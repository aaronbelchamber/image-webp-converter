<?php
/**
 * Core conversion logic for Image WebP Converter.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/** Minimum WEBP quality ever used for images with an alpha channel, regardless of the configured setting. */
const IWC_MIN_QUALITY_FOR_ALPHA = 80;

/**
 * Whether a PNG file has an alpha channel, checked via the color-type byte
 * in the file's IHDR chunk (offset 25) rather than decoding pixels — O(1)
 * regardless of image size. Color type 6 = truecolor+alpha, 4 = grayscale
 * +alpha; these cover the vast majority of real transparent PNGs (anything
 * exported "with transparency" from Photoshop/GIMP/Figma/Canva). Known gap:
 * older indexed PNGs using a tRNS chunk for transparency (color type 3)
 * aren't detected here — rare on the modern web, not worth the extra chunk
 * parsing this would require.
 */
function iwc_png_has_alpha(string $file_path): bool {
    $handle = @fopen($file_path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 26);
    fclose($handle);
    if ($header === false || strlen($header) < 26) {
        return false;
    }
    $color_type = ord($header[25]);
    return in_array($color_type, [4, 6], true);
}

/**
 * Resolve a non-colliding target .webp path for a given source image path,
 * using WordPress's own wp_unique_filename() so a same-named .webp from an
 * unrelated prior upload is never silently overwritten.
 */
function iwc_resolve_webp_target_path(string $source_path): string {
    $dir = dirname($source_path);
    $webp_name = preg_replace('/\.(jpe?g|png)$/i', '.webp', basename($source_path));
    $unique_name = wp_unique_filename($dir, $webp_name);
    return trailingslashit($dir) . $unique_name;
}

/**
 * Whether the current environment can safely produce a WEBP that WordPress
 * can also read back (needed for wp_generate_attachment_metadata() to build
 * intermediate thumbnail sizes from the converted file). Some hosts have a
 * GD build that can encode WEBP but not decode it, which would otherwise
 * silently leave an image with no responsive sizes.
 */
function iwc_environment_supports_webp(): bool {
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        return false;
    }
    if (function_exists('wp_image_editor_supports')) {
        return wp_image_editor_supports(['mime_type' => 'image/webp']);
    }
    return function_exists('imagecreatefromwebp');
}

/**
 * Load a CMYK JPEG and return it as a GD truecolor (RGB) resource, using
 * Imagick's colorspace transform for a correct conversion — GD/libjpeg
 * alone frequently inverts colors on Adobe-tagged CMYK JPEGs (the common
 * case for anything exported from Photoshop or a print workflow). Returns
 * false if Imagick isn't available or the conversion fails for any reason;
 * callers should treat that as "skip this image," not "fall back to GD."
 *
 * @return \GdImage|resource|false
 */
