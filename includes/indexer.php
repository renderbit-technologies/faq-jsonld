<?php

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Create mapping table
 */
function fqj_create_table()
{
    global $wpdb;
    $table_name = FQJ_DB_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      faq_id BIGINT UNSIGNED NOT NULL,
      mapping_type VARCHAR(32) NOT NULL,  -- 'post','post_type','term','url','global'
      mapping_value TEXT NOT NULL,
      PRIMARY KEY  (id),
      INDEX idx_faq_id (faq_id),
      INDEX idx_mapping_type (mapping_type),
      INDEX idx_mapping_value (mapping_value(191))
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Insert mapping rows for a given faq_id: accepts $mappings array with structure:
 * array( array('type' => 'post', 'value' => '123'), array('type'=>'url', 'value'=>'https://...'), ... )
 */
function fqj_insert_mappings($faq_id, $mappings)
{
    global $wpdb;
    $table = FQJ_DB_TABLE;
    foreach ($mappings as $m) {
        $wpdb->insert($table, [
            'faq_id' => intval($faq_id),
            'mapping_type' => sanitize_text_field($m['type']),
            'mapping_value' => maybe_serialize($m['value']),
        ], ['%d', '%s', '%s']);
    }
}

/**
 * Delete mappings for faq_id (used to rebuild on save)
 */
function fqj_delete_mappings_for_faq($faq_id)
{
    global $wpdb;
    $table = FQJ_DB_TABLE;
    $wpdb->delete($table, ['faq_id' => intval($faq_id)], ['%d']);
}

/**
 * Build mapping rows from saved payload and index them
 * payload is array with keys 'urls', 'posts', 'post_types', 'terms', 'global'
 */
function fqj_rebuild_index_for_faq($faq_id)
{
    // delete old
    fqj_delete_mappings_for_faq($faq_id);

    $payload_json = get_post_meta($faq_id, 'fqj_assoc_data_json', true);
    $payload = $payload_json ? json_decode($payload_json, true) : [];

    $mappings = [];

    if (isset($payload['urls']) && is_array($payload['urls'])) {
        foreach ($payload['urls'] as $u) {
            $mappings[] = ['type' => 'url', 'value' => $u];
            // also map internal URLs to post IDs for faster lookup
            $pid = url_to_postid($u);
            if ($pid) {
                $mappings[] = ['type' => 'post', 'value' => intval($pid)];
            }
        }
    }

    if (isset($payload['posts']) && is_array($payload['posts'])) {
        foreach ($payload['posts'] as $p) {
            $mappings[] = ['type' => 'post', 'value' => intval($p)];
        }
    }

    if (isset($payload['post_types']) && is_array($payload['post_types'])) {
        foreach ($payload['post_types'] as $pt) {
            $mappings[] = ['type' => 'post_type', 'value' => sanitize_text_field($pt)];
        }
    }

    if (isset($payload['terms']) && is_array($payload['terms'])) {
        foreach ($payload['terms'] as $t) {
            $mappings[] = ['type' => 'term', 'value' => intval($t)];
        }
    }

    if (isset($payload['global']) && $payload['global']) {
        $mappings[] = ['type' => 'global', 'value' => '1'];
    }

    if (! empty($mappings)) {
        fqj_insert_mappings($faq_id, $mappings);
    }

    // Enqueue affected posts for background invalidation
    fqj_enqueue_invalidation_for_mappings($mappings);
}

/**
 * Resolve mappings to post IDs and enqueue them for invalidation using the background queue.
 * This function does not synchronously delete transients; it pushes affected IDs to the queue.
 */
function fqj_enqueue_invalidation_for_mappings($mappings)
{
    if (empty($mappings)) {
        return;
    }

    $post_ids_to_enqueue = [];
    $post_types_to_process = [];
    $terms_to_process = [];
    $must_purge_all = false;

    foreach ($mappings as $m) {
        switch ($m['type']) {
            case 'post':
                $post_ids_to_enqueue[] = intval($m['value']);
                break;
            case 'url':
                $pid = url_to_postid($m['value']);
                if ($pid) {
                    $post_ids_to_enqueue[] = intval($pid);
                }
                break;
            case 'post_type':
                $post_types_to_process[] = sanitize_text_field($m['value']);
                break;
            case 'term':
                $terms_to_process[] = intval($m['value']);
                break;
            case 'global':
                $must_purge_all = true;
                break;
        }
    }

    // Enqueue direct post IDs
    if (! empty($post_ids_to_enqueue)) {
        fqj_queue_add_posts($post_ids_to_enqueue);
    }

    // Enqueue posts of post types (in batches, gather ids)
    if (! empty($post_types_to_process)) {
        $opts = get_option(FQJ_OPTION_KEY);
        $batch_size = isset($opts['batch_size']) ? intval($opts['batch_size']) : 500;

        foreach ($post_types_to_process as $pt) {
            $paged = 1;
            while (true) {
                $args = [
                    'post_type' => $pt,
                    'post_status' => 'any',
                    'posts_per_page' => $batch_size,
                    'paged' => $paged,
                    'fields' => 'ids',
                ];
                $q = new WP_Query($args);
                if (! $q->have_posts()) {
                    break;
                }
                fqj_queue_add_posts($q->posts);
                wp_reset_postdata();
                if (count($q->posts) < $batch_size) {
                    break;
                }
                $paged++;
            }
        }
    }

    // Enqueue posts tagged with terms
    if (! empty($terms_to_process)) {
        $opts = get_option(FQJ_OPTION_KEY);
        $batch_size = isset($opts['batch_size']) ? intval($opts['batch_size']) : 500;

        foreach ($terms_to_process as $term_id) {
            $term = get_term($term_id);
            if (! $term || is_wp_error($term)) {
                continue;
            }

            $paged = 1;
            while (true) {
                $args = [
                    'post_type' => 'any',
                    'posts_per_page' => $batch_size,
                    'paged' => $paged,
                    'fields' => 'ids',
                    'tax_query' => [
                        [
                            'taxonomy' => $term->taxonomy,
                            'terms' => $term_id,
                            'field' => 'term_id',
                        ],
                    ],
                ];
                $q = new WP_Query($args);
                if (! $q->have_posts()) {
                    break;
                }
                fqj_queue_add_posts($q->posts);
                wp_reset_postdata();
                if (count($q->posts) < $batch_size) {
                    break;
                }
                $paged++;
            }
        }
    }

    // For global, do a full purge (we can't reasonably enqueue all posts efficiently here)
    if ($must_purge_all) {
        fqj_purge_all_faq_transients();
    }
}
