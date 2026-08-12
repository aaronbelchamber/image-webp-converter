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

    public function test_indexed_png_with_trns_is_a_documented_false_negative(): void {
        // Locks in the source's own documented limitation: indexed PNGs
        // (color type 3) using a tRNS chunk for transparency are NOT
        // detected by this function. This is accepted behavior, not a bug —
        // this test exists so a future change to iwc_png_has_alpha() that
        // silently starts detecting this case (or regresses further) shows
        // up as an intentional decision, not a surprise.
        $path = $this->tmpPath('indexed-trns.png');
        FixtureFactory::indexedPngWithTrns($path);

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
