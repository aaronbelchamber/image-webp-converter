=== Image WebP Converter ===
Contributors: aaronbelchamber
Tags: images, webp, performance, optimization, media
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts JPG/JPEG/PNG images to WEBP to cut file size and speed up your site — new uploads automatically, and your existing Media Library on request.

== Description ==

Image WebP Converter hooks into the WordPress media uploader and converts every new JPG, JPEG, or PNG upload to WEBP on the fly — no manual batch jobs, no bulk-scan scripts. Transparency is preserved for PNGs, and conversion quality is adjustable from Settings > WebP Converter.

Requires either the PHP GD extension (enabled on almost every host by default) or ImageMagick. GD is preferred where it can encode WebP; ImageMagick is used automatically where it cannot.

= WP-CLI =

For large libraries, use WP-CLI rather than the browser — it has no request timeout to run into:

`wp iwc scan` — report what a conversion run would find, changing nothing.
`wp iwc convert --dry-run` — list exactly what would be converted.
`wp iwc convert` — convert everything eligible. Accepts `--bucket=`, `--limit=` and `--quality=`.
`wp iwc status` — totals and space reclaimed so far.

Part of a small family of free WordPress utilities — more at [tools.belchamber.us](https://tools.belchamber.us).

== Installation ==

1. Upload the `image-webp-converter` folder to `/wp-content/plugins/`.
2. Activate through the 'Plugins' menu in WordPress.
3. (Optional) Adjust WEBP quality under Settings > WebP Converter.

== Changelog ==

= 1.6.0 =
* The plugin is now fully translatable. Every string in the admin screens, the AJAX responses and the bulk-converter's status messages goes through WordPress's translation functions with a text domain matching the plugin slug, so translate.wordpress.org can pick it up. Strings containing a number are passed to translators as placeholders rather than having the count welded to the front of the sentence, since several languages need it elsewhere.
* The JavaScript on the Convert Existing Images tab is translated too, via strings passed in from PHP. A test checks the keys the script asks for and the strings PHP supplies still match — a rename there used to fail silently, leaving the interface in English and looking entirely normal.
* Fixed: the settings screen reported "the PHP GD extension is not enabled — conversion is currently inactive" on any server whose GD lacks WebP, which since 1.5.0 has been wrong: those servers convert perfectly well through ImageMagick. It now reports which backend is actually in use.
= 1.5.0 =
* Added: an ImageMagick encoding backend. Hosts whose GD was built without WebP support — while ImageMagick handles it perfectly well — previously converted nothing at all and gave no indication why. Those installations now work. GD is still used wherever it can encode WebP, so nothing changes for the great majority of sites; the `iwc_webp_backend` filter can force either one.
* The Imagick path mirrors the GD path's decisions rather than inventing its own: the same colourspace correction for CMYK, the same EXIF orientation baked into the pixels, the same quality floor for transparent images, and the same "keep whichever of lossy and lossless is smaller" comparison. It additionally preserves the ICC colour profile, which GD discards.
* Fixed: the check that WordPress can read a WebP back — without which it cannot generate a single thumbnail size — was tied to the GD branch while the new backend was being added, so a host that failed it could still fall through to Imagick and produce an image with no responsive sizes. It is now a precondition on conversion regardless of which library encodes.
= 1.4.1 =
* Fixed: CMYK JPEG conversion never actually worked. The image is routed through Imagick to correct its colours, and ImageMagick returns a palette image whenever the picture has few enough distinct colours — which WEBP cannot encode at all. Any CMYK image with flat or limited colour (a logo, a print-ready graphic, a solid background) produced a zero-byte file and a failed conversion. It failed safely, leaving the original alone, but the feature did not work. Found by running the test suite in a container with Imagick installed, which is the only way this code path can be reached.
= 1.4.0 =
* Added: WP-CLI commands — `wp iwc scan`, `wp iwc convert` and `wp iwc status`. The browser bulk converter is limited by what an admin-ajax request can survive; on a large library this is the one that finishes. Supports `--dry-run`, `--bucket`, `--limit` and `--quality`, and shares the same lock as the browser path so the two can't collide.
* Added: PNG conversions now try a lossless encode alongside the lossy one and keep whichever is smaller. Logos, icons, screenshots and flat graphics compress dramatically better lossless — often smaller than the source PNG. This also makes transparent images convertible at all: at the alpha quality floor many encoded larger than the PNG they'd replace and were rejected outright. Overridable with the `iwc_try_lossless` filter. Requires PHP 8.1; older versions encode lossy as before.
* Added: the page-builder, competing-optimiser and custom-table plugin lists are now filterable (`iwc_page_builders`, `iwc_conflicting_optimizers`, `iwc_custom_table_plugins`), so a site can declare something these signatures don't recognise and have it treated with the same caution.
= 1.3.1 =
* Fixed: image URLs stored inside block attributes (a Cover block's background, for instance) have their slashes escaped by WordPress, so they were found by the reference scan but never actually rewritten.
* Fixed: EXIF and IPTC data — caption, credit, copyright, camera and timestamp — was discarded on every converted upload, because conversion happens before WordPress reads it and WEBP cannot carry it. The original's metadata is now preserved through conversion, and existing metadata survives bulk conversion too.
* Fixed: indexed PNGs storing transparency in a tRNS chunk (which is what GD produces for a transparent palette image) weren't recognised as transparent, so they skipped the alpha quality floor and encoded with fringing around the edges.
* Fixed: two browser tabs or two administrators running the bulk converter at once could both start converting the same image. Conversion batches now take a lock, and a run abandoned by a crashed request is reclaimed automatically.
* Changed: the admin notice after moving files no longer takes its wording from the URL.
= 1.3.0 =
* Fixed: the bulk converter's reference check treated only PHP-serialized data as a reference, so images placed with Elementor, Bricks, Oxygen or Breakdance (which store layouts as JSON) were classed as unused and had their originals moved — breaking those pages. References are now detected regardless of how they're stored.
* Fixed: every image's own attachment metadata was being counted as a reference to itself, which meant the bulk converter reported everything as "in use" and converted nothing at all on a real site.
* Fixed: references written as JSON escape their slashes, so the search never matched page-builder data even once it was being looked at.
* Fixed: posts referencing a thumbnail size were detected but never rewritten, then had that thumbnail moved anyway. All sizes are now rewritten, and an image whose references can't all be updated is reported and excluded from cleanup instead of being silently marked ready.
* Fixed: the full-resolution original kept alongside large uploads (WordPress's -scaled behaviour) was never collected, leaving the biggest file on disk behind and under-reporting space saved.
* Fixed: converting a .jpe or .jfif upload wrote WEBP data into a file that kept its original extension.
* Added: conversions that would produce a larger file than the source are now discarded and the original kept — WEBP doesn't beat every image. Overridable with the `iwc_require_smaller_output` filter.
* Added: detection for page builders, other image optimisers, and plugins that store image URLs in their own tables (TranslatePress, WPML, Slider Revolution, LayerSlider, MailPoet). When one of the latter is present, originals are always kept for review rather than moved automatically.
* Changed: the Media Library scan now runs in pages instead of a single request, so it completes on large libraries instead of timing out.
* Added: uninstall now removes the plugin's settings, log table and metadata. The holding folder of original images is deliberately left alone.
* Added: the holding folder is no longer publicly readable on Apache/LiteSpeed.
* Fixed: a failed batch left the conversion progress bar frozen with no error shown.
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
Only when you ask it to. New uploads are converted automatically; your existing library is left alone until you run
the bulk converter from the "Convert Existing Images" tab.

= Is the bulk converter safe to run? =
It is built to refuse rather than guess. Before touching an image it searches your posts, postmeta and options for any
reference to that file. Anything referenced from a page builder, widget or setting is reported and left completely
untouched. Only images referenced nowhere, or referenced in plain post content it can rewrite itself, are converted.

= What about Elementor, Bricks, Oxygen or Divi? =
Images used in Elementor, Bricks, Oxygen and similar builders are detected and deliberately skipped — this plugin will
not rewrite a page builder's own data. Divi and other shortcode-based builders keep their content in the post body, so
those images are handled normally.

= Are my original files deleted? =
No. Originals are moved to wp-content/uploads/iwc-trash/, mirroring their original folder structure, so you can restore
or delete them yourself once you're happy. Uninstalling the plugin does not remove that folder.

= I use TranslatePress, WPML, Slider Revolution or MailPoet. =
Those store image URLs in their own database tables, which this plugin cannot search — so an image could look unused
while one of them still points at it. When any of them is detected, originals are always kept for your review instead
of being moved automatically.

= What if GD isn't enabled? =
The plugin shows a notice on its settings page and leaves uploads untouched until GD is available.

= Why did some images not convert? =
A conversion is only kept if the WEBP is actually smaller. WEBP does not beat every source — a well-optimised JPEG or a
flat-colour PNG often re-encodes larger — and in those cases the original is kept as-is rather than made bigger.

= My host runs nginx. How do I protect the holding folder? =
An .htaccess denying access is written automatically, which covers Apache and LiteSpeed. On nginx add:
`location ~* /uploads/iwc-trash/ { deny all; }`
