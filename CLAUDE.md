# Image WebP Converter

Read MISSION.md for what this is.

## Before you touch any code here

This repo is the **published, public side** of the plugin. Real development
happens in `belchamber-plugins-private`, a private monorepo with its own
`image-webp-converter/` subdirectory that is the actual dev source — that
repo's CI is what publishes changes out here.

So: **don't edit code in this repo directly.** If you're asked to fix a bug
or add a feature, the change belongs in `belchamber-plugins-private` first.
Editing here only risks drift — the next publish from the private repo can
silently overwrite or conflict with anything changed on this side. If you're
not sure whether a given change is safe to make here (e.g. docs-only, or
something genuinely public-side-specific), ask before proceeding rather than
assuming.

## CI: public-only, on purpose

GitHub Actions runs here because this is the public repo, where Actions
minutes are free. The private counterpart (`belchamber-plugins-private`)
deliberately does *not* run automatic CI — this repo's run already covers
the pair, since the two are near-identical by construction. Don't suggest
turning on automatic CI over there; if it ever needs to happen for
offload/debug purposes, the pattern is `on: workflow_dispatch:`, not deleting
or disabling the workflow.

## Real operational details

- Requires PHP 7.4+, and either the GD extension or ImageMagick (GD
  preferred where it can encode WebP).
- New uploads convert automatically via `wp_handle_upload` — no batch scan.
- Large-library work goes through WP-CLI, not the browser bulk-converter
  (which is limited by what an admin-ajax request survives):
  - `wp iwc scan` — report what a run would find, changes nothing.
  - `wp iwc convert --dry-run` — list exactly what would convert.
  - `wp iwc convert` — convert everything eligible. Accepts `--bucket=`,
    `--limit=`, `--quality=`.
  - `wp iwc status` — totals and space reclaimed so far.
  - `wp iwc sidecar` (1.7.0+) — build WebP alongside originals without
    touching them; no scan, buckets, or review step needed.
- WP-CLI and the browser bulk-converter share the same lock so they can't
  collide.
- Current stable tag: 1.7.0 (see `readme.txt` for the authoritative,
  WordPress.org-format changelog).

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
