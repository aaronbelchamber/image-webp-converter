<?php
/**
 * Admin UI: Settings / Convert Existing Images / Cleanup Review tabs.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_iwc_move_to_trash', [__CLASS__, 'handle_move_to_trash']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function register_menu(): void {
        add_options_page(
            'Image WebP Converter',
            'WebP Converter',
            'manage_options',
            'image-webp-converter',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting('iwc_settings', IWC_OPTION_QUALITY, [
            'type' => 'integer',
            'default' => 82,
            'sanitize_callback' => function ($value) {
                return max(0, min(100, (int) $value));
            },
        ]);
        register_setting('iwc_settings', IWC_OPTION_ENABLED, [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => function ($value) {
                return !empty($value);
            },
        ]);
    }

    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'settings_page_image-webp-converter') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        if ($tab !== 'convert') {
            return;
        }
        wp_enqueue_script(
            'iwc-bulk-convert',
            plugins_url('assets/bulk-convert.js', dirname(__FILE__)),
            [],
            IWC_VERSION,
            true
        );
        wp_localize_script('iwc-bulk-convert', 'iwcBulkConvert', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('iwc_bulk_convert'),
        ]);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1>Image WebP Converter</h1>

            <?php if (isset($_GET['iwc_message'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['iwc_message']))); ?></p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=image-webp-converter&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=image-webp-converter&tab=convert" class="nav-tab <?php echo $active_tab === 'convert' ? 'nav-tab-active' : ''; ?>">Convert Existing Images</a>
                <a href="?page=image-webp-converter&tab=cleanup" class="nav-tab <?php echo $active_tab === 'cleanup' ? 'nav-tab-active' : ''; ?>">Cleanup Review</a>
            </h2>

            <div style="margin-top:20px;">
                <?php
                if ($active_tab === 'convert') {
                    self::render_convert_tab();
                } elseif ($active_tab === 'cleanup') {
                    self::render_cleanup_tab();
                } else {
                    self::render_settings_tab();
                }
                ?>
            </div>

            <hr />
            <p style="color:#646970;">
                Built by <a href="https://belchamber.us" target="_blank" rel="noopener noreferrer">Aaron Belchamber</a> —
                more free WordPress tools at <a href="https://tools.belchamber.us" target="_blank" rel="noopener noreferrer">tools.belchamber.us</a>.
            </p>
        </div>
        <?php
    }

    private static function render_settings_tab(): void {
        $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
        $enabled = (bool) get_option(IWC_OPTION_ENABLED, true);
        $gd_ok = extension_loaded('gd');
        ?>
        <?php if (!$gd_ok) : ?>
            <div class="notice notice-error"><p>The PHP GD extension is not enabled on this server — image conversion is currently inactive. Ask your host to enable it.</p></div>
        <?php endif; ?>

        <p>Uploads are converted to WebP automatically — nothing else to do. Transparent PNGs are always encoded at quality <?php echo (int) IWC_MIN_QUALITY_FOR_ALPHA; ?> or higher, regardless of the setting below, to avoid jagged edges.</p>

        <form method="post" action="options.php">
            <?php settings_fields('iwc_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Convert uploads automatically</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(IWC_OPTION_ENABLED); ?>" value="1" <?php checked($enabled); ?> />
                            Convert new JPG/PNG uploads to WebP automatically
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="iwc_quality">WEBP Quality</label></th>
                    <td>
                        <input type="number" min="0" max="100" step="1" id="iwc_quality" name="<?php echo esc_attr(IWC_OPTION_QUALITY); ?>" value="<?php echo esc_attr($quality); ?>" class="small-text" />
                        <p class="description">0 (smallest file) – 100 (best quality). 82 is a good default. Used for both new uploads and the bulk converter below.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private static function render_convert_tab(): void {
        ?>
        <p>Scans your Media Library for JPG/PNG images and converts what's safe to convert automatically. Images already used somewhere are handled carefully — see the summary below before starting.</p>
        <div id="iwc-bulk-convert-app">
            <button type="button" id="iwc-scan-button" class="button button-primary">Scan Media Library</button>
            <div id="iwc-scan-summary" style="margin-top:16px;"></div>
            <div id="iwc-progress-wrap" style="display:none; margin-top:16px;">
                <progress id="iwc-progress-bar" value="0" max="100" style="width:100%;"></progress>
                <p id="iwc-progress-text"></p>
            </div>
            <div id="iwc-final-summary" style="margin-top:16px;"></div>
        </div>
        <?php
    }

    private static function render_cleanup_tab(): void {
        $rows = IWC_Logger::get_pending_cleanup();
        ?>
        <p>These images were converted and their content references were updated, but the original files are still on disk pending your review. Select any you're confident about and move them out of the way.</p>

        <?php if (empty($rows)) : ?>
            <p><em>Nothing pending cleanup right now.</em></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="iwc_move_to_trash" />
                <?php wp_nonce_field('iwc_move_to_trash'); ?>

                <p>
                    <button type="button" class="button" onclick="document.querySelectorAll('.iwc-cleanup-checkbox').forEach(c => c.checked = true);">Select All</button>
                    <button type="button" class="button" onclick="document.querySelectorAll('.iwc-cleanup-checkbox').forEach(c => c.checked = false);">Deselect All</button>
                </p>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:24px;"></th>
                            <th>File</th>
                            <th>Space to reclaim</th>
                            <th>Fixed in</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) :
                            $saved = max(0, (int) $row->bytes_before - (int) $row->bytes_after);
                            $refs = json_decode((string) $row->references_updated, true);
                            ?>
                            <tr>
                                <td><input type="checkbox" class="iwc-cleanup-checkbox" name="log_ids[]" value="<?php echo esc_attr($row->id); ?>" /></td>
                                <td><?php echo esc_html(basename($row->original_path)); ?></td>
                                <td><?php echo esc_html(size_format($saved)); ?></td>
                                <td>
                                    <?php
                                    if (is_array($refs) && !empty($refs)) {
                                        $titles = array_map(function ($r) { return $r['post_title'] ?? ('#' . $r['post_id']); }, $refs);
                                        echo esc_html(implode(', ', $titles));
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:16px;">
                    <button type="submit" class="button button-primary" onclick="return confirm('Move the selected original files to the holding folder? They will remain on disk (not deleted) but out of the live uploads path.');">Move Selected to Trash</button>
                </p>
            </form>
        <?php endif; ?>
        <?php
    }

    public static function handle_move_to_trash(): void {
        check_admin_referer('iwc_move_to_trash');
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $log_ids = isset($_POST['log_ids']) ? array_map('intval', (array) $_POST['log_ids']) : [];
        $moved = 0;
        foreach ($log_ids as $log_id) {
            if (IWC_Bulk_Converter::finalize_cleanup($log_id)) {
                $moved++;
            }
        }

        $redirect = add_query_arg([
            'page' => 'image-webp-converter',
            'tab' => 'cleanup',
            'iwc_message' => rawurlencode(sprintf('%d image(s) moved to the holding folder.', $moved)),
        ], admin_url('options-general.php'));

        wp_safe_redirect($redirect);
        exit;
    }
}
