<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves iwc_apply_exif_orientation() actually rotates/flips pixels
 * correctly, not just that conversion completes without error. Each test
 * decodes the real WEBP output and checks that a marker block (opaque red,
 * originally the top-left quadrant on an otherwise blue canvas) ends up
 * exactly where the named orientation implies, sampled well inside each
 * region to stay clear of JPEG/WEBP compression bleed at edges.
 *
 * The fixture builds its EXIF segment by hand, so these need only ext-exif —
 * no Imagick, and therefore no longer skipped on an ordinary workstation.
 *
 * @covers iwc_apply_exif_orientation
 */
final class ExifOrientationTest extends TestCase {
    private const WIDTH = 80;
    private const HEIGHT = 40;

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif is not available in this environment.');
        }
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    public function test_orientation_6_rotates_90_degrees_clockwise(): void {
        $source = $this->tmpPath('orientation-6.jpg');
        $written = FixtureFactory::orientedJpegWithMarker($source, 6, self::WIDTH, self::HEIGHT);
        $this->assertTrue($written, 'fixture EXIF Orientation tag did not round-trip on this machine');

        $decoded = $this->convertAndDecode($source);

        // 90 CW on an 80x40 canvas produces a 40x80 canvas; the original
        // top-left marker (x:[0,20), y:[0,10)) maps to new_x in [30,39],
        // new_y in [0,20) — sample well inside that region, and well
        // outside it, to tolerate lossy-compression edge bleed.
        $this->assertSame(self::HEIGHT, imagesx($decoded), 'rotated image width should equal the original height');
        $this->assertSame(self::WIDTH, imagesy($decoded), 'rotated image height should equal the original width');
        $this->assertPixelIsRed($decoded, 35, 10);
        $this->assertPixelIsBlue($decoded, 5, 60);
    }

    public function test_orientation_8_rotates_90_degrees_counterclockwise(): void {
        $source = $this->tmpPath('orientation-8.jpg');
        $written = FixtureFactory::orientedJpegWithMarker($source, 8, self::WIDTH, self::HEIGHT);
        $this->assertTrue($written, 'fixture EXIF Orientation tag did not round-trip on this machine');

        $decoded = $this->convertAndDecode($source);

        // 90 CCW on an 80x40 canvas: original top-left marker (x:[0,20),
        // y:[0,10)) maps to new_x = y, new_y = W-1-x, i.e. new_x in [0,10),
        // new_y in [60,79] — opposite corner from orientation 6.
        $this->assertSame(self::HEIGHT, imagesx($decoded));
        $this->assertSame(self::WIDTH, imagesy($decoded));
        $this->assertPixelIsRed($decoded, 5, 70);
        $this->assertPixelIsBlue($decoded, 35, 10);
    }

    public function test_orientation_3_rotates_180_degrees(): void {
        $source = $this->tmpPath('orientation-3.jpg');
        $written = FixtureFactory::orientedJpegWithMarker($source, 3, self::WIDTH, self::HEIGHT);
        $this->assertTrue($written, 'fixture EXIF Orientation tag did not round-trip on this machine');

        $decoded = $this->convertAndDecode($source);

        // 180 degrees keeps dimensions the same; the top-left marker moves
        // to the bottom-right quadrant.
        $this->assertSame(self::WIDTH, imagesx($decoded));
        $this->assertSame(self::HEIGHT, imagesy($decoded));
        $this->assertPixelIsRed($decoded, self::WIDTH - 10, self::HEIGHT - 5);
        $this->assertPixelIsBlue($decoded, 10, 5);
    }

    public function test_orientation_2_flips_horizontally(): void {
        $source = $this->tmpPath('orientation-2.jpg');
        $written = FixtureFactory::orientedJpegWithMarker($source, 2, self::WIDTH, self::HEIGHT);
        $this->assertTrue($written, 'fixture EXIF Orientation tag did not round-trip on this machine');

        $decoded = $this->convertAndDecode($source);

        // Horizontal flip keeps dimensions the same; the top-left marker
        // moves to the top-right.
        $this->assertSame(self::WIDTH, imagesx($decoded));
        $this->assertSame(self::HEIGHT, imagesy($decoded));
        $this->assertPixelIsRed($decoded, self::WIDTH - 10, 5);
        $this->assertPixelIsBlue($decoded, 10, 5);
    }

    public function test_missing_exif_data_does_not_error(): void {
        $source = $this->tmpPath('no-exif.jpg');
        FixtureFactory::plainJpeg($source, 30, [0, 255, 0]);

        $out = $this->tmpPath('no-exif.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 80);

        $this->assertTrue($ok);
    }

    private function convertAndDecode(string $source) {
        $out = $this->tmpPath('rotated-' . uniqid('', true) . '.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 95);
        $this->assertTrue($ok, 'conversion should succeed for a real oriented JPEG');

        $decoded = imagecreatefromwebp($out);
        $this->assertNotFalse($decoded, 'output should be a valid, decodable webp file');
        return $decoded;
    }

    private function assertPixelIsRed($image, int $x, int $y): void {
        $rgb = $this->pixelRgb($image, $x, $y);
        $this->assertGreaterThan($rgb['b'] + 40, $rgb['r'], "expected pixel ($x,$y) to be red-dominant, got r={$rgb['r']} g={$rgb['g']} b={$rgb['b']}");
    }

    private function assertPixelIsBlue($image, int $x, int $y): void {
        $rgb = $this->pixelRgb($image, $x, $y);
        $this->assertGreaterThan($rgb['r'] + 40, $rgb['b'], "expected pixel ($x,$y) to be blue-dominant, got r={$rgb['r']} g={$rgb['g']} b={$rgb['b']}");
    }

    private function pixelRgb($image, int $x, int $y): array {
        $color = imagecolorat($image, $x, $y);
        return [
            'r' => ($color >> 16) & 0xFF,
            'g' => ($color >> 8) & 0xFF,
            'b' => $color & 0xFF,
        ];
    }
}
