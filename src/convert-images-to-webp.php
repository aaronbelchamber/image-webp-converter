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
 * Whether a PNG file has any transparency.
 *
 * The colour-type byte in the IHDR chunk (offset 25) settles it for the
 * common cases in O(1), without decoding a single pixel: type 6 is
 * truecolour+alpha and type 4 is greyscale+alpha, which covers anything
 * exported "with transparency" from Photoshop/GIMP/Figma/Canva.
 *
 * The other three types can still be transparent by carrying a tRNS chunk —
 * a palette of per-index alpha for indexed images (type 3), or a single
 * colour declared transparent for greyscale/truecolour (types 0 and 2).
 * That's how GD writes a palette image with imagecolortransparent(), so it
 * turns up in anything routed through GD, not just old files. Missing it
 * meant those images skipped the alpha quality floor and got encoded with
 * visible fringing around the transparent edges.
 */
function iwc_png_has_alpha(string $file_path): bool {
    $handle = @fopen($file_path, 'rb');
    if ($handle === false) {
        return false;
    }

    $header = fread($handle, 26);
    if ($header === false || strlen($header) < 26) {
        fclose($handle);
        return false;
    }

    $color_type = ord($header[25]);
    if (in_array($color_type, [4, 6], true)) {
        fclose($handle);
        return true;
    }

    $has_trns = iwc_png_has_trns_chunk($handle);
    fclose($handle);

    return $has_trns;
}

/**
 * Scan a PNG's chunk table for a tRNS chunk, with the handle positioned just
 * past the IHDR chunk's data.
 *
 * Chunks are [4-byte big-endian length][4-byte type][data][4-byte CRC]. Only
 * the headers are read and the data is seeked over, so this stays cheap
 * regardless of image size. The spec requires tRNS to precede the first IDAT,
 * so the search stops there rather than walking the whole pixel payload.
 */
function iwc_png_has_trns_chunk($handle): bool {
    // The 26 bytes already consumed cover the signature (8), IHDR's length
    // and type (8), and 10 of IHDR's 13 data bytes.
    if (fseek($handle, 33) !== 0) {
        return false;
    }

    // Bounded rather than while(true): a corrupt length field could otherwise
    // spin here, and no real PNG has anything like this many chunks before
    // its first IDAT.
    for ($i = 0; $i < 1024; $i++) {
        $chunk_header = fread($handle, 8);
        if ($chunk_header === false || strlen($chunk_header) < 8) {
            return false;
        }

        $length = unpack('N', substr($chunk_header, 0, 4))[1];
        $type = substr($chunk_header, 4, 4);

        if ($type === 'tRNS') {
            return true;
        }
        if ($type === 'IDAT' || $type === 'IEND') {
            return false;
        }

        // +4 to step over the chunk's trailing CRC as well as its data.
        if (fseek($handle, $length + 4, SEEK_CUR) !== 0) {
            return false;
        }
    }

    return false;
}

/**
 * Resolve a non-colliding target .webp path for a given source image path,
 * using WordPress's own wp_unique_filename() so a same-named .webp from an
 * unrelated prior upload is never silently overwritten.
 */
