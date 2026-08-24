<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves the Imagick backend produces the same decisions as the GD one.
 *
 * The point of a second backend is to reach hosts the first cannot, not to
 * quietly serve those hosts something different. So these assert the shared
 * behaviours — colourspace correction, orientation baked into pixels, the
 * alpha quality floor, refusing an output that is not smaller — rather than
 * merely that a file appeared.
 *
 * Skipped where Imagick is absent, which is most workstations; the playground's
 * php83-full and php83-imagick-only variants are where this actually runs.
 *
 * @covers iwc_convert_with_imagick
 * @covers iwc_imagick_apply_orientation
 * @covers iwc_imagick_write_smallest
 */
final class ImagickEncoderTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!iwc_imagick_supports_webp()) {
            $this->markTestSkipped('Imagick with WEBP support is not available in this environment.');
        }
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    /** Force the Imagick backend even on a host where GD would otherwise win. */
    private function forceImagickBackend(): void {
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return $hook === 'iwc_webp_backend' ? 'imagick' : $value;
        });
    }

    public function test_it_produces_a_readable_webp(): void {
        $source = $this->tmpPath('photo.jpg');
        FixtureFactory::plainJpeg($source, 200, [10, 200, 30], 100);
        $out = $this->tmpPath('photo.webp');

        $this->assertTrue(iwc_convert_with_imagick($source, 'image/jpeg', $out, 82));
        $this->assertFileExists($out);

        $probe = new \Imagick($out);
        $this->assertSame('WEBP', strtoupper($probe->getImageFormat()));
        $probe->clear();
    }

    public function test_a_cmyk_jpeg_converts_without_inverting_colour(): void {
        // The failure this guards against is not a crash but a plausible-looking
        // image with every channel inverted, which is exactly what GD alone
        // does to Adobe-tagged CMYK.
        $source = $this->tmpPath('cmyk.jpg');
        if (!FixtureFactory::cmykJpegFromKnownColor($source, '#3366CC', 40)) {
            $this->markTestSkipped('Could not build a real CMYK JPEG here.');
        }
        $out = $this->tmpPath('cmyk.webp');

        $this->assertTrue(iwc_convert_with_imagick($source, 'image/jpeg', $out, 95));

        $decoded = new \Imagick($out);
        $pixel = $decoded->getImagePixelColor((int) ($decoded->getImageWidth() / 2), (int) ($decoded->getImageHeight() / 2));
        $rgb = $pixel->getColor();
        $decoded->clear();

        // Inversion would land near (204, 153, 51) — far outside this tolerance
        // in the opposite direction on all three channels.
        $this->assertEqualsWithDelta(0x33, $rgb['r'], 30, 'red channel drifted too far');
        $this->assertEqualsWithDelta(0x66, $rgb['g'], 30, 'green channel drifted too far');
        $this->assertEqualsWithDelta(0xCC, $rgb['b'], 30, 'blue channel drifted too far');
    }

    public function test_exif_orientation_is_baked_into_the_pixels(): void {
        // Orientation 6 is a quarter-turn clockwise, so a landscape source
        // must come back portrait. WEBP does not carry the tag the way
        // browsers honour it for JPEG, so leaving it unapplied displays the
        // image rotated.
        $source = $this->tmpPath('oriented.jpg');
        if (!FixtureFactory::orientedJpegWithMarker($source, 6, 80, 40)) {
            $this->markTestSkipped('Could not build an EXIF-tagged fixture here.');
        }
        $out = $this->tmpPath('oriented.webp');

        $this->assertTrue(iwc_convert_with_imagick($source, 'image/jpeg', $out, 90));

        $decoded = new \Imagick($out);
        $w = $decoded->getImageWidth();
        $h = $decoded->getImageHeight();
        $orientation = $decoded->getImageOrientation();
        $decoded->clear();

        $this->assertSame(40, $w, 'an orientation-6 landscape source must come out portrait');
        $this->assertSame(80, $h);
        $this->assertNotSame(
            \Imagick::ORIENTATION_RIGHTTOP,
            $orientation,
            'the tag must be cleared once applied, or a reader rotates the image a second time'
        );
    }

    public function test_a_transparent_png_keeps_its_alpha(): void {
        $source = $this->tmpPath('alpha.png');
        FixtureFactory::transparentPngEllipse($source, 80);
        $out = $this->tmpPath('alpha.webp');

        $this->assertTrue(iwc_convert_with_imagick($source, 'image/png', $out, 82));

        $decoded = new \Imagick($out);
        $hasAlpha = $decoded->getImageAlphaChannel();
        $corner = $decoded->getImagePixelColor(0, 0)->getColor(true);
        $decoded->clear();

        $this->assertTrue((bool) $hasAlpha, 'transparency must survive the conversion');
        $this->assertLessThan(0.5, $corner['a'], 'the transparent corner must still be transparent');
    }

    public function test_output_that_is_not_smaller_is_still_rejected(): void {
        // The size guard lives in the caller, so it has to apply to whichever
        // backend produced the file — a second encoder must not become a way
        // to smuggle larger files onto the site.
        $this->forceImagickBackend();

        $source = $this->tmpPath('flat.png');
        FixtureFactory::transparentPngEllipse($source, 60);
        $out = $this->tmpPath('flat.webp');

        $result = iwc_convert_image_file_to_webp($source, 'image/png', $out, 82);

        if ($result) {
            $this->assertLessThan(filesize($source), filesize($out), 'a kept conversion must be smaller');
        } else {
            $this->assertFileDoesNotExist($out, 'a rejected conversion must leave nothing behind');
        }
    }

    public function test_the_full_pipeline_routes_through_imagick_when_asked(): void {
        $this->forceImagickBackend();

        $this->assertSame('imagick', iwc_webp_backend());

        $source = $this->tmpPath('routed.jpg');
        FixtureFactory::plainJpeg($source, 200, [10, 200, 30], 100);
        $out = $this->tmpPath('routed.webp');

        $this->assertTrue(iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 82));
        $this->assertLessThan(filesize($source), filesize($out));
    }

    public function test_a_corrupt_source_is_refused_rather_than_thrown(): void {
        // ImageMagick throws where GD returns false; the wrapper has to
        // normalise that, or one bad upload becomes a fatal error.
        $source = $this->tmpPath('broken.jpg');
        file_put_contents($source, 'this is not an image');
        $out = $this->tmpPath('broken.webp');

        $this->assertFalse(iwc_convert_with_imagick($source, 'image/jpeg', $out, 82));
    }
}
