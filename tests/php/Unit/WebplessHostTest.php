<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves the plugin refuses cleanly on a host whose GD was built without WEBP.
 *
 * Some hosts ship exactly that, and the only safe response is to decline: a
 * conversion that cannot be written — or worse, one written but unreadable by
 * WordPress, leaving an attachment with no thumbnail sizes — is worse than no
 * conversion at all. The source must come through untouched.
 *
 * Like CmykWithoutImagickTest, this is gated the opposite way round from
 * everything else: it only proves something when the capability is *missing*,
 * so it stands down when WEBP support is present. That makes the
 * php83-gd-no-webp playground variant the environment where it actually earns
 * its keep, rather than a place the suite merely survives.
 *
 * @covers iwc_environment_supports_webp
 * @covers iwc_convert_image_file_to_webp
 * @covers iwc_convert_upload_to_webp
 */
final class WebplessHostTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (function_exists('imagewebp')) {
            $this->markTestSkipped('GD on this host can encode WEBP, so there is nothing to prove here.');
        }
    }

    public function test_environment_support_reports_false(): void {
        // Reported false regardless of what the image editor claims: WordPress
        // may well be able to handle WEBP through Imagick while the GD-based
        // conversion path here still cannot produce one.
        Functions\when('wp_image_editor_supports')->justReturn(true);

        $this->assertFalse(iwc_environment_supports_webp());
    }

    public function test_file_conversion_declines_and_leaves_the_source_alone(): void {
        Functions\when('wp_image_editor_supports')->justReturn(true);

        $source = $this->tmpPath('photo.jpg');
        FixtureFactory::plainJpeg($source, 60, [10, 200, 30]);
        $before = file_get_contents($source);
        $out = $this->tmpPath('photo.webp');

        $this->assertFalse(iwc_convert_image_file_to_webp($source, 'image/jpeg', $out, 82));
        $this->assertFileDoesNotExist($out, 'no output file may be left behind by a refused conversion');
        $this->assertSame($before, file_get_contents($source), 'the source must be byte-for-byte untouched');
    }

    public function test_an_upload_passes_through_unchanged(): void {
        // The upload filter has to hand WordPress back exactly what it was
        // given, still described as a JPEG. Rewriting the array to claim
        // image/webp while no WEBP exists would break the attachment.
        Functions\when('wp_image_editor_supports')->justReturn(true);
        Functions\when('wp_unique_filename')->alias(fn($dir, $name) => $name);

        $source = $this->tmpPath('upload.jpg');
        FixtureFactory::plainJpeg($source, 60, [10, 200, 30]);

        $upload = iwc_convert_upload_to_webp([
            'file' => $source,
            'url'  => 'https://example.test/wp-content/uploads/2024/05/upload.jpg',
            'type' => 'image/jpeg',
        ], 82);

        $this->assertSame($source, $upload['file']);
        $this->assertSame('image/jpeg', $upload['type']);
        $this->assertStringEndsWith('upload.jpg', $upload['url']);
        $this->assertFileExists($source);
    }
}