function iwc_resolve_webp_target_path(string $source_path): string {
    $dir = dirname($source_path);
    $basename = basename($source_path);

    // Strip whatever extension is actually there rather than matching a fixed
    // list. WordPress accepts .jpe for image/jpeg (and hosts see .jfif in the
    // wild), so an allow-list regex left the original extension in place and
    // wrote WEBP bytes into a file still named .jpe — which the upload array
    // then labelled image/webp, a mismatch WordPress rejects downstream.
    $stem = preg_replace('/\.[^.\/\\\\]+$/', '', $basename);
    $webp_name = ($stem === '' ? $basename : $stem) . '.webp';

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
        //
        // Forced to truecolour on the way out: ImageMagick writes a palette
        // PNG when the image has few enough distinct colours, and imagewebp()
        // cannot encode a palette image at all -- it emits "Palette image not
        // supported by webp" and leaves a zero-byte file. Any CMYK image with
        // flat or limited colour (a logo, a print-ready graphic, a solid
        // background) hit that, which is a large share of what arrives as CMYK
        // in the first place.
        $imagick->setImageType(Imagick::IMGTYPE_TRUECOLOR);
        $imagick->setImageFormat('png');
        $blob = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        $image = @imagecreatefromstring($blob);
        if ($image === false) {
            return false;
        }

        // Belt and braces: setImageType() covers the encoder side, this covers
        // whatever GD decided to build on the decoder side.
        imagepalettetotruecolor($image);

        return $image;
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

    // Only PNG sources are worth a lossless attempt. A JPEG is already lossy,
    // so re-encoding it losslessly means faithfully preserving compression
    // artefacts at great expense — it loses on size every time.
    $ok = iwc_encode_webp($image, $webp_path, $quality, $mime_type === 'image/png');
    // imagedestroy() is a deprecated no-op as of PHP 8.0 (GD images are
    // garbage-collected automatically) but still does real cleanup on the
    // PHP 7.4 minimum this plugin supports — guard the call so PHP 8+ hosts
    // don't log a deprecation notice for a call that's still correct there.
    if (PHP_VERSION_ID < 80000) {
        imagedestroy($image);
    }

    if (!$ok) {
        // A failed imagewebp() can still have created (and left behind) an
        // empty or truncated file at the target path.
        @unlink($webp_path);
        return false;
    }

    return iwc_accept_webp_output($source_path, $webp_path);
}

/**
 * Encode a loaded GD image to $webp_path, keeping whichever of lossy and
 * lossless comes out smaller.
 *
 * Which one wins depends on the picture, and nothing about the file says
 * which up front. A logo, icon, screenshot or flat graphic — most of what
 * arrives as a PNG on a website — compresses dramatically smaller lossless,
 * frequently smaller than the source PNG itself. A photograph saved as PNG is
 * the opposite, where lossless can be several times larger. So both are
 * encoded and the smaller is kept.
 *
 * For transparent images this is what makes them convertible at all: at the
 * alpha quality floor they routinely encode larger than the PNG they would
 * replace, and get rejected outright by the size guard.
 *
 * Restricted to PNG sources by the caller, so the doubled encode cost never
 * lands on the JPEG uploads that make up the bulk of most libraries.
 *
 * IMG_WEBP_LOSSLESS needs PHP 8.1; on 7.4 and 8.0 this is a plain lossy
 * encode.
 */
function iwc_encode_webp($image, string $webp_path, int $quality, bool $allow_lossless): bool {
    /**
     * Filters whether a lossless encode is attempted alongside the lossy one.
     *
     * @param bool $try_lossless Whether to try lossless. Default true.
     * @param int  $quality      The lossy quality that would otherwise be used.
     */
    $try_lossless = $allow_lossless
        && defined('IMG_WEBP_LOSSLESS')
        && apply_filters('iwc_try_lossless', true, $quality);

    if (!$try_lossless) {
        return (bool) @imagewebp($image, $webp_path, $quality);
    }

    if (!@imagewebp($image, $webp_path, $quality)) {
        return false;
    }
    $lossy_bytes = @filesize($webp_path);

    $lossless_path = $webp_path . '.lossless.tmp';
    if (!@imagewebp($image, $lossless_path, IMG_WEBP_LOSSLESS)) {
        @unlink($lossless_path);
        return true; // The lossy encode already succeeded; keep it.
    }

    $lossless_bytes = @filesize($lossless_path);

    if ($lossless_bytes !== false && $lossy_bytes !== false && $lossless_bytes < $lossy_bytes) {
        if (@rename($lossless_path, $webp_path)) {
            return true;
        }
    }

    @unlink($lossless_path);
    return true;
}

