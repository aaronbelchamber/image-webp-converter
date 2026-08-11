<?php
/**
 * Core conversion logic for Image WebP Converter.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Convert a single uploaded JPG/JPEG/PNG file to WEBP in place and rewrite
 * the upload array so WordPress stores/serves the WEBP version.
 *
 * @param array $upload  The array passed through the wp_handle_upload filter.
 * @param int   $quality  WEBP quality, 0 (smallest/lowest) - 100 (largest/best).
 * @return array          The (possibly rewritten) upload array.
 */
function iwc_convert_upload_to_webp(array $upload, int $quality = 82): array {
    $file_path = $upload['file'];
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png'];

    if (!in_array($extension, $allowed, true)) {
        return $upload;
    }

    if (!extension_loaded('gd')) {
        return $upload;
    }

    $quality = max(0, min(100, $quality));
    $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file_path);

    if ($extension === 'jpg' || $extension === 'jpeg') {
        $image = @imagecreatefromjpeg($file_path);
    } else {
        $image = @imagecreatefrompng($file_path);
        if ($image !== false) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }
    }

    if ($image === false) {
        return $upload;
    }

    if (imagewebp($image, $webp_path, $quality)) {
        imagedestroy($image);
        @unlink($file_path);

        $upload['file'] = $webp_path;
        $upload['url'] = preg_replace('/\.(jpe?g|png)$/i', '.webp', $upload['url']);
        $upload['type'] = 'image/webp';
    } else {
        imagedestroy($image);
    }

    return $upload;
}
