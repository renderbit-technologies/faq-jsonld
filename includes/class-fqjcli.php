<?php
/**
 * WP-CLI commands.
 *
 * @package FAQ_JSON_LD
 */

namespace FQJ;

use WP_CLI;

// phpcs:disable PSR1.Files.SideEffects

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * WP-CLI commands for FAQ plugin
	 */
	class FqjCli {

		/**
		 * Purge all FAQ transients.
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function purge_transients( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			global $wpdb;
			WP_CLI::log( 'Searching transients...' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '%_transient_fqj_faq_json_%' ) );
			$count = 0;
			if ( $rows ) {
				foreach ( $rows as $opt ) {
					$key = preg_replace( '/^_transient_|^_transient_timeout_/', '', $opt );
					if ( delete_transient( $key ) ) {
						++$count;
					}
				}
			}
			WP_CLI::success( "Purged {$count} faq transients." );
		}

		/**
		 * Process invalidation queue via CLI.
		 * Usage: wp fqj process-queue --limit=1000
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function process_queue( $args, $assoc_args ) {
			$limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : null;
			WP_CLI::log( 'Processing invalidate queue...' );
			$processed = fqj_process_invalidation_queue_now( $limit );
			WP_CLI::success( "Processed {$processed} invalidation items." );
		}

		/**
		 * Show queue length
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function queue_info( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$len = fqj_queue_length();
			WP_CLI::success( "Queue length: {$len}" );
		}
	}

	WP_CLI::add_command( 'fqj', 'FQJ\FqjCli' );
}
