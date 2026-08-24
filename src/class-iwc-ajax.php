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

        // admin_init does not fire on admin-ajax.php, so the schema check
        // hooked there can't be relied on to have run before these endpoints
        // are reached — notably on a freshly network-activated site.
        IWC_DB::maybe_upgrade();
    }

    /**
     * Bucket one page of the Media Library. The client calls this repeatedly,
     * passing back the last_id it was given, until `done` comes back true —
     * scanning the whole library in a single request timed out on any
     * real-sized library.
     */
    public static function handle_scan(): void {
        self::check_access();

        $after_id = isset($_POST['after_id']) ? absint($_POST['after_id']) : 0;
        $result = IWC_Bulk_Converter::scan_batch($after_id);

        $response = [
            'unreferenced'          => $result['buckets']['unreferenced'],
            'plain_content'         => $result['buckets']['plain_content'],
            'serialized_only_count' => count($result['buckets']['serialized_only']),
            'last_id'               => $result['last_id'],
            'scanned'               => $result['scanned'],
            'done'                  => $result['done'],
        ];

        // Only worth the extra COUNT(*) on the first page, to give the
        // progress display a denominator.
        if ($after_id === 0) {
            $response['total'] = IWC_Bulk_Converter::count_eligible();
        }

        wp_send_json_success($response);
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

        // Serialise batches across requests. Without this two tabs can each
        // pass convert_attachment()'s already-converted check for the same
        // image before either has written its result, and both convert it.
        if (!IWC_Lock::acquire()) {
            wp_send_json_error([
                'message' => 'Another bulk conversion is already running. Wait for it to finish, or reload this page.',
            ], 409);
        }

        $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
        $results = [];

        try {
            foreach ($ids as $attachment_id) {
                $results[$attachment_id] = IWC_Bulk_Converter::convert_attachment($attachment_id, $bucket, $quality);
            }
        } finally {
            // Must not survive a fatal in one image's conversion, or the
            // whole feature stays locked until the timeout expires.
            IWC_Lock::release();
        }

        wp_send_json_success(['results' => $results]);
    }
}
