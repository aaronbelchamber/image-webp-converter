> **Standing preferences for this drive live outside this repo.**
> Read `E:\central-project-hub\PREFERENCES.md` before working here. It covers
> ports, launching processes without a window, URL hygiene, branch conventions,
> script placement, file-size thresholds, verification, design systems,
> self-review and session retros. It is canonical: where it and anything below
> disagree, it wins, and the copy below is the thing to fix.
>
> If that path does not resolve -- a different machine, an unmounted drive --
> recover it rather than carrying on as though there were no standing
> preferences: `gh repo clone aaronbelchamber/central-project-hub`.

# Image WebP Converter

Read MISSION.md for what this is.

## Before editing: this is the public mirror

Real development happens in `belchamber-plugins-private`
(`image-webp-converter/` subdirectory) — that private repo's CI publishes
changes out to this one. Do not edit code here directly; changes belong in
`belchamber-plugins-private` first. Editing this repo independently risks
drift, since the next publish from the private repo can overwrite or
conflict with local changes. If unsure whether a change is safe to make
here (docs-only, public-side-specific), confirm before proceeding.

## CI

This is the public half of the pair, so CI runs here and
`belchamber-plugins-private` deliberately does not run its own. The reasoning,
the cost model and the `workflow_dispatch` pattern are drive-wide and live in
`E:\central-project-hub\PREFERENCES.md` under "Continuous integration".

## Operational details

- Requires PHP 7.4+, GD extension or ImageMagick (GD preferred).
- New uploads auto-convert via `wp_handle_upload` — not a batch scan.
- Large libraries: use WP-CLI, not the browser bulk-converter (admin-ajax
  request limits).
  - `wp iwc scan` — reports findings, no changes.
  - `wp iwc convert --dry-run` — lists what would convert.
  - `wp iwc convert` — converts eligible images. Flags: `--bucket=`,
    `--limit=`, `--quality=`.
  - `wp iwc status` — totals and space reclaimed.
  - `wp iwc sidecar` (1.7.0+) — writes WebP alongside originals, untouched;
    no scan/buckets/review needed.
- WP-CLI and browser bulk-converter share one lock — no collisions.
- Stable tag: 1.7.0 — see `readme.txt` for the full changelog.

## Testing against hostile PHP environments

Four branches here cannot be reached from any single workstation: a GD build that
encodes WebP but cannot decode it, CMYK JPEG handling when Imagick is absent, a
lossless path needing PHP 8.1's `IMG_WEBP_LOSSLESS`, and a PHP 7.4
`imagedestroy()` call. [wp-dev-playground](../../wp-dev-playground)'s PHP matrix
exercises all of them:

```
scripts\test\run-php-matrix.cmd
```

Needs `pip install -e E:\ab-code-projects\projects\wp-dev-playground` and a
container runtime (`wpp doctor`). All seven variants passed as of 2026-08-27.
