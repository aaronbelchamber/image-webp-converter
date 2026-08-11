# Image WebP Converter

> Built by [Aaron Belchamber](https://belchamber.us) — Business Growth & Cloud Systems Architect
> A standalone, zero-config WordPress plugin — no dependency on any other project.
> More: [Brandager.com](https://brandager.com) · [Belchamber.us](https://belchamber.us) · [Tools.Belchamber.us](https://tools.belchamber.us)

## Description
Automatically converts newly uploaded JPG/JPEG/PNG images to WEBP format on the fly, via the WordPress upload pipeline (`wp_handle_upload`) — not a directory batch-scan. This reduces image file size while preserving quality, improving page load times.

## Features
- Converts JPG, JPEG, and PNG uploads to WEBP automatically as they come in.
- Preserves transparency for PNG images.
- Checks for the GD extension and shows an admin notice if it's missing, instead of failing silently.
- Adjustable quality from the WordPress admin (Settings > WebP Converter).

## Installation
1. Upload the `image-webp-converter` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.

## Usage
Once activated, every new JPG/JPEG/PNG upload is converted to WEBP automatically — nothing else to do.

## Configuration
Quality (0–100) is set at **Settings > WebP Converter** in wp-admin. No file editing required.

## Requirements
- PHP 7.0+
- GD extension enabled in your PHP configuration.

## Support
Open an issue on this plugin's repository, or contact the developer via [belchamber.us](https://belchamber.us).

## License
GPLv2 — see [readme.txt](readme.txt) for the WordPress.org-format changelog and FAQ.