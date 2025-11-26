<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Build and print JSON-LD for current post (uses mapping table to fetch relevant FAQ IDs)
 */
function fqj_maybe_print_faq_jsonld() {
    if ( is_admin() ) return;

    global $post;
    if ( ! $post ) return;
    $current_id = intval( $post->ID );

    $opts = get_option( FQJ_OPTION_KEY );
    $cache_ttl = isset( $opts['cache_ttl'] ) ? intval( $opts['cache_ttl'] ) : 12 * HOUR_IN_SECONDS;
    $output_type = isset( $opts['output_type'] ) ? $opts['output_type'] : 'faqsection';

    $transient_key = 'fqj_faq_json_' . $current_id;
    $cached = get_transient( $transient_key );
    if ( $cached !== false ) {
        echo "\n<!-- FAQ JSON-LD (cached) -->\n" . $cached . "\n";
        return;
    }

    // Determine candidate mapping values for lookup
    $candidate_values = array();

    // direct post id mapping
    $candidate_values[] = array( 'type' => 'post', 'value' => (string) $current_id );

    // current post type mapping
    $pt = get_post_type( $current_id );
    if ( $pt ) $candidate_values[] = array( 'type' => 'post_type', 'value' => $pt );

    // terms
    $terms = wp_get_post_terms( $current_id );
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        foreach ( $terms as $t ) {
            $candidate_values[] = array( 'type' => 'term', 'value' => (string) $t->term_id );
        }
    }

    // URL mapping - try canonical/permalink
    $permalink = get_permalink( $current_id );
    if ( $permalink ) {
        $candidate_values[] = array( 'type' => 'url', 'value' => rtrim( strtok( $permalink, '?' ), '/' ) );
    }

    // Always include global mapping
    $candidate_values[] = array( 'type' => 'global', 'value' => '1' );

    // Query mapping table for matching faq_ids
    global $wpdb;
    $table = FQJ_DB_TABLE;

    // Build WHERE clauses for each candidate
    $where_clauses = array();
    $params = array();
    foreach ( $candidate_values as $c ) {
        // mapping_type = ? AND mapping_value LIKE ?
        $where_clauses[] = "(mapping_type = %s AND mapping_value LIKE %s)";
        $params[] = $c['type'];
        // we match serialized values, so use LIKE %value% (safe because our simple values are stored raw or serialized)
        $params[] = '%' . $wpdb->esc_like( (string) $c['value'] ) . '%';
    }

    if ( empty( $where_clauses ) ) return;

    $where_sql = implode( ' OR ', $where_clauses );

    $sql = $wpdb->prepare( "SELECT DISTINCT faq_id FROM {$table} WHERE {$where_sql}", $params );

    $faq_ids = $wpdb->get_col( $sql );
    if ( empty( $faq_ids ) ) {
        // cache negative result to avoid repeated queries
        set_transient( $transient_key, '', $cache_ttl );
        return;
    }

    // Now fetch the FAQ posts
    $args = array(
        'post_type' => 'faq_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'post__in' => $faq_ids,
        'orderby' => 'post__in',
    );
    $faqs = get_posts( $args );
    if ( ! $faqs ) {
        set_transient( $transient_key, '', $cache_ttl );
        return;
    }

    $main_entities = array();
    foreach ( $faqs as $f ) {
        $q = get_the_title( $f );
        $a = wp_strip_all_tags( apply_filters( 'the_content', $f->post_content ) );
        if ( empty( $q ) || empty( $a ) ) continue;

        $main_entities[] = array(
            '@type' => 'Question',
            'name' => $q,
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text' => $a,
            ),
        );
    }

    if ( empty( $main_entities ) ) {
        set_transient( $transient_key, '', $cache_ttl );
        return;
    }

    $json_ld = array(
        '@context' => 'https://schema.org',
        '@type' => ( $output_type === 'faqpage' ? 'FAQPage' : 'FAQSection' ),
        'mainEntity' => $main_entities,
    );

    $script = '<script type="application/ld+json">' . wp_json_encode( $json_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>';

    echo "\n<!-- FAQ JSON-LD injected by FAQ JSON-LD Manager plugin (generated) -->\n";
    echo $script . "\n";

    set_transient( $transient_key, $script, $cache_ttl );
}
add_action( 'wp_head', 'fqj_maybe_print_faq_jsonld', 1 );
