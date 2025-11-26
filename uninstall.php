<?php

/**
 * Uninstall script for FAQ JSON-LD Manager (Enterprise)
 * WARNING: This will remove plugin options and the mapping table permanently.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete options
delete_option('fqj_settings');

// Drop table
$table = $wpdb->prefix.'fqj_mappings';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

// Purge transients
$rows = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '%_transient_fqj_faq_json_%'));
if ($rows) {
    foreach ($rows as $opt) {
        $key = preg_replace('/^_transient_|^_transient_timeout_/', '', $opt);
        delete_transient($key);
    }
}
