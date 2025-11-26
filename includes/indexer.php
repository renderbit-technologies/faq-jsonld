<?php

if (! defined('ABSPATH')) {
    exit;
}

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

    require_once ABSPATH.'wp-admin/includes/upgrade.php';
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
    $assoc_type = get_post_meta($faq_id, 'fqj_assoc_type', true) ?: 'urls';
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

    // Invalidate affected posts' transients
    fqj_invalidate_transients_for_mappings($mappings);
}

/**
 * Delete transients for posts affected by mapping rows.
 * For 'post' rows: delete that post transient
 * For 'post_type' rows: fetch posts in batches and delete transients
 * For 'term' rows: fetch posts having the term and delete in batches
 * For 'url' rows: try url_to_postid and delete that post transient if found
 * For 'global' rows: purge all transients (conservative)
 */
function fqj_invalidate_transients_for_mappings($mappings)
{
    // read settings
    $opts = get_option(FQJ_OPTION_KEY);
    $batch_size = isset($opts['batch_size']) ? intval($opts['batch_size']) : 500;

    $post_ids_to_delete = [];
    $post_types_to_process = [];
    $terms_to_process = [];
    $must_purge_all = false;

    foreach ($mappings as $m) {
        switch ($m['type']) {
            case 'post':
                $post_ids_to_delete[] = intval($m['value']);
                break;
            case 'url':
                $pid = url_to_postid($m['value']);
                if ($pid) {
                    $post_ids_to_delete[] = intval($pid);
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

    // delete direct post transients
    foreach ($post_ids_to_delete as $pid) {
        delete_transient('fqj_faq_json_'.$pid);
    }

    // process post_types in batches
    if (! empty($post_types_to_process)) {
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
                foreach ($q->posts as $id) {
                    delete_transient('fqj_faq_json_'.$id);
                }
                wp_reset_postdata();
                if (count($q->posts) < $batch_size) {
                    break;
                }
                $paged++;
            }
        }
    }

    // process terms in batches
    if (! empty($terms_to_process)) {
        foreach ($terms_to_process as $term_id) {
            $args = [
                'post_type' => 'any',
                'posts_per_page' => $batch_size,
                'tax_query' => [
                    [
                        'taxonomy' => get_term($term_id)->taxonomy,
                        'terms' => $term_id,
                        'field' => 'term_id',
                    ],
                ],
                'fields' => 'ids',
                'paged' => 1,
            ];
            $paged = 1;
            while (true) {
                $args['paged'] = $paged;
                $q = new WP_Query($args);
                if (! $q->have_posts()) {
                    break;
                }
                foreach ($q->posts as $id) {
                    delete_transient('fqj_faq_json_'.$id);
                }
                wp_reset_postdata();
                if (count($q->posts) < $batch_size) {
                    break;
                }
                $paged++;
            }
        }
    }

    // global: purge all transients selectively
    if ($must_purge_all) {
        fqj_purge_all_faq_transients();
    }
}

/**
 * Hook: on faq_item save, rebuild index
 * Note: ensure we don't loop excessively — only run for faq_item type
 */
function fqj_on_faq_save_reindex($post_id, $post, $update)
{
    if ($post->post_type !== 'faq_item') {
        return;
    }

    // Rebuild index for this FAQ
    fqj_rebuild_index_for_faq($post_id);

    // Also delete the faq_item specific transient (if any)
    delete_transient('fqj_faq_item_'.$post_id);
}
add_action('save_post', 'fqj_on_faq_save_reindex', 20, 3);

/**
 * When a regular post is updated, delete its cached transient so it will be rebuilt
 */
function fqj_on_content_save_invalidate($post_id, $post, $update)
{
    if ($post->post_type === 'faq_item') {
        return;
    }
    delete_transient('fqj_faq_json_'.$post_id);
}
add_action('save_post', 'fqj_on_content_save_invalidate', 30, 3);