function iwc_load_cmyk_jpeg_as_rgb(string $file_path) {
    if (!class_exists('Imagick')) {
        return false;
    }

    try {
        $imagick = new Imagick($file_path);
        if ($imagick->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        // Round-trip through a lossless intermediate so GD (which has no
        // CMYK awareness at all) only ever sees already-correct RGB data.
        $imagick->setImageFormat('png');
        $blob = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        return @imagecreatefromstring($blob);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Convert a single image file to WEBP at $webp_path.
 *
 * Pure file-in/file-out conversion, shared by the per-upload hook and the
 * bulk "convert existing images" feature so both get the same hardening.
 *
 * @param string $source_path Absolute path to the source JPG/JPEG/PNG file.
 * @param string $mime_type   Already-validated MIME type ('image/jpeg' or 'image/png').
 * @param string $webp_path   Absolute path to write the resulting .webp file to.
 * @param int    $quality     WEBP quality, 0 (smallest/lowest) - 100 (largest/best).
 * @return bool True on success, false if conversion was skipped or failed
 *              (in every case, the source file is left untouched).
 */
function iwc_convert_image_file_to_webp(string $source_path, string $mime_type, string $webp_path, int $quality): bool {
    if (!in_array($mime_type, ['image/jpeg', 'image/png'], true)) {
        return false;
    }

    if (!iwc_environment_supports_webp()) {
        return false;
    }

    $size_info = @getimagesize($source_path);
    if ($size_info === false) {
        return false;
    }
    [$width, $height] = $size_info;
    $channels = $size_info['channels'] ?? 3;
    $is_cmyk_jpeg = ($mime_type === 'image/jpeg' && $channels === 4);

    // Web images should never be CMYK. GD alone can't be trusted to convert
    // it correctly — it's a known source of inverted colors on Adobe-tagged
    // CMYK JPEGs (virtually all CMYK JPEGs from Photoshop/print exports).
    // Imagick handles the colorspace transform correctly when available; if
    // it isn't, skip conversion (keep the original) rather than risk
    // shipping a color-inverted image — a skipped conversion is safe, a
    // silently wrong-colored one live on the site is not.
    if ($is_cmyk_jpeg && !class_exists('Imagick')) {
        return false;
    }

    // Rough decoded-memory estimate (4 bytes/pixel truecolor + ~1.8x GD
    // working-memory overhead) vs. available memory_limit. Skip rather than
    // risk a fatal "Allowed memory size exhausted" mid-request.
    $estimated_bytes = $width * $height * 4 * 1.8;
    $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    if ($memory_limit > 0 && $estimated_bytes > ($memory_limit - memory_get_usage(true))) {
        return false;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }

    $quality = max(0, min(100, $quality));

    if ($is_cmyk_jpeg) {
        $image = iwc_load_cmyk_jpeg_as_rgb($source_path);
        if ($image !== false) {
            iwc_apply_exif_orientation($image, $source_path);
        }
    } elseif ($mime_type === 'image/jpeg') {
        $image = @imagecreatefromjpeg($source_path);
        if ($image !== false) {
            iwc_apply_exif_orientation($image, $source_path);
        }
    } else {
        $image = @imagecreatefrompng($source_path);
        if ($image !== false) {
            imagepalettetotruecolor($image);
            // No drawing happens between load and encode, so this flag has
            // no visible effect today either way — but `false` is the
            // correct, defensively-safe setting for an exact alpha copy.
            // Leave it false even if someone adds a resize/crop step later;
            // `true` would start alpha-compositing new pixels onto an
            // opaque background and corrupt transparency.
            imagealphablending($image, false);
            imagesavealpha($image, true);

            if (iwc_png_has_alpha($source_path)) {
                $quality = max($quality, IWC_MIN_QUALITY_FOR_ALPHA);
            }
        }
    }

    if ($image === false) {
        return false;
    }

    $ok = imagewebp($image, $webp_path, $quality);
    // imagedestroy() is a deprecated no-op as of PHP 8.0 (GD images are
    // garbage-collected automatically) but still does real cleanup on the
    // PHP 7.4 minimum this plugin supports — guard the call so PHP 8+ hosts
    // don't log a deprecation notice for a call that's still correct there.
    if (PHP_VERSION_ID < 80000) {
        imagedestroy($image);
    }

    return $ok;
}

/**
 * Rotate/flip a loaded JPEG GD image in place to match its EXIF Orientation
 * tag, so the pixels are correct before encoding (WEBP doesn't reliably
 * carry the orientation tag the way browsers honor it for JPEG). No-op if
 * ext-exif isn't available or the file has no orientation data.
 */
function iwc_apply_exif_orientation(&$image, string $source_path): void {
    if (!function_exists('exif_read_data')) {
        return;
    }

    $exif = @exif_read_data($source_path);
    if (!$exif || empty($exif['Orientation'])) {
        return;
    }

    switch ((int) $exif['Orientation']) {
        case 2:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $image = imagerotate($image, 180, 0);
            break;
        case 4:
            imageflip($image, IMG_FLIP_VERTICAL);
            break;
        case 5:
            imageflip($image, IMG_FLIP_VERTICAL);
            $image = imagerotate($image, -90, 0);
            break;
        case 6:
            $image = imagerotate($image, -90, 0);
            break;
        case 7:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $image = imagerotate($image, -90, 0);
            break;
        case 8:
            $image = imagerotate($image, 90, 0);
            break;
    }
}

/**
 * Convert a single uploaded JPG/JPEG/PNG file to WEBP in place and rewrite
 * the upload array so WordPress stores/serves the WEBP version.
 *
 * Animated GIFs are never handled here (GIF was never in the allowed MIME
 * list): GD's imagewebp() only encodes a single frame, so "converting" an
 * animated GIF would silently flatten it to a static image — a
 * data-destructive surprise for a "conversion" tool. If animated support is
 * ever wanted, it needs Imagick frame-coalescing as a distinct feature, not
 * a change to this GD-based path.
 *
 * @param array $upload  The array passed through the wp_handle_upload filter.
 * @param int   $quality  WEBP quality, 0 (smallest/lowest) - 100 (largest/best).
 * @return array          The (possibly rewritten) upload array.
 */
function iwc_convert_upload_to_webp(array $upload, int $quality = 82): array {
    $mime_type = $upload['type'] ?? '';
    if (empty($mime_type)) {
        // Fall back to extension only if WordPress didn't already validate a
        // type — and only for extensions we actually handle. Anything else
        // is simply not eligible, never guessed at as JPEG.
        $extension = strtolower(pathinfo($upload['file'], PATHINFO_EXTENSION));
        if ($extension === 'png') {
            $mime_type = 'image/png';
        } elseif (in_array($extension, ['jpg', 'jpeg'], true)) {
            $mime_type = 'image/jpeg';
        }
    }

    if (!in_array($mime_type, ['image/jpeg', 'image/png'], true)) {
        return $upload;
    }

    $webp_path = iwc_resolve_webp_target_path($upload['file']);

    if (!iwc_convert_image_file_to_webp($upload['file'], $mime_type, $webp_path, $quality)) {
        return $upload;
    }

    @unlink($upload['file']);

    $upload['file'] = $webp_path;
    $upload['url'] = trailingslashit(dirname($upload['url'])) . basename($webp_path);
    $upload['type'] = 'image/webp';

    return $upload;
}
