<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    /**
     * WP-CLI command: wp fqj purge-transients
     */
    class FQJ_CLI {
        public function purge_transients( $args, $assoc_args ) {
            global $wpdb;
            WP_CLI::log( 'Searching transients...' );
            $rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '%_transient_fqj_faq_json_%' ) );
            $count = 0;
            if ( $rows ) {
                foreach ( $rows as $opt ) {
                    $key = preg_replace( '/^_transient_|^_transient_timeout_/', '', $opt );
                    if ( delete_transient( $key ) ) $count++;
                }
            }
            WP_CLI::success( "Purged {$count} faq transients." );
        }
    }

    WP_CLI::add_command( 'fqj', 'FQJ_CLI' );
}
