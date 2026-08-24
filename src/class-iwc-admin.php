<?php
/**
 * Admin UI: Settings / Convert Existing Images / Cleanup Review tabs.
 *
 * Every user-facing string here goes through the translation functions with
 * the 'image-webp-converter' text domain — which matches the plugin slug, as
 * WordPress.org requires so translate.wordpress.org can pick it up. Strings
 * that carry a placeholder get a translators: comment, because a bare "%s" in
 * a translation file is unguessable out of context.
 *
 * Escaping stays at the point of output (esc_html__ rather than __), so a
 * translation cannot introduce markup the original did not have.
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
            __('Image WebP Converter', 'image-webp-converter'),
            __('WebP Converter', 'image-webp-converter'),
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

        // The script's user-facing text is translated here and handed over,
        // rather than living as literals in the JS. wp.i18n would be the
        // alternative, but it needs a build step to extract strings from
        // JavaScript, and this plugin deliberately ships no build tooling.
        wp_localize_script('iwc-bulk-convert', 'iwcBulkConvert', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('iwc_bulk_convert'),
            'i18n'    => self::script_strings(),
        ]);
    }

    /**
     * Translated strings for assets/bulk-convert.js.
     *
     * Anything with a number in it is passed as a placeholder string for the
     * script to fill, so translators keep control of word order — several
     * languages will not tolerate the count being hardcoded at the front.
     *
     * @return array<string,string>
     */
    private static function script_strings(): array {
        return [
            'scanning'          => __('Scanning…', 'image-webp-converter'),
            /* translators: 1: images checked so far, 2: total images to check. */
            'scanningProgress'  => __('Scanning… %1$s / %2$s images checked', 'image-webp-converter'),
            /* translators: %s: number of images checked so far. */
            'scanningCount'     => __('Scanning… %s images checked', 'image-webp-converter'),
            'scanFailed'        => __('Scan failed. Nothing was changed.', 'image-webp-converter'),
            /* translators: %s: the underlying error message. */
            'scanFailedReason'  => __('Scan failed: %s. Nothing was changed.', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'readyNow'          => __('%s ready to convert immediately (not used anywhere yet).', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'readyWithRewrite'  => __('%s will be converted and have their content references updated automatically.', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'leftUntouched'     => __('%s are used in a way this tool does not safely update yet (widgets, page builders) — left untouched.', 'image-webp-converter'),
            /* translators: %s: number of images queued. */
            'startConversion'   => __('Start Conversion (%s)', 'image-webp-converter'),
            /* translators: 1: images processed, 2: total queued. */
            'progress'          => __('%1$s / %2$s processed', 'image-webp-converter'),
            /* translators: 1: images processed, 2: total queued. */
            'stoppedAfter'      => __('Stopped after %1$s of %2$s.', 'image-webp-converter'),
            /* translators: %s: the underlying error message. */
            'conversionStopped' => __('Conversion stopped: %s. Images already processed are safe; re-scan to continue with the rest.', 'image-webp-converter'),
            'done'              => __('Done.', 'image-webp-converter'),
            'complete'          => __('Conversion complete.', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'summaryTrashed'    => __('%s converted and cleaned up automatically.', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'summaryPending'    => __('%s converted, content updated, originals kept pending your review — see the Cleanup Review tab.', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'summaryRefFailed'  => __('%s converted, but some references could not be updated automatically — originals kept and not offered for cleanup.', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'summaryError'      => __('%s had an error and were left unchanged.', 'image-webp-converter'),
            /* translators: %s: number of images. */
            'summarySkipped'    => __('%s were skipped.', 'image-webp-converter'),
        ];
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Image WebP Converter', 'image-webp-converter'); ?></h1>

            <?php
            // A count, not a message: passing notice text through the URL let
            // anyone craft a link that displayed arbitrary wording inside the
            // plugin's own admin screen. Escaping made it harmless to render,
            // but the text should never have been the caller's to choose.
            if (isset($_GET['iwc_moved'])) :
                $moved = absint($_GET['iwc_moved']);
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html(sprintf(
                        /* translators: %d: number of images moved. */
                        _n('%d image moved to the holding folder.', '%d images moved to the holding folder.', $moved, 'image-webp-converter'),
                        $moved
                    )); ?></p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=image-webp-converter&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Settings', 'image-webp-converter'); ?></a>
                <a href="?page=image-webp-converter&tab=convert" class="nav-tab <?php echo $active_tab === 'convert' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Convert Existing Images', 'image-webp-converter'); ?></a>
                <a href="?page=image-webp-converter&tab=cleanup" class="nav-tab <?php echo $active_tab === 'cleanup' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Cleanup Review', 'image-webp-converter'); ?></a>
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
                <?php
                printf(
                    /* translators: 1: link to the author's site, 2: link to the author's WordPress tools site. */
                    esc_html__('Built by %1$s — more free WordPress tools at %2$s.', 'image-webp-converter'),
                    '<a href="https://belchamber.us" target="_blank" rel="noopener noreferrer">Aaron Belchamber</a>',
                    '<a href="https://tools.belchamber.us" target="_blank" rel="noopener noreferrer">tools.belchamber.us</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    private static function render_settings_tab(): void {
        $quality = (int) get_option(IWC_OPTION_QUALITY, 82);
        $enabled = (bool) get_option(IWC_OPTION_ENABLED, true);
        $backend = iwc_webp_backend();
        ?>
        <?php if ($backend === '') : ?>
            <div class="notice notice-error">
                <p><?php echo esc_html__('Neither GD nor ImageMagick on this server can produce WebP images, so conversion is currently inactive. Ask your host to enable the GD extension with WebP support, or ImageMagick.', 'image-webp-converter'); ?></p>
            </div>
        <?php elseif ($backend === 'imagick') : ?>
            <div class="notice notice-info">
                <p><?php echo esc_html__('Conversions on this server are handled by ImageMagick, because GD here was built without WebP support. Nothing to do — this works just as well.', 'image-webp-converter'); ?></p>
            </div>
        <?php endif; ?>

        <p><?php
            printf(
                /* translators: %d: the minimum WEBP quality used for transparent images. */
                esc_html__('Uploads are converted to WebP automatically — nothing else to do. Transparent PNGs are always encoded at quality %d or higher, regardless of the setting below, to avoid jagged edges.', 'image-webp-converter'),
                (int) IWC_MIN_QUALITY_FOR_ALPHA
            );
        ?></p>

        <form method="post" action="options.php">
            <?php settings_fields('iwc_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Convert uploads automatically', 'image-webp-converter'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(IWC_OPTION_ENABLED); ?>" value="1" <?php checked($enabled); ?> />
                            <?php echo esc_html__('Convert new JPG/PNG uploads to WebP automatically', 'image-webp-converter'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="iwc_quality"><?php echo esc_html__('WEBP Quality', 'image-webp-converter'); ?></label></th>
                    <td>
                        <input type="number" min="0" max="100" step="1" id="iwc_quality" name="<?php echo esc_attr(IWC_OPTION_QUALITY); ?>" value="<?php echo esc_attr($quality); ?>" class="small-text" />
                        <p class="description"><?php echo esc_html__('0 (smallest file) – 100 (best quality). 82 is a good default. Used for both new uploads and the bulk converter below.', 'image-webp-converter'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Notices about what's installed on this site, shown before the scan so
     * the numbers it reports aren't a surprise.
     */
    private static function render_compat_notices(): void {
        $custom_table = IWC_Compat::custom_table_plugins();
        $builders = IWC_Compat::page_builders();
        $optimizers = IWC_Compat::conflicting_optimizers();

        if (!empty($custom_table)) : ?>
            <div class="notice notice-warning">
                <p><?php
                    printf(
                        /* translators: %s: comma-separated list of plugin names. */
                        esc_html__('%s store image URLs in their own database tables, which this plugin cannot search. An image could look unused here while one of them still points at it.', 'image-webp-converter'),
                        '<strong>' . esc_html(implode(', ', $custom_table)) . '</strong>'
                    );
                ?></p>
                <p><?php echo esc_html__('Images will still be converted, but originals are always kept for your review rather than being moved automatically. Check those pages before clearing anything from the Cleanup Review tab.', 'image-webp-converter'); ?></p>
            </div>
        <?php endif;

        if (!empty($optimizers)) : ?>
            <div class="notice notice-error">
                <p><?php
                    printf(
                        /* translators: %s: comma-separated list of plugin names. */
                        esc_html__('Another image optimiser is active: %s. Two plugins converting the same upload will fight over it. Run only one.', 'image-webp-converter'),
                        '<strong>' . esc_html(implode(', ', $optimizers)) . '</strong>'
                    );
                ?></p>
            </div>
        <?php endif;

        if (!empty($builders)) : ?>
            <div class="notice notice-info">
                <p><?php
                    printf(
                        /* translators: %s: comma-separated list of page builder names. */
                        esc_html__('%s stores page layouts outside normal post content. Images used there are reported below and deliberately left untouched — this tool will not rewrite a page builder\'s data.', 'image-webp-converter'),
                        '<strong>' . esc_html(implode(', ', $builders)) . '</strong>'
                    );
                ?></p>
            </div>
        <?php endif;
    }

    private static function render_convert_tab(): void {
        self::render_compat_notices();
        ?>
        <p><?php echo esc_html__('Scans your Media Library for JPG/PNG images and converts what is safe to convert automatically. Images already used somewhere are handled carefully — see the summary below before starting.', 'image-webp-converter'); ?></p>
        <div id="iwc-bulk-convert-app">
            <button type="button" id="iwc-scan-button" class="button button-primary"><?php echo esc_html__('Scan Media Library', 'image-webp-converter'); ?></button>
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
        $total_pending = IWC_Logger::count_pending_cleanup();
        self::render_references_failed_notice();
        ?>
        <p><?php echo esc_html__('These images were converted and their content references were updated, but the original files are still on disk pending your review. Select any you are confident about and move them out of the way.', 'image-webp-converter'); ?></p>

        <?php if ($total_pending > count($rows)) : ?>
            <p><em><?php
                printf(
                    /* translators: 1: number shown on this screen, 2: total number waiting. */
                    esc_html__('Showing the %1$d most recent of %2$d waiting. Clear these and the rest will appear.', 'image-webp-converter'),
                    (int) count($rows),
                    (int) $total_pending
                );
            ?></em></p>
        <?php endif; ?>

        <?php if (empty($rows)) : ?>
            <p><em><?php echo esc_html__('Nothing pending cleanup right now.', 'image-webp-converter'); ?></em></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="iwc_move_to_trash" />
                <?php wp_nonce_field('iwc_move_to_trash'); ?>

                <p>
                    <button type="button" class="button" onclick="document.querySelectorAll('.iwc-cleanup-checkbox').forEach(c => c.checked = true);"><?php echo esc_html__('Select All', 'image-webp-converter'); ?></button>
                    <button type="button" class="button" onclick="document.querySelectorAll('.iwc-cleanup-checkbox').forEach(c => c.checked = false);"><?php echo esc_html__('Deselect All', 'image-webp-converter'); ?></button>
                </p>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:24px;"></th>
                            <th><?php echo esc_html__('File', 'image-webp-converter'); ?></th>
                            <th><?php echo esc_html__('Space to reclaim', 'image-webp-converter'); ?></th>
                            <th><?php echo esc_html__('Fixed in', 'image-webp-converter'); ?></th>
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
                    <button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js(__('Move the selected original files to the holding folder? They will remain on disk (not deleted) but out of the live uploads path.', 'image-webp-converter')); ?>');"><?php echo esc_html__('Move Selected to Trash', 'image-webp-converter'); ?></button>
                </p>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * Images that converted but whose references couldn't all be rewritten.
     * Deliberately read-only: these are exactly the rows where moving the
     * originals would break something, so there's no action offered.
     */
    private static function render_references_failed_notice(): void {
        $rows = IWC_Logger::get_references_failed();
        if (empty($rows)) {
            return;
        }
        $count = count($rows);
        ?>
        <div class="notice notice-warning">
            <p><strong><?php echo esc_html(sprintf(
                /* translators: %d: number of images. */
                _n(
                    '%d image converted, but at least one reference to it could not be updated automatically.',
                    '%d images converted, but at least one reference to them could not be updated automatically.',
                    $count,
                    'image-webp-converter'
                ),
                $count
            )); ?></strong></p>
            <p><?php echo esc_html__('Their originals have been left in place and are not offered for cleanup below — removing them would break whatever still points at them. This usually means the reference is a relative path, a CDN URL, or otherwise not written in a form this tool can safely rewrite. The pages still work; they are just still using the original file.', 'image-webp-converter'); ?></p>
            <ul style="list-style:disc; margin-left:20px;">
                <?php foreach ($rows as $row) :
                    $affected = json_decode((string) $row->message, true);
                    $titles = is_array($affected)
                        ? array_map(function ($r) { return $r['post_title'] ?: ('#' . $r['post_id']); }, $affected)
                        : [];
                    ?>
                    <li>
                        <code><?php echo esc_html(basename($row->original_path)); ?></code>
                        <?php if (!empty($titles)) : ?>
                            <?php printf(
                                /* translators: %s: comma-separated list of post titles. */
                                esc_html__('— still referenced by: %s', 'image-webp-converter'),
                                esc_html(implode(', ', $titles))
                            ); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    public static function handle_move_to_trash(): void {
        check_admin_referer('iwc_move_to_trash');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'image-webp-converter'));
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
            'iwc_moved' => $moved,
        ], admin_url('options-general.php'));

        wp_safe_redirect($redirect);
        exit;
    }
}
