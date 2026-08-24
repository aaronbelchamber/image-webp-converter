<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves the quality-floor behavior for alpha PNGs (IWC_MIN_QUALITY_FOR_ALPHA
 * = 80): a low requested quality must be silently raised to 80 when the
 * source has an alpha channel, and left alone otherwise.
 *
 * imagewebp() doesn't expose the quality it actually encoded at, so this
 * uses a size-based proof: WEBP output size is monotonically non-decreasing
 * with quality for a fixed source image (a well-established property of the
 * codec), so comparing the pipeline's output size against two raw baseline
 * encodes (at the requested quality and at the floor quality) proves which
 * quality was actually honored — not just "conversion didn't crash."
 *
 * @covers iwc_convert_image_file_to_webp
 */
final class QualityFloorTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('wp_image_editor_supports')->justReturn(true);

        // These fixtures are small synthetic PNGs, which PNG's own compression
        // handles better than WEBP does — so the "output must be smaller"
        // guard legitimately rejects them. This test is about which quality
        // was honored, not whether the result was worth keeping, so opt out of
        // that guard rather than reshape the fixtures around it.
        // Also switches off the lossless attempt: a quality floor only means
        // anything for a lossy encode, and for these fixtures lossless wins
        // on size, which would make the size-based proof below measure the
        // wrong thing entirely.
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            if ($hook === 'iwc_require_smaller_output' || $hook === 'iwc_try_lossless') {
                return false;
            }
            return $value;
        });
    }

    public function test_alpha_png_low_quality_request_is_bumped_to_floor(): void {
        $source = $this->tmpPath('alpha.png');
        FixtureFactory::transparentPngEllipse($source, 60);

        $rawLow = $this->rawEncode($source, 10);
        $rawFloor = $this->rawEncode($source, 80);
        $pipelineOut = $this->tmpPath('alpha-pipeline.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $pipelineOut, 10);
        $this->assertTrue($ok);

        $pipelineSize = filesize($pipelineOut);

        $this->assertGreaterThan(
            $rawLow,
            $pipelineSize,
            'pipeline output at requested quality=10 should be larger than a raw quality=10 encode, proving the floor was applied'
        );

        // Pipeline output (quality=80, floored) should land much closer to
        // the raw quality=80 baseline than to the raw quality=10 baseline.
        $distanceToFloor = abs($pipelineSize - $rawFloor);
        $distanceToLow = abs($pipelineSize - $rawLow);
        $this->assertLessThan(
            $distanceToLow,
            $distanceToFloor,
            'pipeline output size should be closer to the quality=80 baseline than the quality=10 baseline'
        );
    }

    public function test_non_alpha_png_quality_is_not_bumped(): void {
        $source = $this->tmpPath('opaque.png');
        FixtureFactory::opaquePng($source, 60, [10, 200, 90]);

        $rawLow = $this->rawEncode($source, 10);
        $rawHigh = $this->rawEncode($source, 80);
        $pipelineOut = $this->tmpPath('opaque-pipeline.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $pipelineOut, 10);
        $this->assertTrue($ok);

        $pipelineSize = filesize($pipelineOut);

        $distanceToLow = abs($pipelineSize - $rawLow);
        $distanceToHigh = abs($pipelineSize - $rawHigh);
        $this->assertLessThan(
            $distanceToHigh,
            $distanceToLow,
            'pipeline output size for a non-alpha PNG at requested quality=10 should stay close to the quality=10 baseline, not be bumped toward 80'
        );
    }

    public function test_alpha_png_high_quality_request_above_floor_is_unaffected(): void {
        $source = $this->tmpPath('alpha-hq.png');
        FixtureFactory::transparentPngEllipse($source, 60);

        $rawRequested = $this->rawEncode($source, 95);
        $pipelineOut = $this->tmpPath('alpha-hq-pipeline.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $pipelineOut, 95);
        $this->assertTrue($ok);

        // 95 is already above the 80 floor, so the pipeline shouldn't alter
        // it — output size should match the raw quality=95 baseline closely.
        $pipelineSize = filesize($pipelineOut);
        $ratio = $pipelineSize / max(1, $rawRequested);
        $this->assertGreaterThan(0.7, $ratio);
        $this->assertLessThan(1.3, $ratio);
    }

    private function rawEncode(string $sourcePngPath, int $quality): int {
        $img = imagecreatefrompng($sourcePngPath);
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $out = $this->tmpPath('raw-' . $quality . '-' . uniqid('', true) . '.webp');
        imagewebp($img, $out, $quality);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }
        return filesize($out);
    }
}
