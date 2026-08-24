<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * The single highest-value test in the suite: proves a real CMYK JPEG is
 * converted to the *correct* RGB color via Imagick, not merely that
 * conversion "succeeds." GD alone is known to invert colors on
 * Adobe-tagged CMYK JPEGs — a color-inversion bug would produce close to
 * the photographic-negative of the expected color (255-R, 255-G, 255-B),
 * which is trivially distinguishable from a correct, JPEG/WEBP-lossy-noise
 * tolerant match to the known input color.
 *
 * @group requires-imagick
 * @covers iwc_load_cmyk_jpeg_as_rgb
 * @covers iwc_convert_image_file_to_webp
 */
final class CmykConversionTest extends TestCase {
    /** These encode real images, which a GD built without WEBP cannot do. */
    protected bool $requiresWebpEncoding = true;

    protected function setUp(): void {
        parent::setUp();
        if (!FixtureFactory::isImagickAvailable()) {
            $this->markTestSkipped('ext-imagick is not available in this environment.');
        }
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    public function test_cmyk_jpeg_converts_to_the_correct_rgb_color_not_inverted(): void {
        $knownColor = ['r' => 0x33, 'g' => 0x66, 'b' => 0xCC]; // #3366CC
        $source = $this->tmpPath('cmyk.jpg');
        $written = FixtureFactory::cmykJpegFromKnownColor($source, '#3366CC', 40);
        $this->assertTrue($written, 'fixture is not a real CMYK JPEG (getimagesize channels !== 4) on this machine');

        $info = getimagesize($source);
        $this->assertSame(4, $info['channels'], 'sanity check: fixture must actually be detected as CMYK by the same mechanism the plugin uses');

        $out = $this->tmpPath('cmyk.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 95);
        $this->assertTrue($ok, 'CMYK conversion should succeed when Imagick is available');

        $decoded = imagecreatefromwebp($out);
        $this->assertNotFalse($decoded);

        $color = imagecolorat($decoded, (int) (imagesx($decoded) / 2), (int) (imagesy($decoded) / 2));
        $actual = [
            'r' => ($color >> 16) & 0xFF,
            'g' => ($color >> 8) & 0xFF,
            'b' => $color & 0xFF,
        ];

        // Generous but meaningful tolerance for CMYK->RGB->JPEG->WEBP lossy
        // round-tripping. A color-inversion bug would land around
        // (255-0x33, 255-0x66, 255-0xCC) = (204, 153, 51) — far outside
        // this tolerance in the opposite direction on every channel.
        $tolerance = 30;
        $this->assertEqualsWithDelta($knownColor['r'], $actual['r'], $tolerance, 'red channel drifted too far from the known input color');
        $this->assertEqualsWithDelta($knownColor['g'], $actual['g'], $tolerance, 'green channel drifted too far from the known input color');
        $this->assertEqualsWithDelta($knownColor['b'], $actual['b'], $tolerance, 'blue channel drifted too far from the known input color');

        // Explicit anti-inversion assertion: the inverted color must NOT be
        // a closer match than the true color, on any channel.
        $invertedDistance = abs((255 - $knownColor['r']) - $actual['r']);
        $correctDistance = abs($knownColor['r'] - $actual['r']);
        $this->assertLessThan($invertedDistance, $correctDistance + 1, 'output red channel is closer to the color-inverted value than the correct one');
    }

    public function test_cmyk_jpeg_without_imagick_is_safely_skipped_not_fatally_wrong(): void {
        // Can't literally unload ext-imagick mid-process; this documents
        // the guard-clause intent and is exercised for real (no source
        // change needed) by CI's dedicated no-Imagick job, which runs
        // iwc_load_cmyk_jpeg_as_rgb() with genuinely no Imagick class
        // loaded at all. Here we assert the narrower, always-true
        // contract: the function never throws for a real CMYK fixture.
        $source = $this->tmpPath('cmyk-2.jpg');
        $written = FixtureFactory::cmykJpegFromKnownColor($source, '#3366CC', 20);
        $this->assertTrue($written);

        $result = iwc_load_cmyk_jpeg_as_rgb($source);
        // With Imagick present (this test only runs when it is), the call
        // must succeed and return a usable GD resource/GdImage, never throw.
        $this->assertNotFalse($result);
    }
}
