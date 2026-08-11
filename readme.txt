=== Image WebP Converter ===
Contributors: aaronbelchamber
Tags: images, webp, performance, optimization, media
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.0
Stable tag: 1.1.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically converts newly uploaded JPG/JPEG/PNG images to WEBP to cut file size and speed up your site.

== Description ==

Image WebP Converter hooks into the WordPress media uploader and converts every new JPG, JPEG, or PNG upload to WEBP on the fly — no manual batch jobs, no bulk-scan scripts. Transparency is preserved for PNGs, and conversion quality is adjustable from Settings > WebP Converter.

Requires the PHP GD extension (enabled on almost every host by default).

Part of a small family of free WordPress utilities — more at [tools.belchamber.us](https://tools.belchamber.us).

== Installation ==

1. Upload the `image-webp-converter` folder to `/wp-content/plugins/`.
2. Activate through the 'Plugins' menu in WordPress.
3. (Optional) Adjust WEBP quality under Settings > WebP Converter.

== Changelog ==

= 1.1.0 =
* Rebuilt on the upload-hook approach (converts on upload, not via a directory batch-scan).
* Restored the missing plugin header and settings page.
* Added an adjustable quality setting (Settings > WebP Converter).

= 1.0 =
* Initial version.

== Frequently Asked Questions ==

= Does this touch my existing media library? =
No — it only converts new uploads going forward.

= What if GD isn't enabled? =
The plugin shows a notice on its settings page and leaves uploads untouched until GD is available.
