<?php
/**
 * Plugin Name: FAQ JSON-LD Manager (Advanced association UI + caching & indexed queries)
 * Description: Create FAQ items (CPT) and automatically inject FAQSection JSON-LD for pages using flexible association rules. Adds per-post transient caching and indexed meta so only relevant FAQ items are queried.
 * Version: 1.2
 * Author: Renderbit / Soham
 * License: GPLv2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* -------------------------
 * Constants / Defaults
 * ------------------------- */
define( 'FQJ_TRANSIENT_TTL', 12 * HOUR_IN_SECONDS ); // Cache TTL (adjust as needed)

/* -------------------------
 * CPT registration (unchanged)
 * ------------------------- */
function fqj_register_cpt_faq_item() {
    $labels = array(
        'name'               => 'FAQ Items',
        'singular_name'      => 'FAQ Item',
        'add_new_item'       => 'Add FAQ Item',
        'edit_item'          => 'Edit FAQ Item',
        'new_item'           => 'New FAQ Item',
        'view_item'          => 'View FAQ Item',
        'search_items'       => 'Search FAQ Items',
        'not_found'          => 'No FAQ items found',
        'all_items'          => 'All FAQ Items',
    );
    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'capability_type'    => 'post',
        'hierarchical'       => false,
        'supports'           => array( 'title', 'editor' ),
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-editor-help',
        'has_archive'        => false,
    );
    register_post_type( 'faq_item', $args );
}
add_action( 'init', 'fqj_register_cpt_faq_item' );

/* -------------------------
 * Admin assets & UI file creation (unchanged)
 * ------------------------- */
/**
 * Enqueue admin assets (Select2 + our script)
 */
function fqj_admin_assets( $hook ) {
    global $post;
    if ( ! in_array( $hook, array( 'post-new.php', 'post.php' ) ) ) return;
    if ( ! $post || $post->post_type !== 'faq_item' ) return;

    // Use WP's built-in jQuery and jQuery UI; include Select2 (bundled simple copy or CDN)
    // For portability we enqueue a minimal select2 from CDN. Replace with local asset for production if desired.
    wp_enqueue_style( 'fqj-select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13' );
    wp_enqueue_script( 'fqj-select2-js', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array( 'jquery' ), '4.0.13', true );

    wp_enqueue_script( 'fqj-admin-js', plugin_dir_url( __FILE__ ) . 'fqj-admin.js', array( 'jquery', 'fqj-select2-js' ), '1.0', true );
    wp_localize_script( 'fqj-admin-js', 'fqjAdmin', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fqj_admin_nonce' ),
        'post_id'  => get_the_ID(),
    ) );
}
add_action( 'admin_enqueue_scripts', 'fqj_admin_assets' );

/**
 * Create admin JS file content on-the-fly if missing (so single-file plugin works).
 * This will create 'fqj-admin.js' in plugin dir if not exists.
 */
