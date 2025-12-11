<?php
/**
 * Plugin Name: FAQ JSON-LD Manager (Enterprise, queue-enabled)
 * Plugin URI:  https://github.com/renderbit-technologies/faq-jsonld-wordpress-plugin
 * Description: Manage FAQ items as CPT and inject FAQ JSON-LD. Uses a custom mapping table (fast), per-post
 *              transient caching, background invalidation queue (WP-Cron), settings UI and WP-CLI tools.
 * Version:     2.1.0
 * Author:      Renderbit Technologies
 * License:     GPLv2+
 *
 * @package FAQ_JSON_LD
 *
 * NOTE: original source content (optional import reference): /mnt/data/FAQs section content.docx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

define( 'FQJ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FQJ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FQJ_DB_TABLE', $GLOBALS['wpdb']->prefix . 'fqj_mappings' );
define( 'FQJ_OPTION_KEY', 'fqj_settings' );

/**
 * Autoload includes
 */
require_once FQJ_PLUGIN_DIR . 'includes/settings.php';
require_once FQJ_PLUGIN_DIR . 'includes/indexer.php';
require_once FQJ_PLUGIN_DIR . 'includes/queue.php';
require_once FQJ_PLUGIN_DIR . 'includes/admin.php';
require_once FQJ_PLUGIN_DIR . 'includes/frontend.php';
require_once FQJ_PLUGIN_DIR . 'includes/class-fqjcli.php';
require_once FQJ_PLUGIN_DIR . 'includes/health.php';

/**
 * Register CPT
 */
function fqj_register_cpt_faq_item() {
	$labels = array(
		'name'          => 'FAQ Items',
		'singular_name' => 'FAQ Item',
		'add_new_item'  => 'Add FAQ Item',
		'edit_item'     => 'Edit FAQ Item',
		'new_item'      => 'New FAQ Item',
		'view_item'     => 'View FAQ Item',
		'search_items'  => 'Search FAQ Items',
		'not_found'     => 'No FAQ items found',
		'all_items'     => 'All FAQ Items',
	);
	$args   = array(
		'labels'          => $labels,
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'capability_type' => 'post',
		'hierarchical'    => false,
		'supports'        => array( 'title', 'editor' ),
		'menu_position'   => 25,
		'menu_icon'       => 'dashicons-editor-help',
		'has_archive'     => false,
	);
	register_post_type( 'faq_item', $args );
}
add_action( 'init', 'fqj_register_cpt_faq_item' );

/**
 * Activation: create DB table, defaults, schedule cron
 */
function fqj_activate() {
	fqj_create_table();

	$defaults = array(
		'cache_ttl'           => 12 * HOUR_IN_SECONDS,
		'batch_size'          => 500,
		'output_type'         => 'faqsection',
		'queue_cron_interval' => 'fqj_five_minutes', // Interval name.
	);
	$opts     = get_option( FQJ_OPTION_KEY, array() );
	$opts     = wp_parse_args( $opts, $defaults );
	update_option( FQJ_OPTION_KEY, $opts );

	// schedule cron if not scheduled.
	if ( ! wp_next_scheduled( 'fqj_process_invalidation_queue' ) ) {
		// Use a custom interval 'fqj_five_minutes' registered in queue.php.
		wp_schedule_event( time() + 60, 'fqj_five_minutes', 'fqj_process_invalidation_queue' );
	}
}
register_activation_hook( __FILE__, 'fqj_activate' );

/**
 * Deactivation: clear cron
 */
function fqj_deactivate() {
	$timestamp = wp_next_scheduled( 'fqj_process_invalidation_queue' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'fqj_process_invalidation_queue' );
	}
}
register_deactivation_hook( __FILE__, 'fqj_deactivate' );