/**
 * Decide whether a freshly-written .webp is actually worth keeping, deleting
 * it and reporting failure if not.
 *
 * WEBP is not unconditionally smaller. Re-encoding an already well-optimised
 * JPEG, or a flat-colour PNG that PNG's own compression handles well, often
 * produces a *larger* file — so converting unconditionally could grow a
 * page's weight while reporting a saving. The caller treats false the same as
 * any other skipped conversion: the original is left exactly as it was.
 *
 * Filterable for sites that want WEBP for consistency regardless of size.
 */
function iwc_accept_webp_output(string $source_path, string $webp_path): bool {
    /**
     * Filters whether a converted WEBP must be smaller than its source to be kept.
     *
     * @param bool   $require_smaller Whether to require a size reduction. Default true.
     * @param string $source_path     Absolute path to the source image.
     * @param string $webp_path       Absolute path to the generated WEBP.
     */
    if (!apply_filters('iwc_require_smaller_output', true, $source_path, $webp_path)) {
        return true;
    }

    $webp_bytes = @filesize($webp_path);
    $source_bytes = @filesize($source_path);

    if ($webp_bytes === false || $webp_bytes === 0) {
        @unlink($webp_path);
        return false;
    }

    // An unreadable source is not evidence the conversion was bad; keep it.
    if ($source_bytes === false || $source_bytes === 0) {
        return true;
    }

    if ($webp_bytes >= $source_bytes) {
        @unlink($webp_path);
        return false;
    }

    return true;
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
 * Remember, or recall, the image metadata read from a source file before it
 * was replaced by its WEBP version.
 *
 * Conversion happens in the wp_handle_upload filter, which runs before
 * WordPress creates the attachment and calls wp_read_image_metadata() — by
 * which point the JPEG carrying the EXIF/IPTC is gone and WEBP cannot supply
 * it. Without this, every automatic caption, title, credit, copyright and
 * camera/timestamp record was silently dropped on upload, which for anyone
 * uploading photographs is real data loss rather than an optimisation.
 *
 * A request-scoped static is sufficient and correct here: media_handle_upload()
 * creates the attachment in the same request that ran the upload filter, so
 * the value is always written and read within one page load. Persisting it
 * would only leave cruft behind when an upload fails partway.
 *
 * @param array|null $meta Pass an array to store; omit to retrieve.
 * @return array|null
 */
function iwc_remembered_image_meta(string $path, ?array $meta = null): ?array {
    static $store = [];

    if ($meta !== null) {
        $store[$path] = $meta;
        return $meta;
    }

    return $store[$path] ?? null;
}

/**
 * Read a source image's EXIF/IPTC through WordPress's own reader, so what we
 * stash is exactly what WordPress would have recorded itself.
 */
function iwc_read_source_image_metadata(string $source_path): ?array {
    if (!function_exists('wp_read_image_metadata')) {
        $image_include = ABSPATH . 'wp-admin/includes/image.php';
        if (!file_exists($image_include)) {
            return null;
        }
        require_once $image_include;
    }

    if (!function_exists('wp_read_image_metadata')) {
        return null;
    }

    $meta = wp_read_image_metadata($source_path);

    return is_array($meta) ? $meta : null;
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

    // Read this before the source is deleted below — it's the last moment the
    // EXIF/IPTC still exists.
    $image_meta = iwc_read_source_image_metadata($upload['file']);

    if (!iwc_convert_image_file_to_webp($upload['file'], $mime_type, $webp_path, $quality)) {
        return $upload;
    }

    if ($image_meta !== null) {
        iwc_remembered_image_meta($webp_path, $image_meta);
    }

    @unlink($upload['file']);

    $upload['file'] = $webp_path;
    $upload['url'] = trailingslashit(dirname($upload['url'])) . basename($webp_path);
    $upload['type'] = 'image/webp';

    return $upload;
}
