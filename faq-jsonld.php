<?php

/**
 * Plugin Name: FAQ JSON-LD Manager (Enterprise)
 * Plugin URI:  https://example.com
 * Description: Manage FAQ items as CPT and inject FAQ JSON-LD. Uses a custom mapping table (fast), per-post transient caching, settings UI, batched invalidation and WP-CLI tools.
 * Version:     2.0.0
 * Author:      Renderbit / Soham
 * License:     GPLv2+
 *
 * NOTE: original source content (optional import reference): /mnt/data/FAQs section content.docx
 */
if (! defined('ABSPATH')) {
    exit;
}

define('FQJ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FQJ_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FQJ_DB_TABLE', $GLOBALS['wpdb']->prefix.'fqj_mappings');
define('FQJ_OPTION_KEY', 'fqj_settings');

/**
 * Autoload includes
 */
require_once FQJ_PLUGIN_DIR.'includes/settings.php';
require_once FQJ_PLUGIN_DIR.'includes/indexer.php';
require_once FQJ_PLUGIN_DIR.'includes/admin.php';
require_once FQJ_PLUGIN_DIR.'includes/frontend.php';
require_once FQJ_PLUGIN_DIR.'includes/wpcli.php';

/**
 * Register CPT
 */
function fqj_register_cpt_faq_item()
{
    $labels = [
        'name' => 'FAQ Items',
        'singular_name' => 'FAQ Item',
        'add_new_item' => 'Add FAQ Item',
        'edit_item' => 'Edit FAQ Item',
        'new_item' => 'New FAQ Item',
        'view_item' => 'View FAQ Item',
        'search_items' => 'Search FAQ Items',
        'not_found' => 'No FAQ items found',
        'all_items' => 'All FAQ Items',
    ];
    $args = [
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'capability_type' => 'post',
        'hierarchical' => false,
        'supports' => ['title', 'editor'],
        'menu_position' => 25,
        'menu_icon' => 'dashicons-editor-help',
        'has_archive' => false,
    ];
    register_post_type('faq_item', $args);
}
add_action('init', 'fqj_register_cpt_faq_item');

/**
 * Activation: create DB table and set defaults
 */
function fqj_activate()
{
    fqj_create_table();

    $defaults = [
        'cache_ttl' => 12 * HOUR_IN_SECONDS,
        'batch_size' => 500,            // default batch size for invalidation
        'output_type' => 'faqsection',   // 'faqsection' or 'faqpage'
    ];
    if (! get_option(FQJ_OPTION_KEY)) {
        add_option(FQJ_OPTION_KEY, $defaults);
    } else {
        // ensure keys exist
        $opts = get_option(FQJ_OPTION_KEY);
        $opts = wp_parse_args($opts, $defaults);
        update_option(FQJ_OPTION_KEY, $opts);
    }
}
register_activation_hook(__FILE__, 'fqj_activate');

/**
 * Deactivation: currently no destructive changes; transients left alone for safety
 */
function fqj_deactivate()
{
    // nothing destructive by default
}
register_deactivation_hook(__FILE__, 'fqj_deactivate');

/**
 * Uninstall: optional cleanup handled by uninstall.php (if present)
 */
if (file_exists(FQJ_PLUGIN_DIR.'uninstall.php')) {
    // uninstall handled via uninstall.php
}
