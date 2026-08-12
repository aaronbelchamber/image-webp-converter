<?php
/**
 * AJAX endpoints for the "Convert Existing Images" bulk-conversion UI.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Ajax {
    /** Hard server-side cap, independent of whatever batch size the client sends. */
    const MAX_BATCH_SIZE = 20;

    public static function register(): void {
        add_action('wp_ajax_iwc_bulk_scan', [__CLASS__, 'handle_scan']);
        add_action('wp_ajax_iwc_bulk_process_batch', [__CLASS__, 'handle_process_batch']);
    }

    private static function check_access(): void {
        check_ajax_referer('iwc_bulk_convert', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }
    }

    public static function handle_scan(): void {
        self::check_access();
        $buckets = IWC_Bulk_Converter::scan();
        wp_send_json_success([
            'unreferenced'    => $buckets['unreferenced'],
            'plain_content'   => $buckets['plain_content'],
            'serialized_only_count' => count($buckets['serialized_only']),
        ]);
    }

    public static function handle_process_batch(): void {
        self::check_access();

        $ids = isset($_POST['attachment_ids']) ? array_map('intval', (array) $_POST['attachment_ids']) : [];
        $bucket = isset($_POST['bucket']) ? sanitize_key($_POST['bucket']) : '';

        if (empty($ids) || !in_array($bucket, ['unreferenced', 'plain_content'], true)) {
            wp_send_json_error(['message' => 'Invalid batch request']);
        }

        if (count($ids) > self::MAX_BATCH_SIZE) {
            $ids = array_slice($ids, 0, self::MAX_BATCH_SIZE);
        }

        $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
        $results = [];
        foreach ($ids as $attachment_id) {
            $results[$attachment_id] = IWC_Bulk_Converter::convert_attachment($attachment_id, $bucket, $quality);
        }

        wp_send_json_success(['results' => $results]);
    }
}
