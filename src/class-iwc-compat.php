<?php
/**
 * Environment detection for things that change how safe bulk conversion is.
 *
 * Three separate concerns, deliberately kept apart because they warrant
 * different responses:
 *
 * - Page builders store layouts outside post_content. The reference scan
 *   already refuses to touch those images, so this is purely so the UI can
 *   explain *why* a chunk of the library is being skipped.
 * - Custom-table plugins keep image URLs in tables the reference scan never
 *   looks at, so an image can look unreferenced while something still points
 *   at it. This one downgrades the destructive path.
 * - Other image optimisers also hook uploads; two converters racing over the
 *   same file is a mess worth warning about.
 *
 * Detection is by constant/class rather than is_plugin_active(): it needs no
 * extra include, works for must-use and bundled-in-theme copies, and reflects
 * what actually loaded rather than what the options table claims. All of
 * these are set at plugin load, so anything running from admin_init onwards
 * sees an accurate picture — cheap enough that caching it would only create
 * staleness bugs.
 *
 * @package Image_WebP_Converter
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWC_Compat {

    /**
     * Plugins that store image URLs in their own database tables, where the
     * postmeta/options reference scan cannot see them.
     */
    const CUSTOM_TABLE_PLUGINS = [
        'TranslatePress'    => ['constant' => 'TRP_PLUGIN_VERSION', 'class' => 'TRP_Translate_Press'],
        'WPML'              => ['constant' => 'ICL_SITEPRESS_VERSION', 'class' => 'SitePress'],
        'Slider Revolution' => ['constant' => 'RS_REVISION', 'class' => 'RevSliderFront'],
        'LayerSlider'       => ['constant' => 'LS_PLUGIN_VERSION', 'class' => 'LS_Sliders'],
        'MailPoet'          => ['constant' => 'MAILPOET_VERSION', 'class' => 'MailPoet\Config\Initializer'],
    ];

    /** Page builders that keep layout data outside post_content. */
    const PAGE_BUILDERS = [
        'Elementor'       => ['constant' => 'ELEMENTOR_VERSION', 'class' => 'Elementor\Plugin'],
        'Bricks'          => ['constant' => 'BRICKS_VERSION', 'class' => 'Bricks\Frontend'],
        'Oxygen'          => ['constant' => 'CT_VERSION', 'class' => 'OxygenElement'],
        'Breakdance'      => ['constant' => '__BREAKDANCE_VERSION', 'class' => 'Breakdance\Plugin'],
        'Beaver Builder'  => ['constant' => 'FL_BUILDER_VERSION', 'class' => 'FLBuilder'],
        'WPBakery'        => ['constant' => 'WPB_VC_VERSION', 'class' => 'Vc_Manager'],
        'Avada / Fusion'  => ['constant' => 'FUSION_BUILDER_VERSION', 'class' => 'FusionBuilder'],
    ];

    /** Other plugins that convert or rewrite images on upload. */
    const CONFLICTING_OPTIMIZERS = [
        'ShortPixel'            => ['constant' => 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION', 'class' => 'ShortPixelPlugin'],
        'Imagify'               => ['constant' => 'IMAGIFY_VERSION', 'class' => 'Imagify'],
        'EWWW Image Optimizer'  => ['constant' => 'EWWW_IMAGE_OPTIMIZER_VERSION', 'class' => 'EWWW\Base'],
        'Smush'                 => ['constant' => 'WP_SMUSH_VERSION', 'class' => 'Smush\Core\Core'],
        'Optimole'              => ['constant' => 'OPTML_VERSION', 'class' => 'Optml_Main'],
        'Jetpack Site Accelerator' => ['constant' => '', 'class' => 'Jetpack_Photon'],
    ];

    /** Names from $map that are present on this site. */
    private static function detect(array $map): array {
        $found = [];
        foreach ($map as $name => $signature) {
            $constant = $signature['constant'] ?? '';
            $class = $signature['class'] ?? '';
            if (($constant !== '' && defined($constant)) || ($class !== '' && class_exists($class))) {
                $found[] = $name;
            }
        }
        return $found;
    }

    /**
     * Each list is filterable so a site can declare something these
     * signatures don't know about — a bespoke builder, an in-house plugin
     * with its own tables — and have it treated with the same caution.
     *
     * @return string[]
     */
    public static function custom_table_plugins(): array {
        /**
         * Filters the detected plugins that store image URLs in their own tables.
         *
         * @param string[] $detected Plugin names found on this site.
         */
        return (array) apply_filters('iwc_custom_table_plugins', self::detect(self::CUSTOM_TABLE_PLUGINS));
    }

    /** @return string[] */
    public static function page_builders(): array {
        /**
         * Filters the detected page builders.
         *
         * @param string[] $detected Builder names found on this site.
         */
        return (array) apply_filters('iwc_page_builders', self::detect(self::PAGE_BUILDERS));
    }

    /** @return string[] */
    public static function conflicting_optimizers(): array {
        /**
         * Filters the detected competing image optimisers.
         *
         * @param string[] $detected Plugin names found on this site.
         */
        return (array) apply_filters('iwc_conflicting_optimizers', self::detect(self::CONFLICTING_OPTIMIZERS));
    }

    /**
     * Whether anything on this site keeps image URLs somewhere the reference
     * scan cannot reach.
     *
     * When true the bulk converter still converts, but never moves an
     * original to the holding folder on the strength of "nothing references
     * this" — because that conclusion is only as good as the places it
     * looked. Originals are kept for review instead.
     *
     * Filterable both ways: sites that have satisfied themselves the risk
     * doesn't apply can opt back into the faster path, and integrations can
     * flag a plugin this list doesn't know about.
     */
    public static function has_custom_table_risk(): bool {
        $detected = self::custom_table_plugins() !== [];

        /**
         * Filters whether image URLs may exist in tables this plugin cannot scan.
         *
         * @param bool     $detected Whether a known custom-table plugin was found.
         * @param string[] $plugins  The detected plugin names.
         */
        return (bool) apply_filters('iwc_custom_table_risk', $detected, self::custom_table_plugins());
    }
}
