<?php
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Health & Diagnostics admin page for FAQ JSON-LD plugin.
 * Shows:
 *  - Queue length
 *  - Last run timestamp
 *  - Recent invalidation history
 *  - Buttons to process queue now, purge transients, clear log
 */

/**
 * Register submenu under FAQ Items
 */
function fqj_register_health_page()
{
    add_submenu_page(
        'edit.php?post_type=faq_item',
        'FAQ JSON-LD Health',
        'Health',
        'manage_options',
        'fqj-health',
        'fqj_render_health_page'
    );
}
add_action('admin_menu', 'fqj_register_health_page');

/**
 * Enqueue health JS only on our page
 */
function fqj_health_admin_assets($hook)
{
    // check page hook: load only on fqj-health page
    if ($hook !== 'faq_item_page_fqj-health') {
        return;
    }

    wp_enqueue_script(
        'fqj-health-js',
        FQJ_PLUGIN_URL . 'assets/js/fqj-health.js',
        ['jquery'],
        '1.0',
        true
    );
    wp_localize_script('fqj-health-js', 'fqjHealth', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fqj_health_nonce'),
    ]);
}
add_action('admin_enqueue_scripts', 'fqj_health_admin_assets');

/**
 * Render health page
 */
function fqj_render_health_page()
{
    if (! current_user_can('manage_options')) {
        return;
    }
    $queue_len = fqj_queue_length();
    $last_run_ts = get_option('fqj_last_queue_run', 0);
    $log = get_option('fqj_invalidation_log', []);
    if (! is_array($log)) {
        $log = [];
    }

    ?>
    <div class="wrap">
        <h1>FAQ JSON-LD — Health & Diagnostics</h1>

        <h2>Queue</h2>
        <p>Pending invalidation items in queue:
            <strong id="fqj-queue-count"><?php echo intval($queue_len); ?></strong>
        </p>
        <p>Last queue run: <strong id="fqj-last-run">
        <?php
        echo $last_run_ts
            ? esc_html(
                date_i18n('Y-m-d H:i:s', $last_run_ts) .
                ' (' . human_time_diff($last_run_ts, time()) . ' ago)'
            )
            : 'Never';
        ?>
        </strong></p>

        <p>
            <button id="fqj-process-now" class="button button-primary">Process queue now</button>
            <button id="fqj-purge-transients" class="button">Purge all FAQ transients</button>
            <button id="fqj-clear-log" class="button">Clear invalidation log</button>
            <span id="fqj-action-status" style="margin-left:12px;"></span>
        </p>

        <h2>Recent invalidation history</h2>
        <p>Shows up to the last <?php echo esc_html(count($log) ? count($log) : 0); ?> runs (newest first).</p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th width="170">Timestamp</th>
                    <th>Processed</th>
                    <th>Sample post IDs</th>
                </tr>
            </thead>
            <tbody id="fqj-log-body">
                <?php
                if (empty($log)) {
                    echo '<tr><td colspan="3">No invalidation runs logged yet.</td></tr>';
                } else {
                    foreach ($log as $entry) {
                        $ts = isset($entry['ts']) ? intval($entry['ts']) : 0;
                        $processed = isset($entry['processed']) ? intval($entry['processed']) : 0;
                        $sample = isset($entry['sample']) && is_array($entry['sample']) ? $entry['sample'] : [];
                        echo '<tr>';
                        echo '<td>' . esc_html(
                            $ts
                                ? date_i18n('Y-m-d H:i:s', $ts) . ' (' . human_time_diff($ts, time()) . ' ago)'
                                : 'n/a'
                        ) . '</td>';
                        echo '<td>' . esc_html($processed) . '</td>';
                        echo '<td>' . esc_html(implode(', ', $sample)) . '</td>';
                        echo '</tr>';
                    }
                }
                ?>
            </tbody>
        </table>

        <h2>Notes</h2>
        <ul>
            <li>
                Queue is stored transiently; if you need persistent audit trail,
                consider enabling a custom queue table (we can add that).
            </li>
            <li>
                If WP-Cron is disabled on your host, ensure real cron triggers
                <code>wp-cron.php</code> or use WP-CLI to process the queue manually.
            </li>
        </ul>
    </div>
    <?php
}

/**
 * AJAX: process queue now (admin)
 */
function fqj_ajax_process_queue_now()
{
    check_ajax_referer('fqj_health_nonce', 'nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }

    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : null;
    $processed = fqj_process_invalidation_queue_now($limit);
    $queue_len = fqj_queue_length();
    $last_run = get_option('fqj_last_queue_run', 0);

    wp_send_json_success([
        'processed' => $processed,
        'queue_len' => $queue_len,
        'last_run' => $last_run,
    ]);
}
add_action('wp_ajax_fqj_process_queue_now', 'fqj_ajax_process_queue_now');

/**
 * AJAX: purge all FAQ transients (admin)
 */
function fqj_ajax_purge_transients()
{
    check_ajax_referer('fqj_health_nonce', 'nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    fqj_purge_all_faq_transients();
    wp_send_json_success(['message' => 'Purged']);
}
add_action('wp_ajax_fqj_purge_transients', 'fqj_ajax_purge_transients');

/**
 * AJAX: clear invalidation log
 */
function fqj_ajax_clear_invalidation_log()
{
    check_ajax_referer('fqj_health_nonce', 'nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    update_option('fqj_invalidation_log', []);
    wp_send_json_success(['message' => 'Cleared']);
}
add_action('wp_ajax_fqj_clear_invalidation_log', 'fqj_ajax_clear_invalidation_log');
