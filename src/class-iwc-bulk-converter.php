<?php
/**
 * Bulk conversion engine for existing Media Library images.
 *
 * Scans eligible JPG/PNG attachments, buckets them by how (if at all) they
 * are referenced elsewhere in the database, and converts the buckets that
 * are provably safe to touch automatically. Anything referenced from
 * postmeta or options (page builders, widgets, customizer settings) is left
 * untouched and just reported — see class-iwc-admin.php for how that's
 * surfaced.
 *
 * Note on naming: the third bucket is still keyed 'serialized_only' for wire
 * compatibility with the AJAX response and assets/bulk-convert.js, but it no
 * longer means "serialized" — see has_external_reference() for what it
 * actually tests now. Worth renaming end-to-end at the next breaking change.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Bulk_Converter {

    /** Attachments bucketed per scan request. */
    const SCAN_BATCH_SIZE = 100;

    /** Hard ceiling on the batch size, whatever a caller asks for. */
    const SCAN_BATCH_MAX = 500;

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
        $buckets = self::empty_buckets();
        $after_id = 0;

        do {
            $batch = self::scan_batch($after_id);
            foreach ($buckets as $key => $_) {
                $buckets[$key] = array_merge($buckets[$key], $batch['buckets'][$key]);
            }
            $after_id = $batch['last_id'];
        } while (!$batch['done']);

        return $buckets;
    }

    private static function empty_buckets(): array {
        return [
            'unreferenced'    => [],
            'plain_content'   => [],
            'serialized_only' => [],
        ];
    }

    /**
     * How many attachments a full scan still has to look at. Used only to
     * show a total alongside scan progress.
     */
    public static function count_eligible(): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg', 'image/png')
               AND m.meta_id IS NULL",
            '_iwc_converted'
        ));
    }

    /**
     * Bucket one page of attachments, resuming after $after_id.
     *
     * Bucketing costs several unindexed LIKE scans per attachment, so doing
     * the whole library in one request was a guaranteed timeout on anything
     * past a few hundred images — the caller now drives this in pages and
     * keeps its own progress.
     *
     * Paged by ID cursor rather than LIMIT/OFFSET: OFFSET has to walk and
     * discard every preceding row on each page, and the result set shifts
     * underneath it as attachments get marked converted, which silently skips
     * rows. A cursor is stable under both.
     *
     * @return array{buckets: array, last_id: int, scanned: int, done: bool}
     */
    public static function scan_batch(int $after_id = 0, int $limit = self::SCAN_BATCH_SIZE): array {
        global $wpdb;

        $limit = max(1, min(self::SCAN_BATCH_MAX, $limit));

        $attachment_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg', 'image/png')
               AND m.meta_id IS NULL
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            '_iwc_converted',
            max(0, $after_id),
            $limit
        ));

        $buckets = self::empty_buckets();
        $last_id = max(0, $after_id);

        foreach ($attachment_ids as $attachment_id) {
            $attachment_id = (int) $attachment_id;
            $buckets[self::determine_bucket($attachment_id)][] = $attachment_id;
            $last_id = max($last_id, $attachment_id);
        }

        $scanned = count($attachment_ids);

        return [
            'buckets' => $buckets,
            'last_id' => $last_id,
            'scanned' => $scanned,
            'done'    => $scanned < $limit,
        ];
    }

    /**
     * Determine which bucket an attachment belongs in based on a read-only
     * search for its file across post_content, postmeta and options.
     */
    private static function determine_bucket(int $attachment_id): string {
        $relative_base = self::get_relative_base($attachment_id);
        if ($relative_base === '') {
            // Couldn't resolve a path to search for — safest is to treat as
            // referenced-unknown rather than risk a false "unreferenced".
            return 'serialized_only';
        }

        if (self::has_external_reference($relative_base)) {
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

        $patterns = self::reference_like_patterns($relative_base);
        $clause = implode(' OR ', array_fill(0, count($patterns), 'post_content LIKE %s'));

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts}
             WHERE post_status != 'trash'
               AND post_type NOT IN ('revision', 'attachment')
               AND ($clause)
             LIMIT 1",
            $patterns
        ));
    }

    /**
     * Whether this file is referenced anywhere outside post_content — i.e.
     * from postmeta or options, where this plugin has no safe way to rewrite
     * the reference. A match here means "hands off": the attachment is
     * reported, never converted.
     *
     * Deliberately encoding-agnostic. An earlier version of this check only
     * counted a match when is_serialized() was also true, which made it blind
     * to every page builder that stores its layout as JSON rather than a PHP
     * serialized array — Elementor (_elementor_data), Bricks, Oxygen,
     * Breakdance. Those builders also keep post_content empty, so their
     * images fell through to 'unreferenced' and had their originals moved to
     * the holding folder, breaking the page. The format a reference is stored
     * in tells us nothing about whether moving the file breaks something; the
     * only question that matters is whether the path appears at all.
     *
     * Two exclusions, both about not matching our own bookkeeping:
     *
     * - Meta owned by an attachment is skipped. Every image carries a
     *   serialized _wp_attachment_metadata containing its own path, so
     *   without this every attachment matches itself and nothing is ever
     *   eligible. Excluding all attachment-owned meta (not just this
     *   attachment's) also avoids a sibling false-positive: the base is
     *   extension-stripped, so "2024/05/photo" matches photo-2.jpg's metadata
     *   too. Page builders never store layouts on attachment posts.
     * - Transients are skipped: regenerable caches, not references.
     *
     * Orphaned meta (post_id pointing at a row that no longer exists) counts
     * as a reference — leftover builder data is exactly the case worth being
     * conservative about.
     *
     * No row limit on what's examined, but LIMIT 1 lets the query stop at the
     * first hit instead of hauling every matching blob into PHP.
     */
    private static function has_external_reference(string $relative_base): bool {
        global $wpdb;

        $patterns = self::reference_like_patterns($relative_base);
        $meta_clause = implode(' OR ', array_fill(0, count($patterns), 'm.meta_value LIKE %s'));
        $option_clause = implode(' OR ', array_fill(0, count($patterns), 'option_value LIKE %s'));

        $in_postmeta = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} m
             LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE ($meta_clause)
               AND (p.ID IS NULL OR p.post_type <> 'attachment')
             LIMIT 1",
            $patterns
        ));
        if ($in_postmeta) {
            return true;
        }

        $in_options = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->options}
             WHERE ($option_clause)
               AND substr(option_name, 1, 11) <> '_transient_'
               AND substr(option_name, 1, 16) <> '_site_transient_'
             LIMIT 1",
            $patterns
        ));

        return (bool) $in_options;
    }

    /**
     * LIKE patterns to search a stored value for this file, covering the ways
     * a path is actually written to the database.
     *
     * json_encode() escapes forward slashes by default, so a builder storing
     * its layout as JSON writes "2024\/05\/photo.jpg", not "2024/05/photo.jpg"
     * — and Elementor, Bricks, Oxygen and Breakdance all go through
     * wp_json_encode(), which does not pass JSON_UNESCAPED_SLASHES. Searching
     * only the plain form silently misses every one of them, which is the
     * same blind spot that made these images look unreferenced in the first
     * place.
     *
     * Both patterns go into a single query rather than two: it's one table
     * scan either way, and the extra comparison per row costs nothing next to
     * the scan itself.
     *
     * @return string[]
     */
    private static function reference_like_patterns(string $relative_base): array {
        global $wpdb;

        $patterns = ['%' . $wpdb->esc_like($relative_base) . '%'];

        $json_escaped = str_replace('/', '\/', $relative_base);
        if ($json_escaped !== $relative_base) {
            $patterns[] = '%' . $wpdb->esc_like($json_escaped) . '%';
        }

        return $patterns;
    }

    /**
     * Referencing posts for an attachment (used to populate the log's
     * references_updated field and the Cleanup Review screen).
     */
    private static function find_referencing_posts(string $relative_base): array {
        global $wpdb;

        // Must use the same patterns has_plain_content_reference() does: if
        // one finds a post the other doesn't, an attachment gets bucketed as
        // rewritable and then has nothing rewritten.
        $patterns = self::reference_like_patterns($relative_base);
        $clause = implode(' OR ', array_fill(0, count($patterns), 'post_content LIKE %s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status != 'trash'
               AND post_type NOT IN ('revision', 'attachment')
               AND ($clause)",
            $patterns
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
            return ['status' => 'skipped', 'message' => __('Already converted', 'image-webp-converter')];
        }

        if (!in_array($bucket, ['unreferenced', 'plain_content'], true)) {
            return ['status' => 'skipped', 'message' => __('Not an eligible bucket', 'image-webp-converter')];
        }

        $mime_type = get_post_mime_type($attachment_id);
        if (!in_array($mime_type, ['image/jpeg', 'image/png'], true)) {
            return ['status' => 'skipped', 'message' => __('Not an eligible image type', 'image-webp-converter')];
        }

        $original_path = get_attached_file($attachment_id);
        if (empty($original_path) || !file_exists($original_path)) {
            return ['status' => 'error', 'message' => __('Original file not found', 'image-webp-converter')];
        }

        $relative_base = self::get_relative_base($attachment_id);
        $log_id = IWC_Logger::log_start($attachment_id, $original_path, $bucket);

        // Read everything that describes the pre-conversion state before any
        // of it is mutated: metadata is regenerated below, and get_attached_file()
        // starts returning the .webp path, so neither can be recovered later.
        $old_metadata = wp_get_attachment_metadata($attachment_id);
        $old_metadata = is_array($old_metadata) ? $old_metadata : [];
        $old_files = self::collect_attachment_files($attachment_id, $original_path, $old_metadata);
        $old_files_json = wp_json_encode($old_files);
        $bytes_before = self::total_size($old_files);

        $webp_path = iwc_resolve_webp_target_path($original_path);
        if (!iwc_convert_image_file_to_webp($original_path, $mime_type, $webp_path, $quality)) {
            IWC_Logger::log_result($log_id, ['status' => 'error', 'message' => __('Conversion failed or was skipped by a safety guard', 'image-webp-converter')]);
            return ['status' => 'error', 'message' => __('Conversion failed or was skipped by a safety guard', 'image-webp-converter')];
        }

        update_attached_file($attachment_id, $webp_path);
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => 'image/webp']);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $webp_path);

        // Regenerating against a WEBP produces no image_meta — WEBP can't
        // carry the EXIF/IPTC WordPress reads, and the JPEG that could is
        // about to be moved aside. Carry the existing record forward so the
        // camera data, credit and copyright already stored for this
        // attachment survive the conversion.
        if (is_array($metadata) && empty($metadata['image_meta']) && !empty($old_metadata['image_meta'])) {
            $metadata['image_meta'] = $old_metadata['image_meta'];
        }

        wp_update_attachment_metadata($attachment_id, $metadata);

        $new_metadata = is_array($metadata) ? $metadata : [];
        $bytes_after = self::total_size(
            self::collect_attachment_files($attachment_id, $webp_path, $new_metadata)
        );
        update_post_meta($attachment_id, '_iwc_converted', current_time('mysql'));

        // Re-verify "unreferenced" right now, at the moment of the
        // destructive action, rather than trusting the bucket the caller
        // passed in — it reflects a scan that may be stale by the time this
        // batch actually runs. If anything has since referenced this file,
        // fall through to the safer plain_content handling below instead of
        // moving the original out from under it.
        // "Nothing references this" is only as trustworthy as the places the
        // scan can look. When a plugin is present that keeps image URLs in its
        // own tables — TranslatePress, WPML, Slider Revolution, MailPoet — the
        // scan is blind to them, so the destructive branch is withheld and the
        // originals are kept for review instead. See IWC_Compat.
        $is_still_unreferenced = $bucket === 'unreferenced'
            && !IWC_Compat::has_custom_table_risk()
            && !self::has_external_reference($relative_base)
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
            return ['status' => 'trashed', 'bytes_saved' => max(0, $bytes_before - $bytes_after)];
        }

        // plain_content bucket: rewrite every size's URL in referencing posts,
        // but leave the original files in place until manually reviewed.
        $referencing_posts = self::find_referencing_posts($relative_base);
        $url_map = self::build_url_map(
            self::build_path_map($original_path, $webp_path, $old_metadata, $new_metadata)
        );

        $updated = [];
        $unresolved = [];
        foreach ($referencing_posts as $post) {
            $post_obj = get_post($post['ID']);
            if (!$post_obj) {
                continue;
            }
            $new_content = $url_map === []
                ? $post_obj->post_content
                : str_replace(array_keys($url_map), array_values($url_map), $post_obj->post_content);

            if ($new_content === $post_obj->post_content) {
                // Matched the reference scan but nothing was rewritable — the
                // reference is in a form this pass can't express (a relative
                // path, an encoded URL, a CDN host), or path_to_url() couldn't
                // resolve the uploads directory at all.
                $unresolved[] = ['post_id' => (int) $post['ID'], 'post_title' => $post['post_title']];
                continue;
            }

            $result = wp_update_post(['ID' => $post['ID'], 'post_content' => $new_content], true);
            if (!is_wp_error($result) && $result > 0) {
                $updated[] = ['post_id' => (int) $post['ID'], 'post_title' => $post['post_title']];
            } else {
                $unresolved[] = ['post_id' => (int) $post['ID'], 'post_title' => $post['post_title']];
            }
        }

        // Only offer the originals for cleanup when every reference we found
        // was actually rewritten. A post that matched the scan but came back
        // unchanged is a signal something wasn't understood — moving its
        // originals would break it, and the Cleanup Review screen rendered
        // that case identically to a genuine no-op, so the user had no way to
        // tell. 'references_failed' rows are reported, never finalised.
        $status = $unresolved === [] ? 'pending_cleanup' : 'references_failed';

        IWC_Logger::log_result($log_id, [
            'status'             => $status,
            'new_path'           => $webp_path,
            'bytes_after'        => $bytes_after,
            'references_updated' => wp_json_encode($updated),
            'message'            => $unresolved === []
                ? ''
                : wp_json_encode($unresolved),
            'old_files_json'     => $old_files_json,
        ]);

        return [
            'status'             => $status,
            'references_updated' => count($updated),
            'references_failed'  => count($unresolved),
        ];
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

    /**
     * Absolute path -> public URL, or '' when the path isn't inside the
     * uploads directory.
     *
     * Separators are normalised on both sides before comparing: get_attached_file()
     * and wp_get_upload_dir()['basedir'] don't always agree on slash direction
     * (Windows hosts, sites setting the UPLOADS constant, symlinked uploads).
     * The previous str_replace() approach silently returned the untouched
     * filesystem path when they disagreed, so every later str_replace() against
     * post content matched nothing while still reporting success — the originals
     * then got moved out from under content still pointing at them.
     *
     * Returning '' rather than a best guess is deliberate: callers treat it as
     * "this reference cannot be rewritten" and refuse the destructive step.
     */
    private static function path_to_url(string $path): string {
        $upload_dir = wp_get_upload_dir();
        $basedir = wp_normalize_path($upload_dir['basedir']);
        $normalized = wp_normalize_path($path);

        if ($basedir === '' || strpos($normalized, trailingslashit($basedir)) !== 0) {
            return '';
        }

        return trailingslashit($upload_dir['baseurl']) . ltrim(substr($normalized, strlen($basedir)), '/');
    }

    /**
     * Map every old file belonging to this attachment to the file that
     * replaces it, so content references to *any* size can be rewritten —
     * not just the full-size one.
     *
     * This matters because get_relative_base() deliberately searches without
     * an extension, so a post embedding "photo-300x200.jpg" counts as a
     * reference. Rewriting only the full-size URL left those posts matched
     * but untouched, and the thumbnail was then moved to the holding folder
     * anyway — a broken image with a "converted successfully" log row.
     *
     * Old sizes with no regenerated counterpart (and the pre-scaled
     * original_image, which never has one) fall back to the new full-size
     * file: a larger-than-ideal image still renders, a moved one does not.
     */
    private static function build_path_map(
        string $original_path,
        string $webp_path,
        array $old_metadata,
        array $new_metadata
    ): array {
        $map = [$original_path => $webp_path];
        $old_dir = trailingslashit(dirname($original_path));
        $new_dir = trailingslashit(dirname($webp_path));

        if (!empty($old_metadata['original_image'])) {
            $map[$old_dir . $old_metadata['original_image']] = $webp_path;
        }

        $new_sizes = isset($new_metadata['sizes']) && is_array($new_metadata['sizes'])
            ? $new_metadata['sizes']
            : [];
        $old_sizes = isset($old_metadata['sizes']) && is_array($old_metadata['sizes'])
            ? $old_metadata['sizes']
            : [];

        foreach ($old_sizes as $size_name => $size) {
            if (empty($size['file'])) {
                continue;
            }
            $map[$old_dir . $size['file']] = !empty($new_sizes[$size_name]['file'])
                ? $new_dir . $new_sizes[$size_name]['file']
                : $webp_path;
        }

        return $map;
    }

    /**
     * The path map expressed as old URL => new URL, longest old URL first so
     * a shorter URL that happens to be a substring of a longer one can never
     * corrupt it. Pairs whose old path isn't resolvable to a URL are dropped
     * — see path_to_url().
     */
    private static function build_url_map(array $path_map): array {
        $url_map = [];
        foreach ($path_map as $old_path => $new_path) {
            $old_url = self::path_to_url($old_path);
            $new_url = self::path_to_url($new_path);
            if ($old_url === '' || $new_url === '' || $old_url === $new_url) {
                continue;
            }
            $url_map[$old_url] = $new_url;

            // Block markup carries its attributes as JSON in the delimiter
            // comment -- <!-- wp:cover {"url":"https:\/\/..."} --> -- where
            // json_encode() has escaped every slash. A Cover block's
            // background URL lives only there, so without this pass it was
            // found by the reference scan and then left untouched.
            $old_escaped = str_replace('/', '\/', $old_url);
            if ($old_escaped !== $old_url) {
                $url_map[$old_escaped] = str_replace('/', '\/', $new_url);
            }
        }

        uksort($url_map, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        return $url_map;
    }

    /**
     * Every physical file belonging to this attachment (full-size + every
     * existing intermediate thumbnail), read before metadata regeneration
     * overwrites the size list.
     */
    private static function collect_attachment_files(int $attachment_id, string $original_path, ?array $metadata = null): array {
        $files = [$original_path];
        if ($metadata === null) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }
        if (!is_array($metadata)) {
            return $files;
        }
        $dir = trailingslashit(dirname($original_path));

        // WordPress 5.3+ downscales large uploads and keeps the untouched
        // full-resolution file as original_image, with get_attached_file()
        // pointing at the -scaled version. Missing it left the single largest
        // file on disk behind forever, uncounted in the space-saved figure.
        if (!empty($metadata['original_image'])) {
            $files[] = $dir . $metadata['original_image'];
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $files[] = $dir . $size['file'];
                }
            }
        }

        return array_values(array_unique($files));
    }

    /** Total bytes on disk for a set of files, skipping any that are gone. */
    private static function total_size(array $files): int {
        $total = 0;
        foreach ($files as $file) {
            $size = @filesize($file);
            if ($size !== false) {
                $total += (int) $size;
            }
        }
        return $total;
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

        // The holding folder mirrors the uploads path, so without this the
        // originals stay publicly fetchable at a guessable URL — the index.php
        // above only stops directory listing. Apache only; nginx hosts need
        // the equivalent location block, which the readme documents.
        if (!file_exists(trailingslashit($holding_root) . '.htaccess')) {
            @file_put_contents(
                trailingslashit($holding_root) . '.htaccess',
                "# Originals kept for review — not for public serving.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
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

        // Fall back to the holding root when nothing was actually moved (every
        // file already gone), so _iwc_backup_path still points somewhere real
        // rather than being stored as an empty string.
        return $destination_dir !== '' ? $destination_dir : $holding_root;
    }
}
