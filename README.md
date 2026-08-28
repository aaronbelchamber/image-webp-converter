# Image WebP Converter

> Built by [Aaron Belchamber](https://belchamber.us) — Business Growth & Cloud Systems Architect
> A standalone, zero-config WordPress plugin — no dependency on any other project.
> More: [Brandager.com](https://brandager.com) · [Belchamber.us](https://belchamber.us) · [Tools.Belchamber.us](https://tools.belchamber.us)

## Description
Automatically converts newly uploaded JPG/JPEG/PNG images to WEBP format on the fly, via the WordPress upload pipeline (`wp_handle_upload`) — not a directory batch-scan. This reduces image file size while preserving quality, improving page load times.

## Features
- Converts JPG, JPEG, and PNG uploads to WEBP automatically as they come in.
- Preserves transparency for PNG images — and never drops below quality 80 for transparent images specifically, to avoid jagged compression artifacts on hard alpha edges.
- Corrects EXIF orientation before converting, so photos don't end up sideways.
- Skips CMYK JPEGs and hosts that can't decode WebP back, rather than risk a broken or corrupted result.
- **Convert Existing Images**: safely bulk-convert your current Media Library. Images not yet used anywhere convert immediately; images already in a post's content are converted with their references updated automatically, originals kept for your review; anything referenced from a page builder or widget is left untouched and reported, not guessed at.
- Cleanup Review screen to move reviewed originals to a holding folder (not deleted) once you're confident.
- Adjustable quality and an on/off toggle from the WordPress admin (Settings > WebP Converter).

## Installation
1. Upload the `image-webp-converter` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.

## Usage
Once activated, every new JPG/JPEG/PNG upload is converted to WEBP automatically — nothing else to do. To convert images already in your Media Library, go to **Settings > WebP Converter > Convert Existing Images**.

## Configuration
Quality (0–100) and the automatic-conversion toggle are set at **Settings > WebP Converter** in wp-admin. No file editing required.

## Requirements
- PHP 7.4+
- GD extension enabled in your PHP configuration.

## Support
Open an issue on this plugin's repository, or contact the developer via [belchamber.us](https://belchamber.us).

## License
GPLv2 or later — see [readme.txt](readme.txt) for the WordPress.org-format changelog and FAQ.

## Testing against hostile PHP environments

This plugin has four branches no single workstation can reach: a GD build that
encodes WebP but cannot decode it, CMYK JPEG handling when Imagick is absent,
a lossless path needing PHP 8.1's `IMG_WEBP_LOSSLESS`, and a PHP 7.4
`imagedestroy()` call. They are exercised by
[wp-dev-playground](../../wp-dev-playground)'s PHP matrix:

```
scripts\test\run-php-matrix.cmd
```

Needs `pip install -e E:\ab-code-projects\projects\wp-dev-playground` and a
container runtime (`wpp doctor`). All seven variants pass as of 2026-08-27.
