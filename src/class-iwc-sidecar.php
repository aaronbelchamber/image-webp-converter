<?php
/**
 * Sidecar mode: keep the original, serve WEBP alongside it.
 *
 * The replace mode this plugin started with has to find and rewrite every
 * reference to an image before it can move the original — and that problem has
 * no end. Page builders store layouts as JSON, TranslatePress and Slider
 * Revolution keep URLs in their own tables, themes hardcode paths in CSS
 * files, and Google Images and every CDN hold copies of URLs no database scan
 * can reach. Each of those is a separate special case, and the list only grows.
 *
 * Sidecar mode makes the question moot. photo.jpg stays exactly where it is;
 * photo.jpg.webp is written beside it, and the browser is offered the WEBP
 * through content negotiation. No URL changes, so nothing can break —
 * Elementor, hardcoded CSS, external links and plugins nobody has heard of all
 * keep working untouched, with no reference scanning involved at all.
 *
 * The cost is disk: both files exist. That is the trade, and it is why this is
 * a choice rather than the only behaviour.
 *
 * Naming appends rather than replaces the extension (photo.jpg.webp, not
 * photo.webp). It matches what the other WebP plugins do, so a host's existing
 * rewrite rules keep working, and it cannot collide the way photo.jpg and
 * photo.png both wanting photo.webp would.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Sidecar {

    const MODE_REPLACE = 'replace';
    const MODE_SIDECAR = 'sidecar';

    public static function register(): void {
        // Sidecars are generated from the metadata WordPress has just built,
        // which is the only point at which every intermediate size is known
        // and on disk. The filter returns metadata unchanged — this is a
        // side-effect hook, not a rewrite.
        add_filter('wp_generate_attachment_metadata', [__CLASS__, 'on_metadata_generated'], 20, 2);

        // Removing the attachment has to remove its sidecars, or they
        // accumulate as orphans nothing will ever clean up.
        add_action('delete_attachment', [__CLASS__, 'on_attachment_deleted']);

        add_filter('the_content', [__CLASS__, 'offer_webp_in_html'], 20);
        add_filter('post_thumbnail_html', [__CLASS__, 'offer_webp_in_html'], 20);
        add_filter('wp_get_attachment_image', [__CLASS__, 'offer_webp_in_html'], 20);
    }

    public static function mode(): string {
        $mode = get_option(IWC_OPTION_MODE, self::MODE_REPLACE);
        return $mode === self::MODE_SIDECAR ? self::MODE_SIDECAR : self::MODE_REPLACE;
    }

    public static function is_active(): bool {
        return self::mode() === self::MODE_SIDECAR && (bool) get_option(IWC_OPTION_ENABLED, true);
    }

    /** photo.jpg -> photo.jpg.webp, for a path or a URL alike. */
    public static function sidecar_for(string $path_or_url): string {
        return $path_or_url . '.webp';
    }

    /** Whether a path looks like something this plugin would convert. */
    private static function is_convertible(string $path): bool {
        return (bool) preg_match('/\.(jpe?g|png)$/i', $path);
    }

    /**
     * Generate a sidecar for the full-size file and every intermediate size.
     *
     * @param array $metadata      Attachment metadata, returned unchanged.
     * @param int   $attachment_id Attachment being processed.
     */
    public static function on_metadata_generated($metadata, $attachment_id) {
        if (!self::is_active() || !is_array($metadata)) {
            return $metadata;
        }

        $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
        foreach (self::files_for($attachment_id, $metadata) as $file) {
            self::generate($file, $quality);
        }

        return $metadata;
    }

    /**
     * Write one sidecar. Returns false when the source is not convertible,
     * conversion was refused, or an up-to-date sidecar already exists.
     */
    public static function generate(string $source_path, int $quality): bool {
        if (!self::is_convertible($source_path) || !file_exists($source_path)) {
            return false;
        }

        $sidecar = self::sidecar_for($source_path);

        // Skip when a sidecar is already there and no older than its source.
        // Re-encoding on every metadata regeneration would be pure waste, and
        // metadata gets regenerated far more often than images change.
        if (file_exists($sidecar) && @filemtime($sidecar) >= @filemtime($source_path)) {
            return false;
        }

        $mime = self::mime_for($source_path);
        if ($mime === '') {
            return false;
        }

        // Deliberately the same conversion path as replace mode, including the
        // "must be smaller" guard: a sidecar bigger than its source is a file
        // that costs disk and saves nothing, and the negotiation would then
        // serve the worse of the two.
        return iwc_convert_image_file_to_webp($source_path, $mime, $sidecar, $quality);
    }

    private static function mime_for(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'png') {
            return 'image/png';
        }
        return in_array($extension, ['jpg', 'jpeg', 'jpe'], true) ? 'image/jpeg' : '';
    }

    /**
     * Every physical file belonging to an attachment: full size, the
     * pre-scaled original where WordPress kept one, and each thumbnail.
     *
     * @return string[]
     */
    public static function files_for(int $attachment_id, ?array $metadata = null): array {
        $original = get_attached_file($attachment_id);
        if (empty($original)) {
            return [];
        }

        if ($metadata === null) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }

        $files = [$original];
        $dir = trailingslashit(dirname($original));

        if (is_array($metadata)) {
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
        }

        return array_values(array_unique($files));
    }

    /** Remove every sidecar belonging to an attachment. */
    public static function on_attachment_deleted($attachment_id): void {
        foreach (self::files_for((int) $attachment_id) as $file) {
            $sidecar = self::sidecar_for($file);
            if (file_exists($sidecar)) {
                @unlink($sidecar);
            }
        }
    }

    /**
     * Wrap <img> tags whose file has a sidecar in a <picture>, offering the
     * WEBP first and leaving the original as the fallback.
     *
     * This is the portable half of serving. A server-level rewrite on the
     * Accept header is better — it needs no markup change and covers CSS
     * backgrounds too — but it needs host configuration, and plenty of sites
     * cannot get it. <picture> works everywhere with no configuration at all,
     * and a browser without WEBP support simply ignores the source and uses
     * the img.
     */
    public static function offer_webp_in_html($html) {
        if (!is_string($html) || $html === '' || !self::is_active()) {
            return $html;
        }
        if (stripos($html, '<img') === false) {
            return $html;
        }

        return preg_replace_callback('/<img\s[^>]*>/i', static function ($match) use ($html) {
            $img = $match[0];

            // Leave anything already inside a <picture> alone — wrapping it
            // again would produce invalid markup and override an author's
            // deliberate art direction.
            if (self::is_inside_picture($html, $img)) {
                return $img;
            }

            $src = self::attribute($img, 'src');
            if ($src === '' || !self::is_convertible($src)) {
                return $img;
            }

            $webp_srcset = self::webp_srcset($img, $src);
            if ($webp_srcset === '') {
                return $img;
            }

            $sizes = self::attribute($img, 'sizes');
            $sizes_attr = $sizes !== '' ? ' sizes="' . esc_attr($sizes) . '"' : '';

            return '<picture><source srcset="' . esc_attr($webp_srcset) . '"' . $sizes_attr
                . ' type="image/webp">' . $img . '</picture>';
        }, $html);
    }

    /**
     * The srcset to offer as WEBP, built from the img's own srcset where it
     * has one so responsive images keep working, or from src alone otherwise.
     *
     * Any candidate without a sidecar on disk disqualifies the whole set: a
     * srcset that silently drops widths would have the browser pick a smaller
     * image than it should.
     */
    private static function webp_srcset(string $img, string $src): string {
        $srcset = self::attribute($img, 'srcset');
        $candidates = $srcset !== ''
            ? array_map('trim', explode(',', $srcset))
            : [$src];

        $out = [];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $candidate, 2);
            $url = $parts[0];
            $descriptor = isset($parts[1]) ? ' ' . $parts[1] : '';

            if (!self::is_convertible($url) || !self::sidecar_exists($url)) {
                return '';
            }
            $out[] = self::sidecar_for($url) . $descriptor;
        }

        return implode(', ', $out);
    }

    /** Whether the sidecar for a URL exists on disk. */
    private static function sidecar_exists(string $url): bool {
        $path = self::url_to_path($url);
        return $path !== '' && file_exists(self::sidecar_for($path));
    }

    /**
     * URL -> filesystem path, for uploads only.
     *
     * Anything outside the uploads directory — a CDN host, a theme asset, an
     * external image — returns '' and is left alone. Guessing a local path for
     * a remote URL would produce a <source> pointing at a file that is not
     * there.
     */
    private static function url_to_path(string $url): string {
        $upload_dir = wp_get_upload_dir();
        $baseurl = $upload_dir['baseurl'] ?? '';
        $basedir = $upload_dir['basedir'] ?? '';
        if ($baseurl === '' || $basedir === '') {
            return '';
        }

        // Protocol-relative and scheme-mismatched URLs are common in content
        // written before a move to HTTPS, so compare without the scheme.
        $strip = static function (string $value): string {
            return preg_replace('#^https?://#i', '//', $value);
        };

        $needle = trailingslashit($strip($baseurl));
        $candidate = $strip($url);

        if (strpos($candidate, $needle) !== 0) {
            return '';
        }

        return trailingslashit($basedir) . substr($candidate, strlen($needle));
    }

    /** Read one attribute out of a tag, '' when absent. */
    private static function attribute(string $tag, string $name): string {
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m)) {
            return $m[2];
        }
        return '';
    }

    /** Whether this exact img tag already sits inside a <picture> element. */
    private static function is_inside_picture(string $html, string $img): bool {
        $position = strpos($html, $img);
        if ($position === false) {
            return false;
        }
        $before = substr($html, 0, $position);
        $open = strripos($before, '<picture');
        if ($open === false) {
            return false;
        }
        $close = strripos($before, '</picture>');
        return $close === false || $close < $open;
    }
}
