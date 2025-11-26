# FAQ JSON-LD Manager (Enterprise, queue-enabled)

A WordPress plugin to manage FAQ items as a custom post type (CPT) and inject FAQ JSON-LD into posts/pages. Designed for performance and scalability with advanced cache management, background queueing, and admin/CLI tools.

## Features

- **Custom Post Type:** Manage FAQ items via the `faq_item` CPT.
- **Mapping Table:** Fast MySQL table (`fqj_mappings`) links FAQ items to posts, post types, terms, URLs, or global context.
- **JSON-LD Output:** Injects FAQ schema on the frontend, with per-post transient caching.
- **Cache Invalidation:** Background queue (WP-Cron or WP-CLI) for efficient cache purging.
- **Admin UI:** Meta boxes and settings for mapping, output, and diagnostics.
- **Health & Diagnostics:** Admin page for queue/logs and manual actions.
- **WP-CLI Integration:** Commands for cache and queue management.

## Installation

1. Copy the plugin folder to your WordPress `wp-content/plugins/` directory.
2. Activate via the WordPress admin Plugins page.
3. On activation, the mapping table is created automatically.

## Usage

- **Add FAQ Items:** Use the `FAQ Items` CPT in the admin menu.
- **Associate FAQs:** Use meta boxes to link FAQs to posts, post types, terms, URLs, or globally.
- **Settings:** Configure cache TTL, batch size, and output type under the FAQ Items > Settings menu.
- **Health:** Monitor queue/logs and run manual actions under FAQ Items > Health.
- **WP-CLI:**
  - `wp fqj purge-transients` — Purge all FAQ JSON-LD transients.
  - `wp fqj process-queue [--limit=N]` — Process the invalidation queue.

## Developer Notes

- **Key Files:**
  - `faq-jsonld.php`: Main loader and CPT registration
  - `includes/indexer.php`: Mapping table logic
  - `includes/queue.php`: Invalidation queue
  - `includes/frontend.php`: JSON-LD output/caching
  - `includes/admin.php`: Admin UI/meta boxes
  - `includes/settings.php`: Settings page
  - `includes/health.php`: Health/diagnostics UI
  - `includes/wpcli.php`: WP-CLI commands
  - `assets/js/fqj-admin.js`: Admin JS (Select2, AJAX)
- **Mapping Types:** All associations are stored in a single table for fast lookups.
- **Transient Keys:** `fqj_faq_json_{post_id}`
- **Admin JS:** Uses Select2 for post selectors, AJAX for search (`fqj_search_posts` action).
- **Background Processing:** Invalidation queue is processed every 5 minutes by WP-Cron.

## Contributing

Pull requests and issues are welcome. Please follow project conventions and review inline comments in each file for guidance.

## License

GPLv2+

---

For more details, see the inline comments in each file or the `.github/copilot-instructions.md` for AI agent guidance.
