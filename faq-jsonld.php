<?php
/**
 * Plugin Name: FAQ JSON-LD Manager (Advanced association UI)
 * Description: Create FAQ items (CPT) and automatically inject FAQSection JSON-LD for pages using flexible association rules and an AJAX post-search multi-select UI.
 * Version: 1.1
 * Author: Renderbit / Soham
 * License: GPLv2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register CPT: faq_item
 */
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
        'supports'           => array( 'title', 'editor' ), // title = question, editor = answer
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-editor-help',
        'has_archive'        => false,
    );
    register_post_type( 'faq_item', $args );
}
add_action( 'init', 'fqj_register_cpt_faq_item' );

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

/**
 * Add meta box to manage flexible association rules
 */
function fqj_add_meta_boxes() {
    add_meta_box(
        'fqj_assoc_rules',
        'Associated FAQs / Rules',
        'fqj_assoc_rules_meta_box_cb',
        'faq_item',
        'normal',
        'default'
    );
}
add_action( 'add_meta_boxes', 'fqj_add_meta_boxes' );

function fqj_assoc_rules_meta_box_cb( $post ) {
    wp_nonce_field( 'fqj_save_meta', 'fqj_meta_nonce' );

    // load stored JSON
    $data_json = get_post_meta( $post->ID, 'fqj_assoc_data_json', true );
    $data_json = $data_json ? $data_json : '{}';

    // association type
    $assoc_types = array(
        'urls'       => 'By URLs (one per line)',
        'posts'      => 'By Posts (search & multi-select)',
        'post_types' => 'By Post Types (apply to all posts of selected types)',
        'tax_terms'  => 'By Taxonomy Terms (posts with selected terms)',
        'global'     => 'Global (site-wide)'
    );

    $current_type = get_post_meta( $post->ID, 'fqj_assoc_type', true ) ?: 'urls';

    echo '<p><label for="fqj_assoc_type"><strong>Association Type</strong></label></p>';
    echo '<select id="fqj_assoc_type" name="fqj_assoc_type" style="width:100%;max-width:400px;">';
    foreach ( $assoc_types as $k => $label ) {
        $sel = selected( $current_type, $k, false );
        echo "<option value='" . esc_attr( $k ) . "' {$sel}>" . esc_html( $label ) . "</option>";
    }
    echo '</select>';

    // hidden field contains JSON to preload admin UI
    echo '<input type="hidden" id="fqj_assoc_data" name="fqj_assoc_data" value="' . esc_attr( $data_json ) . '">';

    echo '<div id="fqj_assoc_container" style="margin-top:12px;"></div>';

    echo '<p class="description">Choose how this FAQ should be associated. For "By Posts" and "By Taxonomy Terms" use the search box.</p>';
}

/**
 * Save meta (store association type and JSON payload)
 */
