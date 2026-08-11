<?php
/**
 * Plugin Name:       Image WebP Converter
 * Plugin URI:        https://tools.belchamber.us/image-webp-converter
 * Description:       Automatically converts newly uploaded JPG/JPEG/PNG images to WEBP to cut file size and speed up your site. Zero config required.
 * Version:           1.1.0
 * Requires PHP:      7.0
 * Author:            Aaron Belchamber
 * Author URI:        https://belchamber.us
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-webp-converter
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('IWC_VERSION', '1.1.0');
define('IWC_OPTION_QUALITY', 'iwc_quality');

require_once plugin_dir_path(__FILE__) . 'src/convert-images-to-webp.php';

/**
 * Hook into every upload and convert eligible images to WEBP.
 */
add_filter('wp_handle_upload', function (array $upload): array {
    $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
    return iwc_convert_upload_to_webp($upload, $quality);
});

/**
 * Settings page under Settings > WebP Converter.
 */
add_action('admin_menu', function (): void {
    add_options_page(
        'Image WebP Converter',
        'WebP Converter',
        'manage_options',
        'image-webp-converter',
        'iwc_render_settings_page'
    );
});

add_action('admin_init', function (): void {
    register_setting('iwc_settings', IWC_OPTION_QUALITY, [
        'type' => 'integer',
        'default' => 82,
        'sanitize_callback' => function ($value) {
            return max(0, min(100, (int) $value));
        },
    ]);
});

function iwc_render_settings_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
    $gd_ok = extension_loaded('gd');
    ?>
    <div class="wrap">
        <h1>Image WebP Converter</h1>

        <?php if (!$gd_ok) : ?>
            <div class="notice notice-error"><p>The PHP GD extension is not enabled on this server — image conversion is currently inactive. Ask your host to enable it.</p></div>
        <?php endif; ?>

        <p>New JPG/JPEG/PNG uploads are converted to WEBP automatically. Nothing else to do.</p>

        <form method="post" action="options.php">
            <?php settings_fields('iwc_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="iwc_quality">WEBP Quality</label></th>
                    <td>
                        <input type="number" min="0" max="100" step="1" id="iwc_quality" name="<?php echo esc_attr(IWC_OPTION_QUALITY); ?>" value="<?php echo esc_attr($quality); ?>" class="small-text" />
                        <p class="description">0 (smallest file) – 100 (best quality). 82 is a good default.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr />
        <p style="color:#646970;">
            Built by <a href="https://belchamber.us" target="_blank" rel="noopener noreferrer">Aaron Belchamber</a> —
            more free WordPress tools at <a href="https://tools.belchamber.us" target="_blank" rel="noopener noreferrer">tools.belchamber.us</a>.
        </p>
    </div>
    <?php
}
