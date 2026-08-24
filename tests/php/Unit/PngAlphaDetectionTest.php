<?php

namespace IWC\Tests\Unit;

use IWC\Tests\fixtures\FixtureFactory;

/**
 * @covers iwc_png_has_alpha
 */
final class PngAlphaDetectionTest extends TestCase {
    public function test_detects_true_color_alpha_png(): void {
        $path = $this->tmpPath('alpha.png');
        FixtureFactory::transparentPngEllipse($path);

        $this->assertTrue(iwc_png_has_alpha($path));
    }

    public function test_opaque_png_has_no_alpha(): void {
        $path = $this->tmpPath('opaque.png');
        FixtureFactory::opaquePng($path);

        $this->assertFalse(iwc_png_has_alpha($path));
    }

    public function test_detects_grayscale_alpha_via_raw_ihdr_color_type_4(): void {
        // GD has no writer that emits a real grayscale+alpha (color type 4)
        // PNG, so this exercises the IHDR-byte-parsing logic directly via a
        // syntactically minimal (26-byte) PNG prefix — iwc_png_has_alpha()
        // never reads past that offset, so this is a faithful test of the
        // real code path, not a shortcut around it.
        $path = $this->tmpPath('grayscale-alpha.png');
        FixtureFactory::rawPngHeaderOnly($path, 4);

        $this->assertTrue(iwc_png_has_alpha($path));
    }

    public function test_indexed_png_with_trns_is_detected(): void {
        // Indexed PNGs (colour type 3) carry transparency in a tRNS chunk
        // rather than an alpha channel, so the IHDR colour-type byte alone
        // says nothing about it. GD writes exactly this shape whenever
        // imagecolortransparent() is used, so it is not a legacy-only case.
        // Undetected, these skipped the alpha quality floor and encoded with
        // visible fringing around the transparent edges.
        $path = $this->tmpPath('indexed-trns.png');
        FixtureFactory::indexedPngWithTrns($path);

        $this->assertTrue(iwc_png_has_alpha($path));
    }

    public function test_opaque_indexed_png_without_trns_is_not_reported_as_alpha(): void {
        // The counterpart: walking the chunk table must not turn every
        // palette image into an alpha image.
        $path = $this->tmpPath('indexed-opaque.png');
        $img = imagecreate(10, 10);
        imagecolorallocate($img, 0, 128, 0);
        imagepng($img, $path);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }

        $this->assertFalse(iwc_png_has_alpha($path));
    }

    public function test_chunk_walk_stops_at_image_data(): void {
        // A truecolour PNG with no transparency: the scan must reach IDAT and
        // give up rather than walking the whole pixel payload.
        $path = $this->tmpPath('truecolor-opaque.png');
        FixtureFactory::opaquePng($path, 40, [10, 20, 200]);

        $this->assertFalse(iwc_png_has_alpha($path));
    }

    public function test_nonexistent_file_returns_false(): void {
        $this->assertFalse(iwc_png_has_alpha($this->tmpPath('does-not-exist.png')));
    }

    public function test_truncated_file_returns_false(): void {
        $path = $this->tmpPath('truncated.png');
        file_put_contents($path, "\x89PNG\r\n\x1a\n"); // signature only, < 26 bytes

        $this->assertFalse(iwc_png_has_alpha($path));
    }
}
