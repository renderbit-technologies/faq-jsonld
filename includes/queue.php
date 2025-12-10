<?php

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Background invalidation queue using transients + WP-Cron
 * Now includes logging: last run timestamp and an invalidation history log.
 */

/**
 * Register a custom cron interval (5 minutes) if not present.
 */
function fqj_add_cron_interval($schedules)
{
    if (! isset($schedules['fqj_five_minutes'])) {
        $schedules['fqj_five_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 Minutes'];
    }

    return $schedules;
}
add_filter('cron_schedules', 'fqj_add_cron_interval');

/**
 * Enqueue posts to the invalidation queue.
 */
function fqj_queue_add_posts($post_ids)
{
    if (empty($post_ids)) {
        return false;
    }

    $post_ids = array_map('intval', $post_ids);
    $post_ids = array_filter($post_ids);

    if (empty($post_ids)) {
        return false;
    }

    $key = 'fqj_invalidate_queue';
    $existing = get_transient($key);
    $queue = [];
    if ($existing !== false) {
        $queue = maybe_unserialize($existing);
        if (! is_array($queue)) {
            $queue = [];
        }
    }

    $queue_map = array_flip($queue);
    foreach ($post_ids as $pid) {
        if (! isset($queue_map[$pid])) {
            $queue[] = $pid;
        }
    }

    set_transient($key, $queue, DAY_IN_SECONDS);

    return true;
}

/**
 * Pop up to $limit posts from queue (returns array of post IDs popped). FIFO.
 */
function fqj_queue_pop_posts($limit = 100)
{
    $key = 'fqj_invalidate_queue';
    $existing = get_transient($key);
    if ($existing === false) {
        return [];
    }

    $queue = maybe_unserialize($existing);
    if (! is_array($queue) || empty($queue)) {
        return [];
    }

    $pop = array_splice($queue, 0, intval($limit));
    if (empty($queue)) {
        delete_transient($key);
    } else {
        set_transient($key, $queue, DAY_IN_SECONDS);
    }

    return array_map('intval', $pop);
}

/**
 * Get the approximate queue length
 */
function fqj_queue_length()
{
    $key = 'fqj_invalidate_queue';
    $existing = get_transient($key);
    if ($existing === false) {
        return 0;
    }
    $queue = maybe_unserialize($existing);
    if (! is_array($queue)) {
        return 0;
    }

    return count($queue);
}

/**
 * Log an invalidation worker run.
 * Stores an entry into option 'fqj_invalidation_log' as array of entries:
 * [ [ 'ts' => 12345, 'processed' => n, 'sample' => [ids] ], ... ]
 * Keeps only last 200 entries (configurable later).
 */
function fqj_log_invalidation_run($processed_count, $sample_ids = [])
{
    $opt_name = 'fqj_invalidation_log';
    $log = get_option($opt_name, []);
    if (! is_array($log)) {
        $log = [];
    }

    $entry = [
        'ts' => time(),
        'processed' => intval($processed_count),
        'sample' => array_slice(array_map('intval', $sample_ids), 0, 20),
    ];

    array_unshift($log, $entry); // newest first

    // cap length
    $max = 200; // reasonable cap
    if (count($log) > $max) {
        $log = array_slice($log, 0, $max);
    }

    update_option($opt_name, $log);
    update_option('fqj_last_queue_run', time());
}

/**
 * Cron worker: processes up to batch_size posts from the queue
 */
function fqj_process_invalidation_queue_cron()
{
    $opts = get_option(FQJ_OPTION_KEY);
    $batch_size = isset($opts['batch_size']) ? intval($opts['batch_size']) : 500;

    $to_process = fqj_queue_pop_posts($batch_size);
    if (empty($to_process)) {
        // still record last run time
        update_option('fqj_last_queue_run', time());
        fqj_log_invalidation_run(0, []);

        return;
    }

    foreach ($to_process as $pid) {
        $pid = intval($pid);
        if ($pid <= 0) {
            continue;
        }
        delete_transient('fqj_faq_json_' . $pid);
    }

    // log and save last run
    fqj_log_invalidation_run(count($to_process), array_slice($to_process, 0, 20));
}
add_action('fqj_process_invalidation_queue', 'fqj_process_invalidation_queue_cron');

/**
 * Immediate queue processor (used by WP-CLI, admin AJAX or ad-hoc)
 * Returns number processed.
 */
function fqj_process_invalidation_queue_now($limit = null)
{
    $opts = get_option(FQJ_OPTION_KEY);
    $batch_size = isset($opts['batch_size']) ? intval($opts['batch_size']) : 500;
    $limit = $limit ? intval($limit) : $batch_size;

    $processed = 0;
    $sample = [];

    // Pop and process until we hit the limit once
    $pop = fqj_queue_pop_posts($limit);
    if (! empty($pop)) {
        foreach ($pop as $pid) {
            delete_transient('fqj_faq_json_' . intval($pid));
            $processed++;
            if (count($sample) < 20) {
                $sample[] = intval($pid);
            }
        }
    }

    // log run
    fqj_log_invalidation_run($processed, $sample);

    return $processed;
}

/**
 * Admin notice helper: show queue length on admin screens for admins.
 */
function fqj_admin_queue_notice()
{
    if (! current_user_can('manage_options')) {
        return;
    }
    $len = fqj_queue_length();
    if ($len > 0) {
        printf(
            '<div class="notice notice-info"><p>FAQ JSON-LD queue: <strong>%d</strong> ' .
            'posts pending invalidation. The background worker (WP-Cron) will process them in batches.</p></div>',
            intval($len)
        );
    }
}
add_action('admin_notices', 'fqj_admin_queue_notice');
