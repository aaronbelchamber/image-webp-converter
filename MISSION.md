# Mission

Image WebP Converter is a standalone WordPress plugin that converts JPG/JPEG/PNG
images to WebP to cut file size and speed up page loads. New uploads convert
automatically via the `wp_handle_upload` pipeline (not a directory batch-scan).
It handles CMYK JPEGs by correcting colourspace through Imagick, preserves EXIF
orientation and metadata that WebP itself can't carry, and holds transparent
PNGs to a quality floor so alpha edges don't compress into jagged artifacts.
Existing Media Libraries can be bulk-converted safely -- unused images convert
immediately, images referenced in plain post content convert with references
rewritten and originals kept for review, and anything referenced from a page
builder, widget, or another plugin's own tables is left untouched and reported
rather than guessed at. Large libraries are handled through WP-CLI
(`wp iwc scan|convert|status|sidecar`), which has no request timeout to hit. As
of 1.7.0 there's also a sidecar mode: the original file stays exactly where it
is and a `.webp` is written alongside it, so nothing that already points at
the original can break.

## What this is for

Reducing image weight on WordPress sites without asking the site owner to do
anything -- install, activate, done. It's a performance utility, not a media
management or optimization suite.

## Who it's for

WordPress site owners and admins who want smaller images and faster pages
without editing files, running scripts, or understanding image formats. Also
developers managing larger libraries via WP-CLI, and site owners running page
builders, translation plugins, or other tools that store image references
outside the searchable post content.

## What it is not

- Not a general image optimizer -- it only does JPG/JPEG/PNG to WebP
  conversion, not resizing, cropping, or lossless recompression of other
  formats.
- Not a CDN or image-delivery service.
- Not a page builder or media library replacement.
- Not dependent on any other plugin or project -- zero-config, standalone.

## Interfaces

```yaml
provides:
  - name: webp-conversion
    surface: "WordPress plugin -- image-webp-converter.php"
    kind: wordpress-plugin
    stable: true
consumes:
  - name: php-matrix
    from: wp-dev-playground
    surface: "wpp run . --variants all -- via scripts/test/run-php-matrix.cmd"
    status: live
```

## Related projects -- and, if applicable, how they work together

This repo (`image-webp-converter`) is the **published, public side** of the
plugin. Active development happens in `belchamber-plugins-private`, a private
monorepo with its own `image-webp-converter/` subdirectory that is the real
dev source. That private repo's CI publishes changes out to this repo --
`belchamber-plugins-private -> image-webp-converter` is one of several
private-to-public publish pairings (the same pattern as
`site-ops -> site-ops-showcase`).

Changes should generally originate in `belchamber-plugins-private`, not be
made independently here -- editing this repo directly risks drift between the
two, since the private repo's next publish would overwrite or conflict with
anything changed only on this side.