function fqj_save_meta( $post_id, $post ) {
    if ( ! isset( $_POST['fqj_meta_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['fqj_meta_nonce'], 'fqj_save_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( $post->post_type !== 'faq_item' ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $assoc_type = isset( $_POST['fqj_assoc_type'] ) ? sanitize_text_field( $_POST['fqj_assoc_type'] ) : 'urls';
    $payload = array();

    if ( $assoc_type === 'urls' ) {
        $raw = isset( $_POST['fqj_assoc_urls'] ) ? trim( wp_unslash( $_POST['fqj_assoc_urls'] ) ) : '';
        // Normalize lines and store as array of full URLs
        $lines = preg_split( "/\r\n|\n|\r/", $raw );
        $urls = array();
        foreach ( $lines as $l ) {
            $l = trim( $l );
            if ( empty( $l ) ) continue;
            $urls[] = esc_url_raw( $l );
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
    } elseif ( $assoc_type === 'post_types' ) {
        $ptypes = array();
        if ( isset( $_POST['fqj_assoc_post_types'] ) && is_array( $_POST['fqj_assoc_post_types'] ) ) {
            foreach ( $_POST['fqj_assoc_post_types'] as $pt ) {
                $ptypes[] = sanitize_text_field( $pt );
            }
        }
        $payload['post_types'] = array_values( array_unique( $ptypes ) );
    } elseif ( $assoc_type === 'tax_terms' ) {
        $terms = array();
        if ( isset( $_POST['fqj_assoc_terms_select'] ) && is_array( $_POST['fqj_assoc_terms_select'] ) ) {
            foreach ( $_POST['fqj_assoc_terms_select'] as $t ) {
                // format expected: taxonomy:term_id or term_id - but Select2 returns IDs, we'll store as ints
                $terms[] = intval( $t );
            }
        }
        $payload['terms'] = array_values( array_unique( $terms ) );
    } elseif ( $assoc_type === 'global' ) {
        $payload['global'] = true;
    }

    // store type and payload JSON
    update_post_meta( $post_id, 'fqj_assoc_type', $assoc_type );
    update_post_meta( $post_id, 'fqj_assoc_data_json', wp_json_encode( $payload ) );
}
add_action( 'save_post', 'fqj_save_meta', 10, 2 );

/**
 * AJAX: search posts by title (used by select2)
 */
function fqj_ajax_search_posts() {
    check_ajax_referer( 'fqj_admin_nonce', 'nonce' );

    $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $results = array();

    if ( strlen( $q ) < 1 ) {
        wp_send_json( $results );
    }

    $args = array(
        's'                   => $q,
        'post_type'           => array( 'post', 'page' ),
        'posts_per_page'      => 10,
        'post_status'         => 'publish',
    );
    $posts = get_posts( $args );
    foreach ( $posts as $p ) {
        $results[] = array( 'id' => $p->ID, 'text' => get_the_title( $p ) . ' — ' . get_permalink( $p ) );
    }
    wp_send_json( $results );
}
add_action( 'wp_ajax_fqj_search_posts', 'fqj_ajax_search_posts' );

/**
 * AJAX: search taxonomy terms (all taxonomies)
 */
function fqj_ajax_search_terms() {
    check_ajax_referer( 'fqj_admin_nonce', 'nonce' );

    $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $results = array();

    if ( strlen( $q ) < 1 ) {
        wp_send_json( $results );
    }

    // search across public taxonomies
    $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
    $found = array();
    foreach ( $taxonomies as $tax ) {
        $terms = get_terms( array(
            'taxonomy'   => $tax,
            'name__like' => $q,
            'number'     => 10,
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) ) continue;
        foreach ( $terms as $t ) {
            $found[] = array( 'id' => $t->term_id, 'text' => $t->name . ' (' . $tax . ')' );
        }
    }
    wp_send_json( $found );
}
add_action( 'wp_ajax_fqj_search_terms', 'fqj_ajax_search_terms' );

/**
 * AJAX: return available public post types (for checkboxes)
 */
function fqj_ajax_get_post_types() {
    check_ajax_referer( 'fqj_admin_nonce', 'nonce' );
    $pts = get_post_types( array( 'public' => true ), 'objects' );
    $out = array();
    foreach ( $pts as $k => $obj ) {
        $out[] = array( 'name' => $k, 'label' => $obj->labels->singular_name );
    }
    wp_send_json( $out );
}
add_action( 'wp_ajax_fqj_get_post_types', 'fqj_ajax_get_post_types' );

/**
 * Frontend: determine whether current post matches a faq_item association rule.
 * We will collect all published faq_item posts and evaluate rules efficiently.
 */
function fqj_maybe_print_faq_jsonld() {
    if ( is_admin() ) return;
    if ( ! is_singular() && ! is_home() && ! is_front_page() ) {
        // we still allow front page and home because some site use them as a singular entry
        // but primary use-case is singular pages/posts
    }

    global $post;
    if ( ! $post ) return;
    $current_id = intval( $post->ID );
    $current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $current_url = strtok( $current_url, '?' ); // remove query

    // Query all published faq_item posts. For medium/large sites you may want to cache results.
    $args = array(
        'post_type'      => 'faq_item',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    );

    $faqs = get_posts( $args );
    if ( empty( $faqs ) ) return;

    $main_entities = array();

    foreach ( $faqs as $f ) {
        $assoc_type = get_post_meta( $f->ID, 'fqj_assoc_type', true ) ?: 'urls';
        $data_json  = get_post_meta( $f->ID, 'fqj_assoc_data_json', true );
        $payload = $data_json ? json_decode( $data_json, true ) : array();

        $include = false;

        // Evaluate rules
        if ( $assoc_type === 'global' ) {
            $include = true;
        } elseif ( $assoc_type === 'urls' && ! empty( $payload['urls'] ) ) {
            foreach ( $payload['urls'] as $u ) {
                // normalize and compare (strip query, trailing slash)
                $u_norm = rtrim( strtok( $u, '?' ), '/' );
                $c_norm = rtrim( strtok( $current_url, '?' ), '/' );
                if ( strtolower( $u_norm ) === strtolower( $c_norm ) ) { $include = true; break; }
            }
        } elseif ( $assoc_type === 'posts' && ! empty( $payload['posts'] ) ) {
            if ( in_array( $current_id, $payload['posts'] ) ) $include = true;
        } elseif ( $assoc_type === 'post_types' && ! empty( $payload['post_types'] ) ) {
            if ( in_array( get_post_type( $current_id ), $payload['post_types'] ) ) $include = true;
        } elseif ( $assoc_type === 'tax_terms' && ! empty( $payload['terms'] ) ) {
            // check if current post has any of the terms
            foreach ( $payload['terms'] as $term_id ) {
                if ( has_term( intval( $term_id ), '', $current_id ) ) { $include = true; break; }
            }
        }

        if ( $include ) {
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
    }

    if ( empty( $main_entities ) ) return;

    $json = array(
        '@context' => 'https://schema.org',
        '@type'    => 'FAQSection',
        'mainEntity' => $main_entities,
    );

    // For performance, consider transient-caching this per-post for a short time.
    echo "\n<!-- FAQ JSON-LD injected by FAQ JSON-LD Manager plugin (advanced) -->\n";
    echo '<script type="application/ld+json">' . wp_json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
}
add_action( 'wp_head', 'fqj_maybe_print_faq_jsonld', 1 );