function fqj_ensure_admin_js() {
    $file = plugin_dir_path( __FILE__ ) . 'fqj-admin.js';
    if ( file_exists( $file ) ) return;

    $js = <<<'JS'
jQuery(document).ready(function($){
    // cache elements
    var $assocType = $('#fqj_assoc_type');
    var $container = $('#fqj_assoc_container');

    function renderField(type, data){
        $container.empty();
        if(type === 'urls'){
            $container.append('<p>Enter one full URL per line (e.g. https://example.com/about/)</p>');
            $container.append('<textarea id="fqj_assoc_urls" name="fqj_assoc_urls" rows="6" style="width:100%;">' + (data.urls || '') + '</textarea>');
        } else if(type === 'posts'){
            $container.append('<p>Search posts/pages and add multiple results.</p>');
            $container.append('<select id="fqj_assoc_posts_select" name="fqj_assoc_posts_select[]" multiple="multiple" style="width:100%"></select>');
            // initialize select2 with ajax
            $('#fqj_assoc_posts_select').select2({
                placeholder: 'Search posts by title...',
                ajax: {
                    url: fqjAdmin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term,
                            action: 'fqj_search_posts',
                            nonce: fqjAdmin.nonce
                        };
                    },
                    processResults: function (data) {
                        return { results: data };
                    },
                    cache: true
                },
                minimumInputLength: 1,
                templateResult: function(item){ if(!item.id) return item.text; return item.text; },
                templateSelection: function(item){ return item.text; }
            });
            // preload selected if any
            if(data.posts && data.posts.length){
                var select = $('#fqj_assoc_posts_select');
                data.posts.forEach(function(p){
                    var option = new Option(p.text, p.id, true, true);
                    select.append(option);
                });
                select.trigger('change');
            }
        } else if(type === 'post_types'){
            $container.append('<p>Select post type(s) to associate this FAQ with.</p>');
            var html = '<div id="fqj_post_types_wrap"></div>';
            $container.append(html);
            // request available post types from server
            $.get(fqjAdmin.ajax_url, { action: 'fqj_get_post_types', nonce: fqjAdmin.nonce }, function(resp){
                if(!resp || !resp.length) return;
                var wrap = $('#fqj_post_types_wrap');
                resp.forEach(function(pt){
                    var checked = (data.post_types && data.post_types.indexOf(pt.name) !== -1) ? 'checked' : '';
                    wrap.append('<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="fqj_assoc_post_types[]" value="'+pt.name+'" '+checked+'> '+pt.label+'</label>');
                });
            }, 'json');
        } else if(type === 'tax_terms'){
            $container.append('<p>Search taxonomy terms and add multiple results.</p>');
            $container.append('<select id="fqj_assoc_terms_select" name="fqj_assoc_terms_select[]" multiple="multiple" style="width:100%"></select>');
            $('#fqj_assoc_terms_select').select2({
                placeholder: 'Search terms by name...',
                ajax: {
                    url: fqjAdmin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term,
                            action: 'fqj_search_terms',
                            nonce: fqjAdmin.nonce
                        };
                    },
                    processResults: function (data) {
                        return { results: data };
                    },
                    cache: true
                },
                minimumInputLength: 1,
                templateResult: function(item){ return item.text; },
                templateSelection: function(item){ return item.text; }
            });
            if(data.terms && data.terms.length){
                var select = $('#fqj_assoc_terms_select');
                data.terms.forEach(function(t){
                    var option = new Option(t.text, t.id, true, true);
                    select.append(option);
                });
                select.trigger('change');
            }
        } else if(type === 'global'){
            $container.append('<p>This FAQ will be included site-wide.</p>');
            $container.append('<input type="hidden" name="fqj_assoc_global" value="1">');
        }
    }

    // on load: read serialized data JSON from hidden field
    var initData = {};
    try {
        var raw = $('#fqj_assoc_data').val();
        if(raw) initData = JSON.parse(raw);
    } catch(e){ initData = {}; }

    // initial render
    renderField( $assocType.val(), initData );

    // change handler
    $assocType.on('change', function(){
        renderField( $(this).val(), {} );
    });

});
JS;

    @file_put_contents( $file, $js );
}
add_action( 'admin_init', 'fqj_ensure_admin_js' );

/* -------------------------
 * Save meta: store association payload AND create index meta for fast querying
 * ------------------------- */
