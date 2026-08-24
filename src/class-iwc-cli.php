<?php
/**
 * WP-CLI commands for Image WebP Converter.
 *
 * The browser-driven bulk converter is bounded by what an admin-ajax request
 * can survive: batches of twenty, a scan paged to keep each request short, and
 * a tab that has to stay open. On a library of tens of thousands of images
 * that is the wrong tool — this is the one that finishes, over SSH, without a
 * timeout or an open browser.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_CLI {

    public static function register(): void {
        WP_CLI::add_command('iwc scan', [__CLASS__, 'scan']);
        WP_CLI::add_command('iwc convert', [__CLASS__, 'convert']);
        WP_CLI::add_command('iwc status', [__CLASS__, 'status']);
    }

    /**
     * Report what a conversion run would find, changing nothing.
     *
     * ## EXAMPLES
     *
     *     wp iwc scan
     *
     * @when after_wp_load
     */
    public static function scan(array $args = [], array $assoc_args = []): void {
        $buckets = IWC_Bulk_Converter::scan();

        WP_CLI::log(sprintf('Unreferenced (convert and clean up):  %d', count($buckets['unreferenced'])));
        WP_CLI::log(sprintf('In post content (convert and rewrite): %d', count($buckets['plain_content'])));
        WP_CLI::log(sprintf('Referenced elsewhere (left alone):     %d', count($buckets['serialized_only'])));

        self::warn_about_environment();
    }

    /**
     * Convert eligible Media Library images to WEBP.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List what would be converted without touching anything.
     *
     * [--bucket=<bucket>]
     * : Restrict to one bucket: unreferenced or plain_content.
     *
     * [--limit=<number>]
     * : Stop after this many images.
     *
     * [--quality=<number>]
     * : Override the configured WEBP quality for this run.
     *
     * ## EXAMPLES
     *
     *     wp iwc convert --dry-run
     *     wp iwc convert --bucket=unreferenced --limit=500
     *
     * @when after_wp_load
     */
    public static function convert(array $args = [], array $assoc_args = []): void {
        $dry_run = !empty($assoc_args['dry-run']);
        $bucket_filter = isset($assoc_args['bucket']) ? (string) $assoc_args['bucket'] : '';
        $limit = isset($assoc_args['limit']) ? max(0, (int) $assoc_args['limit']) : 0;

        $eligible = ['unreferenced', 'plain_content'];
        if ($bucket_filter !== '' && !in_array($bucket_filter, $eligible, true)) {
            WP_CLI::error(sprintf('Unknown bucket "%s". Use one of: %s', $bucket_filter, implode(', ', $eligible)));
            return;
        }

        $quality = isset($assoc_args['quality'])
            ? max(0, min(100, (int) $assoc_args['quality']))
            : (int) get_option(IWC_OPTION_QUALITY, 82);

        self::warn_about_environment();

        $buckets = IWC_Bulk_Converter::scan();
        $queue = [];
        foreach ($eligible as $bucket) {
            if ($bucket_filter !== '' && $bucket !== $bucket_filter) {
                continue;
            }
            foreach ($buckets[$bucket] as $attachment_id) {
                $queue[] = ['id' => $attachment_id, 'bucket' => $bucket];
            }
        }

        if ($limit > 0) {
            $queue = array_slice($queue, 0, $limit);
        }

        if ($queue === []) {
            WP_CLI::success('Nothing to convert.');
            return;
        }

        if ($dry_run) {
            foreach ($queue as $item) {
                WP_CLI::log(sprintf('would convert #%d (%s)', $item['id'], $item['bucket']));
            }
            WP_CLI::success(sprintf('%d image(s) would be converted. Nothing was changed.', count($queue)));
            return;
        }

        // Same lock the browser path takes: a CLI run and an open admin tab
        // are exactly the concurrent case that could double-convert an image.
        if (!IWC_Lock::acquire()) {
            WP_CLI::error('Another bulk conversion is already running (check for an open Convert Existing Images tab).');
            return;
        }

        $totals = ['trashed' => 0, 'pending_cleanup' => 0, 'references_failed' => 0, 'skipped' => 0, 'error' => 0];
        $bytes_saved = 0;

        try {
            foreach ($queue as $item) {
                $result = IWC_Bulk_Converter::convert_attachment($item['id'], $item['bucket'], $quality);
                $status = $result['status'] ?? 'error';
                if (isset($totals[$status])) {
                    $totals[$status]++;
                }
                $bytes_saved += (int) ($result['bytes_saved'] ?? 0);

                if ($status === 'error') {
                    WP_CLI::warning(sprintf('#%d: %s', $item['id'], $result['message'] ?? 'conversion failed'));
                }
            }
        } finally {
            IWC_Lock::release();
        }

        WP_CLI::log('');
        WP_CLI::log(sprintf('Converted and cleaned up:            %d', $totals['trashed']));
        WP_CLI::log(sprintf('Converted, originals kept for review: %d', $totals['pending_cleanup']));
        if ($totals['references_failed'] > 0) {
            WP_CLI::log(sprintf('Converted, references need attention: %d', $totals['references_failed']));
        }
        if ($totals['skipped'] > 0) {
            WP_CLI::log(sprintf('Skipped:                              %d', $totals['skipped']));
        }
        if ($totals['error'] > 0) {
            WP_CLI::log(sprintf('Errors (left unchanged):              %d', $totals['error']));
        }

        WP_CLI::success(sprintf(
            '%d image(s) processed, %s reclaimed.',
            count($queue),
            size_format(max(0, $bytes_saved))
        ));
    }

    /**
     * Show totals from the conversion log.
     *
     * ## EXAMPLES
     *
     *     wp iwc status
     *
     * @when after_wp_load
     */
    public static function status(array $args = [], array $assoc_args = []): void {
        $summary = IWC_Logger::get_summary_counts();

        if ($summary === []) {
            WP_CLI::log('No conversions recorded yet.');
            return;
        }

        $rows = [];
        $total_saved = 0;
        foreach ($summary as $status => $counts) {
            $rows[] = [
                'status'      => $status,
                'images'      => $counts['total'],
                'space saved' => size_format(max(0, $counts['bytes_saved'])),
            ];
            $total_saved += max(0, $counts['bytes_saved']);
        }

        WP_CLI\Utils\format_items('table', $rows, ['status', 'images', 'space saved']);
        WP_CLI::success(sprintf('%s reclaimed in total.', size_format($total_saved)));
    }

    /**
     * Surface the same caveats the admin screen shows, so a CLI-only operator
     * isn't the one person who never sees them.
     */
    private static function warn_about_environment(): void {
        $custom_table = IWC_Compat::custom_table_plugins();
        if (!empty($custom_table)) {
            WP_CLI::warning(sprintf(
                '%s store image URLs in their own tables, which cannot be searched. Originals will be kept for review rather than moved.',
                implode(', ', $custom_table)
            ));
        }

        $optimizers = IWC_Compat::conflicting_optimizers();
        if (!empty($optimizers)) {
            WP_CLI::warning(sprintf('Another image optimiser is active: %s. Run only one.', implode(', ', $optimizers)));
        }

        $builders = IWC_Compat::page_builders();
        if (!empty($builders)) {
            WP_CLI::log(sprintf('Note: %s layouts are not rewritten; those images are reported and left alone.', implode(', ', $builders)));
        }
    }
}
