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

- Public repo => Actions minutes are free => CI runs here.
- `belchamber-plugins-private` does not run automatic CI — this repo's run
  is the coverage for the pair. Don't enable automatic CI there.
- If private-side CI is ever needed for offload/debug: use
  `on: workflow_dispatch:`, never delete or disable the workflow.

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
