<?php
/**
 * Plugin Name:       Image WebP Converter
 * Plugin URI:        https://tools.belchamber.us/image-webp-converter
 * Description:       Automatically converts newly uploaded JPG/JPEG/PNG images to WEBP to cut file size and speed up your site, and can safely convert your existing Media Library too. Zero config required.
 * Version:           1.6.0
 * Requires PHP:      7.4
 * Author:            Aaron Belchamber
 * Author URI:        https://belchamber.us
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-webp-converter
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('IWC_VERSION', '1.6.0');
define('IWC_OPTION_QUALITY', 'iwc_quality');
define('IWC_OPTION_ENABLED', 'iwc_enabled');
define('IWC_PLUGIN_FILE', __FILE__);

require_once plugin_dir_path(__FILE__) . 'src/convert-images-to-webp.php';
require_once plugin_dir_path(__FILE__) . 'src/class-iwc-compat.php';
require_once plugin_dir_path(__FILE__) . 'src/class-iwc-lock.php';
require_once plugin_dir_path(__FILE__) . 'src/class-iwc-db.php';
require_once plugin_dir_path(__FILE__) . 'src/class-iwc-logger.php';
require_once plugin_dir_path(__FILE__) . 'src/class-iwc-bulk-converter.php';
require_once plugin_dir_path(__FILE__) . 'src/class-iwc-ajax.php';
require_once plugin_dir_path(__FILE__) . 'src/class-iwc-admin.php';

register_activation_hook(__FILE__, ['IWC_DB', 'activate']);
add_action('admin_init', ['IWC_DB', 'maybe_upgrade']);

IWC_Admin::init();
IWC_Ajax::register();

// The browser bulk converter is bounded by what an admin-ajax request can
// survive; on a large library WP-CLI is the tool that actually finishes.
if (defined('WP_CLI') && WP_CLI) {
    require_once plugin_dir_path(__FILE__) . 'src/class-iwc-cli.php';
    IWC_CLI::register();
}

/**
 * Hook into every upload and convert eligible images to WEBP, unless
 * disabled via the settings toggle or the iwc_skip_conversion filter.
 */
add_filter('wp_handle_upload', function (array $upload): array {
    if (!get_option(IWC_OPTION_ENABLED, true)) {
        return $upload;
    }

    /**
     * Filters whether a given upload should skip WebP conversion.
     *
     * @param bool  $skip   Whether to skip conversion. Default false.
     * @param array $upload The upload array passed through wp_handle_upload.
     */
    if (apply_filters('iwc_skip_conversion', false, $upload)) {
        return $upload;
    }

    $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
    return iwc_convert_upload_to_webp($upload, $quality);
});

/**
 * Hand WordPress the EXIF/IPTC read from the original file, since by the time
 * it asks for it the source has been replaced by a WEBP that cannot carry it.
 * Without this, captions, credits, copyright and camera data were dropped on
 * every converted upload.
 */
add_filter('wp_read_image_metadata', function ($meta, $file) {
    $remembered = iwc_remembered_image_meta((string) $file);
    return is_array($remembered) ? $remembered : $meta;
}, 10, 2);
