<?php
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Settings page: TTL, batch size, output_type
 */
function fqj_register_settings_page()
{
    add_submenu_page(
        'edit.php?post_type=faq_item',
        'FAQ JSON-LD Settings',
        'Settings',
        'manage_options',
        'fqj-settings',
        'fqj_render_settings_page'
    );
}
add_action('admin_menu', 'fqj_register_settings_page');

function fqj_render_settings_page()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $options = get_option(FQJ_OPTION_KEY);
    if (! $options) {
        $options = [];
    }

    // Handle save
    if (
        isset($_POST['fqj_settings_nonce'])
        && wp_verify_nonce($_POST['fqj_settings_nonce'], 'fqj_save_settings')
    ) {
        $cache_ttl = intval($_POST['cache_ttl']);
        $batch_size = intval($_POST['batch_size']);
        $output_type = in_array($_POST['output_type'], ['faqsection', 'faqpage'])
            ? $_POST['output_type']
            : 'faqsection';

        $options['cache_ttl'] = max(60, $cache_ttl);
        $options['batch_size'] = max(10, $batch_size);
        $options['output_type'] = $output_type;
        update_option(FQJ_OPTION_KEY, $options);

        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $cache_ttl = isset($options['cache_ttl']) ? intval($options['cache_ttl']) : 12 * HOUR_IN_SECONDS;
    $batch_size = isset($options['batch_size']) ? intval($options['batch_size']) : 500;
    $output_type = isset($options['output_type']) ? $options['output_type'] : 'faqsection';
    ?>
    <div class="wrap">
        <h1>FAQ JSON-LD Settings</h1>
        <form method="post">
            <?php wp_nonce_field('fqj_save_settings', 'fqj_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="cache_ttl">Cache TTL (seconds)</label></th>
                    <td><input name="cache_ttl" id="cache_ttl" type="number"
                        value="<?php echo esc_attr($cache_ttl); ?>" class="regular-text" />
                        <p class="description">
                        Number of seconds to cache per-post JSON-LD. Default is 43200 (12 hours).
                        </p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="batch_size">Invalidation batch size</label></th>
                    <td><input name="batch_size" id="batch_size" type="number"
                        value="<?php echo esc_attr($batch_size); ?>" class="regular-text" />
                        <p class="description">
                        When invalidating many posts (e.g., post-type mapping), process posts in batches
                        of this size to avoid timeouts.
                        </p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="output_type">Default output type</label></th>
                    <td>
                        <select name="output_type" id="output_type">
                            <option value="faqsection" <?php selected($output_type, 'faqsection'); ?>>
                                FAQSection (recommended)
                            </option>
                            <option value="faqpage" <?php selected($output_type, 'faqpage'); ?>>
                                FAQPage
                            </option>
                        </select>
                        <p class="description">
                        Choose whether the plugin outputs <code>FAQSection</code> or <code>FAQPage</code> by default.
                        Individual FAQs still control associations.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h2>Tools</h2>
        <p>
            <strong>Purge all FAQ transients:</strong>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('fqj_purge_all_nonce', 'fqj_purge_all_nonce_field'); ?>
                <input type="hidden" name="fqj_action" value="purge_all" />
                <?php submit_button('Purge transients', 'secondary', 'submit', false); ?>
            </form>
        </p>

        <?php
        if (
            isset($_POST['fqj_action'])
            && $_POST['fqj_action'] === 'purge_all'
            && isset($_POST['fqj_purge_all_nonce_field'])
            && wp_verify_nonce($_POST['fqj_purge_all_nonce_field'], 'fqj_purge_all_nonce')
        ) {
            fqj_purge_all_faq_transients();
            echo '<div class="updated"><p>All FAQ transients purged.</p></div>';
        }
        ?>

    </div>
    <?php
}

/**
 * Helper: purge all faq transients (used by settings and WP-CLI)
 */
function fqj_purge_all_faq_transients()
{
    global $wpdb;
    $like = '%fqj_faq_json_%';
    $sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s";
    $rows = $wpdb->get_col($wpdb->prepare($sql, '%_transient_fqj_faq_json_%'));
    if ($rows) {
        foreach ($rows as $opt) {
            // option_name may be _transient_fqj_faq_json_{id} or _transient_timeout_fqj_faq_json_{id}
            $key = preg_replace('/^_transient_|^_transient_timeout_/', '', $opt);
            delete_transient($key);
        }
    }
}
