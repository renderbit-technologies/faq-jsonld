<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue admin assets for faq_item edit screen
 */
function fqj_admin_assets($hook)
{
    global $post;
    if (! in_array($hook, ['post.php', 'post-new.php'])) {
        return;
    }
    if (! $post || $post->post_type !== 'faq_item') {
        return;
    }

    wp_enqueue_style('fqj-select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', [], '4.0.13');
    wp_enqueue_script('fqj-select2-js', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', ['jquery'], '4.0.13', true);

    wp_enqueue_script('fqj-admin-js', FQJ_PLUGIN_URL.'assets/js/fqj-admin.js', ['jquery', 'fqj-select2-js'], '1.0', true);
    wp_localize_script('fqj-admin-js', 'fqjAdmin', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fqj_admin_nonce'),
        'post_id' => get_the_ID(),
    ]);
}
add_action('admin_enqueue_scripts', 'fqj_admin_assets');

/**
 * Add association meta box
 */
function fqj_add_meta_boxes()
{
    add_meta_box(
        'fqj_assoc_rules',
        'Associations & Output',
        'fqj_assoc_rules_meta_box_cb',
        'faq_item',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'fqj_add_meta_boxes');

function fqj_assoc_rules_meta_box_cb($post)
{
    wp_nonce_field('fqj_save_meta', 'fqj_meta_nonce');

    // Load stored JSON payload for this FAQ
    $data_json = get_post_meta($post->ID, 'fqj_assoc_data_json', true) ?: '{}';
    $assoc_type = get_post_meta($post->ID, 'fqj_assoc_type', true) ?: 'urls';

    $assoc_types = [
        'urls' => 'By URLs (one per line)',
        'posts' => 'By Posts (search & multi-select)',
        'post_types' => 'By Post Types (apply to all posts of selected types)',
        'tax_terms' => 'By Taxonomy Terms (search & multi-select)',
        'global' => 'Global (site-wide)',
    ];

    echo '<p><label for="fqj_assoc_type"><strong>Association Type</strong></label></p>';
    echo '<select id="fqj_assoc_type" name="fqj_assoc_type" style="width:100%;max-width:400px;">';
    foreach ($assoc_types as $k => $label) {
        $sel = selected($assoc_type, $k, false);
        echo "<option value='".esc_attr($k)."' {$sel}>".esc_html($label).'</option>';
    }
    echo '</select>';

    echo '<input type="hidden" id="fqj_assoc_data" name="fqj_assoc_data" value="'.esc_attr($data_json).'">';

    echo '<div id="fqj_assoc_container" style="margin-top:12px;"></div>';

    echo '<p class="description">Enter association details. Use the "By Posts" option to search and multi-select posts/pages. For large sites, prefer Post Types or Taxonomy Terms.</p>';
}

/**
 * Save meta handler (similar to previous implementation but store payload JSON for indexing)
 */
function fqj_save_meta_handler($post_id, $post)
{
    if (! isset($_POST['fqj_meta_nonce'])) {
        return;
    }
    if (! wp_verify_nonce($_POST['fqj_meta_nonce'], 'fqj_save_meta')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if ($post->post_type !== 'faq_item') {
        return;
    }
    if (! current_user_can('edit_post', $post_id)) {
        return;
    }

    $assoc_type = isset($_POST['fqj_assoc_type']) ? sanitize_text_field($_POST['fqj_assoc_type']) : 'urls';
    $payload = [];

    if ($assoc_type === 'urls') {
        $raw = isset($_POST['fqj_assoc_urls']) ? trim(wp_unslash($_POST['fqj_assoc_urls'])) : '';
        $lines = preg_split("/\r\n|\n|\r/", $raw);
        $urls = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if (empty($l)) {
                continue;
            }
            $urls[] = esc_url_raw($l);
        }
        $payload['urls'] = array_values(array_unique($urls));
    } elseif ($assoc_type === 'posts') {
        $post_ids = [];
        if (isset($_POST['fqj_assoc_posts_select']) && is_array($_POST['fqj_assoc_posts_select'])) {
            foreach ($_POST['fqj_assoc_posts_select'] as $pid) {
                $post_ids[] = intval($pid);
            }
        }
        $payload['posts'] = array_values(array_unique($post_ids));
    } elseif ($assoc_type === 'post_types') {
        $ptypes = [];
        if (isset($_POST['fqj_assoc_post_types']) && is_array($_POST['fqj_assoc_post_types'])) {
            foreach ($_POST['fqj_assoc_post_types'] as $pt) {
                $ptypes[] = sanitize_text_field($pt);
            }
        }
        $payload['post_types'] = array_values(array_unique($ptypes));
    } elseif ($assoc_type === 'tax_terms') {
        $terms = [];
        if (isset($_POST['fqj_assoc_terms_select']) && is_array($_POST['fqj_assoc_terms_select'])) {
            foreach ($_POST['fqj_assoc_terms_select'] as $t) {
                $terms[] = intval($t);
            }
        }
        $payload['terms'] = array_values(array_unique($terms));
    } elseif ($assoc_type === 'global') {
        $payload['global'] = true;
    }

    update_post_meta($post_id, 'fqj_assoc_type', $assoc_type);
    update_post_meta($post_id, 'fqj_assoc_data_json', wp_json_encode($payload));

    // Rebuild index (indexer will delete and reinsert mappings, then invalidate transients)
    fqj_rebuild_index_for_faq($post_id);
}
add_action('save_post', 'fqj_save_meta_handler', 10, 2);

/**
 * AJAX: search posts for Select2
 */
function fqj_ajax_search_posts()
{
    check_ajax_referer('fqj_admin_nonce', 'nonce');
    $q = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash($_REQUEST['q'])) : '';
    $results = [];
    if (strlen($q) < 1) {
        wp_send_json($results);
    }

    $args = [
        's' => $q,
        'post_type' => ['post', 'page'],
        'posts_per_page' => 20,
        'post_status' => 'publish',
    ];
    $posts = get_posts($args);
    foreach ($posts as $p) {
        $results[] = ['id' => $p->ID, 'text' => get_the_title($p).' – '.get_permalink($p)];
    }
    wp_send_json($results);
}
add_action('wp_ajax_fqj_search_posts', 'fqj_ajax_search_posts');

/**
 * AJAX: search terms
 */
function fqj_ajax_search_terms()
{
    check_ajax_referer('fqj_admin_nonce', 'nonce');
    $q = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash($_REQUEST['q'])) : '';
    $results = [];
    if (strlen($q) < 1) {
        wp_send_json($results);
    }

    $taxonomies = get_taxonomies(['public' => true], 'names');
    foreach ($taxonomies as $tax) {
        $terms = get_terms(['taxonomy' => $tax, 'name__like' => $q, 'number' => 10, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            continue;
        }
        foreach ($terms as $t) {
            $results[] = ['id' => $t->term_id, 'text' => $t->name.' ('.$tax.')'];
        }
    }
    wp_send_json($results);
}
add_action('wp_ajax_fqj_search_terms', 'fqj_ajax_search_terms');

/**
 * AJAX: get public post types
 */
function fqj_ajax_get_post_types()
{
    check_ajax_referer('fqj_admin_nonce', 'nonce');
    $pts = get_post_types(['public' => true], 'objects');
    $out = [];
    foreach ($pts as $k => $obj) {
        $out[] = ['name' => $k, 'label' => $obj->labels->singular_name];
    }
    wp_send_json($out);
}
add_action('wp_ajax_fqj_get_post_types', 'fqj_ajax_get_post_types');
