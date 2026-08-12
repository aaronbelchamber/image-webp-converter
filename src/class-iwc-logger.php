<?php
/**
 * Conversion log writer for Image WebP Converter.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Logger {
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'iwc_conversion_log';
    }

    /**
     * Insert a new "in progress" log row and return its ID.
     */
    public static function log_start(int $attachment_id, string $original_path, string $bucket): int {
        global $wpdb;
        $wpdb->insert(
            self::table(),
            [
                'attachment_id' => $attachment_id,
                'original_path' => $original_path,
                'bucket'        => $bucket,
                'status'        => 'converting',
                'bytes_before'  => @filesize($original_path) ?: 0,
                'started_at'    => time(),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d']
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing log row with a final (or error) status.
     */
    public static function log_result(int $log_id, array $fields): void {
        global $wpdb;
        $fields['completed_at'] = time();
        $formats = [];
        foreach ($fields as $key => $value) {
            $formats[] = is_int($value) ? '%d' : '%s';
        }
        $wpdb->update(self::table(), $fields, ['id' => $log_id], $formats, ['%d']);
    }

    /**
     * Rows currently awaiting manual cleanup review (bucket = plain_content,
     * originals not yet moved to the holding folder).
     */
    public static function get_pending_cleanup(): array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results("SELECT * FROM $table WHERE status = 'pending_cleanup' ORDER BY completed_at DESC");
    }

    public static function get_by_ids(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id IN ($placeholders)", $ids));
    }

    public static function get_summary_counts(): array {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as total, SUM(bytes_before - bytes_after) as bytes_saved FROM $table GROUP BY status", ARRAY_A);
        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['status']] = [
                'total'       => (int) $row['total'],
                'bytes_saved' => (int) $row['bytes_saved'],
            ];
        }
        return $summary;
    }
}
