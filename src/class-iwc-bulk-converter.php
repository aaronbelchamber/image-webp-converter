<?php
/**
 * Bulk conversion engine for existing Media Library images.
 *
 * Scans eligible JPG/PNG attachments, buckets them by how (if at all) they
 * are referenced elsewhere in the database, and converts the buckets that
 * are provably safe to touch automatically. Anything referenced from
 * serialized data (page builders, widgets, customizer settings) is left
 * untouched and just reported — see class-iwc-admin.php for how that's
 * surfaced.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Bulk_Converter {

    /**
     * Scan the Media Library for eligible JPG/PNG attachments not yet
     * converted, and bucket each by reference type.
     *
     * @return array{
     *   unreferenced: int[],
     *   plain_content: int[],
     *   serialized_only: int[],
     * }
     */
    public static function scan(): array {
        global $wpdb;

        $attachment_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg', 'image/png')
               AND m.meta_id IS NULL",
            '_iwc_converted'
        ));

        $buckets = [
            'unreferenced'    => [],
            'plain_content'   => [],
            'serialized_only' => [],
        ];

        foreach ($attachment_ids as $attachment_id) {
            $buckets[self::determine_bucket((int) $attachment_id)][] = (int) $attachment_id;
        }

        return $buckets;
    }

    /**
     * Determine which bucket an attachment belongs in based on a read-only
     * search for its file across post_content and serialized postmeta/options.
     */
    private static function determine_bucket(int $attachment_id): string {
        $relative_base = self::get_relative_base($attachment_id);
        if ($relative_base === '') {
            // Couldn't resolve a path to search for — safest is to treat as
            // referenced-unknown rather than risk a false "unreferenced".
            return 'serialized_only';
        }

        if (self::has_serialized_reference($relative_base)) {
            return 'serialized_only';
        }

        if (self::has_plain_content_reference($relative_base)) {
            return 'plain_content';
        }

        return 'unreferenced';
    }

    /**
     * The uploads-relative path without extension, e.g. "2024/05/photo" —
     * deliberately broad so it also catches references to intermediate
     * thumbnail sizes ("2024/05/photo-300x200.jpg"), not just the full-size
     * file, without needing to enumerate every registered size.
     */
    private static function get_relative_base(int $attachment_id): string {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($file)) {
            return '';
        }
        return preg_replace('/\.(jpe?g|png)$/i', '', $file);
    }

    private static function has_plain_content_reference(string $relative_base): bool {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($relative_base) . '%';
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status != 'trash'
               AND post_type NOT IN ('revision', 'attachment')
               AND post_content LIKE %s",
            $like
        ));
        return $count > 0;
    }

    /**
     * No row limit here deliberately: this check exists specifically to
     * catch page-builder/widget references before a file is ever moved, so
     * silently truncating the scan would defeat the entire point of it.
     */
    private static function has_serialized_reference(string $relative_base): bool {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($relative_base) . '%';

        $meta_values = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
            $like
        ));
        foreach ($meta_values as $value) {
            if (is_serialized($value)) {
                return true;
            }
        }

        $option_values = $wpdb->get_col($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE %s",
            $like
        ));
        foreach ($option_values as $value) {
            if (is_serialized($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Referencing posts for an attachment (used to populate the log's
     * references_updated field and the Cleanup Review screen).
     */
    private static function find_referencing_posts(string $relative_base): array {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($relative_base) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status != 'trash'
               AND post_type NOT IN ('revision', 'attachment')
               AND post_content LIKE %s",
            $like
        ), ARRAY_A);
        return $rows ?: [];
    }

    /**
     * Convert a single attachment. $bucket is the caller's hint (from an
     * earlier scan) about which handling to use, but it is never trusted
     * blindly for the destructive 'unreferenced' path — see the re-check
     * right before that branch below. Scan and conversion happen in
     * separate HTTP requests, so content can change in between; trusting a
     * stale or client-supplied bucket for a destructive action would defeat
     * the safety guarantee this whole feature is built on.
     */
    public static function convert_attachment(int $attachment_id, string $bucket, int $quality): array {
        if (get_post_meta($attachment_id, '_iwc_converted', true)) {
            return ['status' => 'skipped', 'message' => 'Already converted'];
        }

        if (!in_array($bucket, ['unreferenced', 'plain_content'], true)) {
            return ['status' => 'skipped', 'message' => 'Not an eligible bucket'];
        }

        $mime_type = get_post_mime_type($attachment_id);
        if (!in_array($mime_type, ['image/jpeg', 'image/png'], true)) {
            return ['status' => 'skipped', 'message' => 'Not an eligible image type'];
        }

        $original_path = get_attached_file($attachment_id);
        if (empty($original_path) || !file_exists($original_path)) {
            return ['status' => 'error', 'message' => 'Original file not found'];
        }

        $relative_base = self::get_relative_base($attachment_id);
        $log_id = IWC_Logger::log_start($attachment_id, $original_path, $bucket);

        $webp_path = iwc_resolve_webp_target_path($original_path);
        if (!iwc_convert_image_file_to_webp($original_path, $mime_type, $webp_path, $quality)) {
            IWC_Logger::log_result($log_id, ['status' => 'error', 'message' => 'Conversion failed or was skipped by a safety guard']);
            return ['status' => 'error', 'message' => 'Conversion failed or was skipped by a safety guard'];
        }

        // Gather the old size files (full-size + every existing intermediate
        // size) before we ask WordPress to regenerate metadata, so we still
        // know what to move for the 'unreferenced' bucket afterward.
        $old_files = self::collect_attachment_files($attachment_id, $original_path);
        $old_files_json = wp_json_encode($old_files);

        update_attached_file($attachment_id, $webp_path);
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => 'image/webp']);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $webp_path);
        wp_update_attachment_metadata($attachment_id, $metadata);

        $bytes_after = @filesize($webp_path) ?: 0;
        update_post_meta($attachment_id, '_iwc_converted', current_time('mysql'));

        // Re-verify "unreferenced" right now, at the moment of the
        // destructive action, rather than trusting the bucket the caller
        // passed in — it reflects a scan that may be stale by the time this
        // batch actually runs. If anything has since referenced this file,
        // fall through to the safer plain_content handling below instead of
        // moving the original out from under it.
        $is_still_unreferenced = $bucket === 'unreferenced'
            && !self::has_serialized_reference($relative_base)
            && !self::has_plain_content_reference($relative_base);

        if ($is_still_unreferenced) {
            $moved_to = self::move_files_to_holding_folder($old_files);
            update_post_meta($attachment_id, '_iwc_backup_path', $moved_to);
            IWC_Logger::log_result($log_id, [
                'status'         => 'trashed',
                'new_path'       => $webp_path,
                'bytes_after'    => $bytes_after,
                'old_files_json' => $old_files_json,
            ]);
            return ['status' => 'trashed', 'bytes_saved' => 0];
        }

        // plain_content bucket: replace the literal URL in referencing posts,
        // but leave the original files in place until manually reviewed.
        $referencing_posts = self::find_referencing_posts($relative_base);
        $old_url = self::path_to_url($original_path);
        $new_url = self::path_to_url($webp_path);
        $updated = [];
        foreach ($referencing_posts as $post) {
            $post_obj = get_post($post['ID']);
            if (!$post_obj) {
                continue;
            }
            $new_content = str_replace($old_url, $new_url, $post_obj->post_content);
            if ($new_content !== $post_obj->post_content) {
                $result = wp_update_post(['ID' => $post['ID'], 'post_content' => $new_content], true);
                if (!is_wp_error($result) && $result > 0) {
                    $updated[] = ['post_id' => (int) $post['ID'], 'post_title' => $post['post_title']];
                }
            }
        }

        IWC_Logger::log_result($log_id, [
            'status'             => 'pending_cleanup',
            'new_path'           => $webp_path,
            'bytes_after'        => $bytes_after,
            'references_updated' => wp_json_encode($updated),
            'old_files_json'     => $old_files_json,
        ]);

        return ['status' => 'pending_cleanup', 'references_updated' => count($updated)];
    }

    /**
     * Move the original files for a 'pending_cleanup' log row into the
     * holding folder now, and mark it 'trashed'. Used by the Cleanup Review
     * screen's "Move Selected to Trash" action.
     */
    public static function finalize_cleanup(int $log_id): bool {
        $rows = IWC_Logger::get_by_ids([$log_id]);
        if (empty($rows)) {
            return false;
        }
        $row = $rows[0];
        if ($row->status !== 'pending_cleanup') {
            return false;
        }

        $old_files = json_decode((string) $row->old_files_json, true);
        if (!is_array($old_files)) {
            $old_files = [$row->original_path];
        }

        $moved_to = self::move_files_to_holding_folder($old_files);
        update_post_meta((int) $row->attachment_id, '_iwc_backup_path', $moved_to);
        IWC_Logger::log_result($log_id, ['status' => 'trashed']);

        return true;
    }

    private static function path_to_url(string $path): string {
        $upload_dir = wp_get_upload_dir();
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $path);
    }

    /**
     * Every physical file belonging to this attachment (full-size + every
     * existing intermediate thumbnail), read before metadata regeneration
     * overwrites the size list.
     */
    private static function collect_attachment_files(int $attachment_id, string $original_path): array {
        $files = [$original_path];
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return $files;
        }
        $dir = dirname($original_path);
        foreach ($metadata['sizes'] as $size) {
            if (!empty($size['file'])) {
                $files[] = trailingslashit($dir) . $size['file'];
            }
        }
        return array_unique($files);
    }

    /**
     * Move a set of files into wp-content/uploads/iwc-trash/{Y}/{m}/..,
     * mirroring their original relative path. Returns the destination
     * directory used.
     */
    private static function move_files_to_holding_folder(array $files): string {
        $upload_dir = wp_get_upload_dir();
        $holding_root = trailingslashit($upload_dir['basedir']) . 'iwc-trash';

        if (!file_exists(trailingslashit($holding_root) . 'index.php')) {
            wp_mkdir_p($holding_root);
            @file_put_contents(trailingslashit($holding_root) . 'index.php', "<?php\n// Silence is golden.\n");
        }

        $destination_dir = '';
        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $relative = ltrim(str_replace($upload_dir['basedir'], '', $file), '/\\');
            $destination = trailingslashit($holding_root) . $relative;
            $destination_dir = dirname($destination);
            wp_mkdir_p($destination_dir);

            if (!@rename($file, $destination)) {
                if (@copy($file, $destination)) {
                    @unlink($file);
                }
            }
        }

        return $destination_dir;
    }
}