function fqj_save_meta( $post_id, $post ) {
    if ( ! isset( $_POST['fqj_meta_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['fqj_meta_nonce'], 'fqj_save_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( $post->post_type !== 'faq_item' ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $assoc_type = isset( $_POST['fqj_assoc_type'] ) ? sanitize_text_field( $_POST['fqj_assoc_type'] ) : 'urls';
    $payload = array();

    // We'll maintain indexed meta keys:
    // - fqj_assoc_index_post_ids => CSV like ",12,34,"
    // - fqj_assoc_index_post_types => CSV like ",post,page,"
    // - fqj_assoc_index_term_ids => CSV like ",5,9,"
    // - fqj_assoc_index_global => "1" if global
    // - fqj_assoc_data_json => original payload JSON for admin UI
    $index_post_ids = array();
    $index_post_types = array();
    $index_term_ids = array();
    $index_global = 0;

    if ( $assoc_type === 'urls' ) {
        $raw = isset( $_POST['fqj_assoc_urls'] ) ? trim( wp_unslash( $_POST['fqj_assoc_urls'] ) ) : '';
        $lines = preg_split( "/\r\n|\n|\r/", $raw );
        $urls = array();
        foreach ( $lines as $l ) {
            $l = trim( $l );
            if ( empty( $l ) ) continue;
            $urls[] = esc_url_raw( $l );
            // try to map to post ID (if internal)
            $pid = url_to_postid( $l );
            if ( $pid ) $index_post_ids[] = intval( $pid );
        }
        $payload['urls'] = array_values( array_unique( $urls ) );
    } elseif ( $assoc_type === 'posts' ) {
        $post_ids = array();
        if ( isset( $_POST['fqj_assoc_posts_select'] ) && is_array( $_POST['fqj_assoc_posts_select'] ) ) {
            foreach ( $_POST['fqj_assoc_posts_select'] as $pid ) {
                $pid = intval( $pid );
                if ( $pid ) $post_ids[] = $pid;
            }
        }
        $payload['posts'] = array_values( array_unique( $post_ids ) );
        $index_post_ids = $payload['posts'];
    } elseif ( $assoc_type === 'post_types' ) {
        $ptypes = array();
        if ( isset( $_POST['fqj_assoc_post_types'] ) && is_array( $_POST['fqj_assoc_post_types'] ) ) {
            foreach ( $_POST['fqj_assoc_post_types'] as $pt ) {
                $ptypes[] = sanitize_text_field( $pt );
            }
        }
        $payload['post_types'] = array_values( array_unique( $ptypes ) );
        $index_post_types = $payload['post_types'];
    } elseif ( $assoc_type === 'tax_terms' ) {
        $terms = array();
        if ( isset( $_POST['fqj_assoc_terms_select'] ) && is_array( $_POST['fqj_assoc_terms_select'] ) ) {
            foreach ( $_POST['fqj_assoc_terms_select'] as $t ) {
                $terms[] = intval( $t );
            }
        }
        $payload['terms'] = array_values( array_unique( $terms ) );
        $index_term_ids = $payload['terms'];
    } elseif ( $assoc_type === 'global' ) {
        $payload['global'] = true;
        $index_global = 1;
    }

    // store original association data JSON & type
    update_post_meta( $post_id, 'fqj_assoc_type', $assoc_type );
    update_post_meta( $post_id, 'fqj_assoc_data_json', wp_json_encode( $payload ) );

    // store index metas (as CSV with surrounding commas for LIKE queries)
    if ( ! empty( $index_post_ids ) ) {
        $csv = ',' . implode( ',', array_map( 'intval', array_unique( $index_post_ids ) ) ) . ',';
        update_post_meta( $post_id, 'fqj_assoc_index_post_ids', $csv );
    } else {
        delete_post_meta( $post_id, 'fqj_assoc_index_post_ids' );
    }

    if ( ! empty( $index_post_types ) ) {
        $csv = ',' . implode( ',', array_map( 'sanitize_text_field', array_unique( $index_post_types ) ) ) . ',';
        update_post_meta( $post_id, 'fqj_assoc_index_post_types', $csv );
    } else {
        delete_post_meta( $post_id, 'fqj_assoc_index_post_types' );
    }

    if ( ! empty( $index_term_ids ) ) {
        $csv = ',' . implode( ',', array_map( 'intval', array_unique( $index_term_ids ) ) ) . ',';
        update_post_meta( $post_id, 'fqj_assoc_index_term_ids', $csv );
    } else {
        delete_post_meta( $post_id, 'fqj_assoc_index_term_ids' );
    }

    if ( $index_global ) {
        update_post_meta( $post_id, 'fqj_assoc_index_global', '1' );
    } else {
        delete_post_meta( $post_id, 'fqj_assoc_index_global' );
    }

    /**
     * Invalidate transients for affected posts:
     * - direct post IDs in index_post_ids
     * - all posts of post types in index_post_types
     * - posts assigned to terms in index_term_ids
     * If we can't resolve a large set, we at least delete option-wide cache keys (not implemented here).
     */
    $affected_post_ids = array();

    // direct post IDs
    if ( ! empty( $index_post_ids ) ) {
        $affected_post_ids = array_merge( $affected_post_ids, $index_post_ids );
    }

    // posts of specific post types
    if ( ! empty( $index_post_types ) ) {
        $query = array(
            'post_type'      => $index_post_types,
            'post_status'    => 'any',
            'posts_per_page' => 1000, // reasonable upper bound; if site larger, consider WP_Query loop or direct SQL
            'fields'         => 'ids',
        );
        $posts_of_types = get_posts( $query );
        if ( $posts_of_types ) $affected_post_ids = array_merge( $affected_post_ids, $posts_of_types );
    }

    // posts with terms
    if ( ! empty( $index_term_ids ) ) {
        foreach ( $index_term_ids as $term_id ) {
            $posts_with_term = get_objects_in_term( $term_id, false );
            if ( is_array( $posts_with_term ) ) {
                $affected_post_ids = array_merge( $affected_post_ids, $posts_with_term );
            }
        }
    }

    $affected_post_ids = array_filter( array_unique( array_map( 'intval', $affected_post_ids ) ) );

    foreach ( $affected_post_ids as $pid ) {
        delete_transient( 'fqj_faq_json_' . $pid );
    }

    // Also delete the faq_item's own cached transient in case it was used somewhere (rare)
    delete_transient( 'fqj_faq_item_' . $post_id );
}
add_action( 'save_post', 'fqj_save_meta', 10, 2 );


/* -------------------------
 * Invalidate per-post transient when a normal post/page is updated
 * ------------------------- */
function fqj_invalidate_transient_on_post_save( $post_id, $post, $update ) {
    // Only care about public content (avoid deleting when saving faq_item)
    if ( $post->post_type === 'faq_item' ) return;

    // Delete transient for this post (it will be rebuilt on next view)
    delete_transient( 'fqj_faq_json_' . $post_id );

    // Additionally, if post terms changed or post type changed, related FAQs might be affected; for simplicity,
    // delete transient for posts that match same post type (cheap) - optional
    // delete_transient( 'fqj_faq_json_' . $post_id );
}
add_action( 'save_post', 'fqj_invalidate_transient_on_post_save', 20, 3 );

/* -------------------------
 * Frontend: fetch cached JSON-LD or build it by querying indexed faq_item posts
 * ------------------------- */
function fqj_maybe_print_faq_jsonld() {
    if ( is_admin() ) return;

    global $post;
    if ( ! $post ) return;
    $current_id = intval( $post->ID );

    // Try transient first
    $transient_key = 'fqj_faq_json_' . $current_id;
    $cached = get_transient( $transient_key );
    if ( $cached !== false ) {
        // echo directly
        echo "\n<!-- FAQ JSON-LD (cached) -->\n" . $cached . "\n";
        return;
    }

    // Build optimized meta_query to fetch only relevant faq_item posts:
    // Conditions (OR):
    //  - fqj_assoc_index_global = '1'
    //  - fqj_assoc_index_post_ids LIKE ",{current_id},"
    //  - fqj_assoc_index_post_types LIKE ",{post_type},"
    //  - fqj_assoc_index_term_ids LIKE ",{term_id},"
    //
    // We'll build term conditions dynamically for all terms assigned to current post.
    $post_type = get_post_type( $current_id );
    $post_type_like = ',' . $post_type . ',';

    // Build meta_query with OR relation
    $meta_query = array( 'relation' => 'OR' );

    // global
    $meta_query[] = array(
        'key'     => 'fqj_assoc_index_global',
        'value'   => '1',
        'compare' => '=',
    );

    // post id
    $meta_query[] = array(
        'key'     => 'fqj_assoc_index_post_ids',
        'value'   => ',' . $current_id . ',',
        'compare' => 'LIKE',
    );

    // post_type
    $meta_query[] = array(
        'key'     => 'fqj_assoc_index_post_types',
        'value'   => $post_type_like,
        'compare' => 'LIKE',
    );

    // terms
    $terms = wp_get_post_terms( $current_id ); // returns WP_Term objects
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        foreach ( $terms as $t ) {
            $meta_query[] = array(
                'key'     => 'fqj_assoc_index_term_ids',
                'value'   => ',' . intval( $t->term_id ) . ',',
                'compare' => 'LIKE',
            );
        }
    }

    // Final query
    $args = array(
        'post_type'      => 'faq_item',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => $meta_query,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'fields'         => 'all',
    );

    $query = new WP_Query( $args );
    if ( ! $query->have_posts() ) {
        // cache empty result as well to avoid repeated DB calls
        set_transient( $transient_key, '', FQJ_TRANSIENT_TTL );
        return;
    }

    $main_entities = array();

    foreach ( $query->posts as $f ) {
        // As an extra safety, re-evaluate URLs mapping if this faq uses URLs only but was indexed to post IDs earlier.
        // We simply trust the saved index for speed.

        $question = get_the_title( $f );
        $answer  = wp_strip_all_tags( apply_filters( 'the_content', $f->post_content ) );
        if ( empty( $question ) || empty( $answer ) ) continue;

        $main_entities[] = array(
            '@type' => 'Question',
            'name'  => $question,
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => $answer,
            ),
        );
    }

    if ( empty( $main_entities ) ) {
        set_transient( $transient_key, '', FQJ_TRANSIENT_TTL );
        return;
    }

    $json = array(
        '@context' => 'https://schema.org',
        '@type'    => 'FAQSection',
        'mainEntity' => $main_entities,
    );

    $script = '<script type="application/ld+json">' . wp_json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>';

    // Print and cache
    echo "\n<!-- FAQ JSON-LD injected by FAQ JSON-LD Manager plugin (cached) -->\n";
    echo $script . "\n";

    set_transient( $transient_key, $script, FQJ_TRANSIENT_TTL );

    // cleanup
    wp_reset_postdata();
}
add_action( 'wp_head', 'fqj_maybe_print_faq_jsonld', 1 );

/* -------------------------
 * Utility: purge all faq transients (optional helper)
 * ------------------------- */
function fqj_purge_all_faq_transients() {
    global $wpdb;
    // This approach depends on transient naming prefix. We'll search options table for matching transients.
    $like = '_transient_%fqj_faq_json_%';
    // Better to directly query option_name LIKE '%fqj_faq_json_%' but specifics differ by WP version.
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        '%fqj_faq_json_%'
    ) );
    if ( $rows ) {
        foreach ( $rows as $opt ) {
            // option_name may be _transient_key or _transient_timeout_key
            if ( false !== strpos( $opt, '_transient_' ) ) {
                // extract the transient key
                $key = preg_replace( '/^_transient_/', '', $opt );
                $key = preg_replace( '/^_transient_timeout_/', '', $key );
                delete_transient( $key );
            }
        }
    }
}
/* call fqj_purge_all_faq_transients() manually if you need a full purge; not called automatically. */

/* -------------------------
 * Notes:
 * - The plugin now creates index meta on save so frontend queries can use meta_query (fast).
 * - On save, transients for affected posts are deleted; when a post is updated, its own transient is deleted.
 * - For very large sites, further optimization is possible (store mappings in a custom table, incremental updates, or better batching).
 * ------------------------- */
