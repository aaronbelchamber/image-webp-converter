=== Image WebP Converter ===
Contributors: aaronbelchamber
Tags: images, webp, performance, optimization, media
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
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


= 1.2.1 =
* Fixed bulk-convert bytes_saved always reporting 0 for trashed images; added a PHPUnit test suite.
= 1.2.0 =
* Convert Existing Images: safely bulk-convert your existing Media Library. Scans for images already used elsewhere on the site and only auto-fixes what's provably safe (unused images convert immediately; images found in plain post content convert with their references updated automatically, originals kept for review). Images referenced from page builders/widgets/serialized data are left untouched and reported, not guessed at.
* Cleanup Review screen: select and move reviewed originals to a holding folder (not deleted) once you're satisfied.
* Hardened the upload-time converter: correct EXIF orientation handling, a quality floor for transparent PNGs to avoid jagged edges, memory/timeout guards for large images, a filename-collision guard, and conversion is skipped entirely on hosts that can't decode WebP back (which would otherwise silently break responsive thumbnail sizes).
* CMYK JPEGs (which should never be on the web) are now converted to RGB correctly via Imagick when available; without Imagick, they're safely skipped rather than risk the color inversion GD alone is prone to on CMYK JPEGs.
* New settings: toggle automatic upload conversion on/off, plus a developer filter (`iwc_skip_conversion`) for programmatic control.

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
