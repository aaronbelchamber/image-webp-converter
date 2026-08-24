<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves sidecar mode leaves originals alone and offers WebP alongside them.
 *
 * The point of the mode is that no URL ever changes, so most of what is worth
 * asserting is what does *not* happen: the source survives byte for byte,
 * nothing is moved, and an image whose sidecar is missing is left exactly as
 * the author wrote it rather than pointed at a file that is not there.
 *
 * @covers IWC_Sidecar
 */
final class SidecarModeTest extends TestCase {
    /** Encodes real images. */
    protected bool $requiresWebpEncoding = true;

    private string $uploads;

    protected function setUp(): void {
        parent::setUp();
        $this->uploads = sys_get_temp_dir() . '/iwc-sidecar-' . uniqid('', true);
        mkdir($this->uploads . '/2024/05', 0777, true);

        Functions\when('wp_image_editor_supports')->justReturn(true);
        Functions\when('wp_get_upload_dir')->justReturn([
            'basedir' => $this->uploads,
            'baseurl' => 'https://example.test/wp-content/uploads',
        ]);
        $this->setMode(\IWC_Sidecar::MODE_SIDECAR, true);
    }

    private function setMode(string $mode, bool $enabled): void {
        Functions\when('get_option')->alias(function ($name, $default = false) use ($mode, $enabled) {
            if ($name === IWC_OPTION_MODE) {
                return $mode;
            }
            if ($name === IWC_OPTION_ENABLED) {
                return $enabled;
            }
            if ($name === IWC_OPTION_QUALITY) {
                return 82;
            }
            return $default;
        });
    }

    private function seedJpeg(string $relative = '2024/05/photo.jpg'): string {
        $path = $this->uploads . '/' . $relative;
        FixtureFactory::plainJpeg($path, 300, [10, 200, 30], 100);
        return $path;
    }

    private function url(string $relative): string {
        return 'https://example.test/wp-content/uploads/' . $relative;
    }

    protected function tearDown(): void {
        $this->rrm($this->uploads);
        parent::tearDown();
    }

    private function rrm(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrm($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // -- generation ----------------------------------------------------

    public function test_the_original_is_left_byte_for_byte_intact(): void {
        $source = $this->seedJpeg();
        $before = file_get_contents($source);

        \IWC_Sidecar::generate($source, 82);

        $this->assertFileExists($source);
        $this->assertSame($before, file_get_contents($source), 'sidecar mode must never touch the source');
    }

    public function test_the_sidecar_is_written_beside_the_original(): void {
        $source = $this->seedJpeg();

        $this->assertTrue(\IWC_Sidecar::generate($source, 82));
        $this->assertFileExists($source . '.webp');
    }

    public function test_the_extension_is_appended_not_replaced(): void {
        // photo.jpg.webp, never photo.webp: the latter collides when a .jpg
        // and a .png of the same name both exist, and it is not the naming
        // other WebP plugins' rewrite rules expect.
        $this->assertSame('/uploads/photo.jpg.webp', \IWC_Sidecar::sidecar_for('/uploads/photo.jpg'));

        $source = $this->seedJpeg();
        \IWC_Sidecar::generate($source, 82);
        $this->assertFileDoesNotExist($this->uploads . '/2024/05/photo.webp');
    }

    public function test_an_existing_up_to_date_sidecar_is_not_rebuilt(): void {
        // Metadata is regenerated far more often than images change, so
        // re-encoding on every pass would be pure waste.
        $source = $this->seedJpeg();
        \IWC_Sidecar::generate($source, 82);
        touch($source . '.webp', time() + 10);
        $stamp = filemtime($source . '.webp');

        $this->assertFalse(\IWC_Sidecar::generate($source, 82));
        $this->assertSame($stamp, filemtime($source . '.webp'), 'an up-to-date sidecar must be left alone');
    }

    public function test_a_stale_sidecar_is_rebuilt(): void {
        $source = $this->seedJpeg();
        file_put_contents($source . '.webp', 'stale');
        touch($source . '.webp', time() - 3600);

        $this->assertTrue(\IWC_Sidecar::generate($source, 82));
        $this->assertNotSame('stale', file_get_contents($source . '.webp'));
    }

    public function test_a_non_convertible_file_is_ignored(): void {
        $pdf = $this->uploads . '/2024/05/doc.pdf';
        file_put_contents($pdf, 'not really a pdf');

        $this->assertFalse(\IWC_Sidecar::generate($pdf, 82));
        $this->assertFileDoesNotExist($pdf . '.webp');
    }

    // -- serving -------------------------------------------------------

    public function test_an_img_with_a_sidecar_is_wrapped_in_a_picture(): void {
        $source = $this->seedJpeg();
        \IWC_Sidecar::generate($source, 82);

        $html = '<p><img src="' . $this->url('2024/05/photo.jpg') . '" alt="x" /></p>';
        $out = \IWC_Sidecar::offer_webp_in_html($html);

        $this->assertStringContainsString('<picture>', $out);
        $this->assertStringContainsString('type="image/webp"', $out);
        $this->assertStringContainsString('photo.jpg.webp', $out);
        $this->assertStringContainsString(
            '<img src="' . $this->url('2024/05/photo.jpg') . '"',
            $out,
            'the original img must survive untouched as the fallback'
        );
    }

    public function test_an_img_without_a_sidecar_is_left_alone(): void {
        // The safety property the whole mode rests on: never point at a file
        // that is not there.
        $html = '<img src="' . $this->url('2024/05/missing.jpg') . '" />';

        $this->assertSame($html, \IWC_Sidecar::offer_webp_in_html($html));
    }

    public function test_an_external_image_is_left_alone(): void {
        $html = '<img src="https://cdn.example.com/photo.jpg" />';

        $this->assertSame($html, \IWC_Sidecar::offer_webp_in_html($html));
    }

    public function test_an_img_already_inside_a_picture_is_not_wrapped_again(): void {
        $source = $this->seedJpeg();
        \IWC_Sidecar::generate($source, 82);

        $html = '<picture><source srcset="a.avif" type="image/avif">'
            . '<img src="' . $this->url('2024/05/photo.jpg') . '" /></picture>';
        $out = \IWC_Sidecar::offer_webp_in_html($html);

        $this->assertSame(
            1,
            substr_count($out, '<picture>'),
            'nesting would be invalid markup and would override an author art-direction choice'
        );
    }

    public function test_a_srcset_is_only_offered_when_every_candidate_has_a_sidecar(): void {
        // A srcset that silently drops widths would have the browser choose a
        // smaller image than it should, so a partial set is no set at all.
        $a = $this->seedJpeg('2024/05/a.jpg');
        $this->seedJpeg('2024/05/b.jpg');
        \IWC_Sidecar::generate($a, 82);

        $html = '<img src="' . $this->url('2024/05/a.jpg') . '"'
            . ' srcset="' . $this->url('2024/05/a.jpg') . ' 300w, ' . $this->url('2024/05/b.jpg') . ' 600w" />';

        $this->assertSame($html, \IWC_Sidecar::offer_webp_in_html($html));
    }

    public function test_a_complete_srcset_is_carried_across_with_its_descriptors(): void {
        $a = $this->seedJpeg('2024/05/a.jpg');
        $b = $this->seedJpeg('2024/05/b.jpg');
        \IWC_Sidecar::generate($a, 82);
        \IWC_Sidecar::generate($b, 82);

        $html = '<img src="' . $this->url('2024/05/a.jpg') . '"'
            . ' srcset="' . $this->url('2024/05/a.jpg') . ' 300w, ' . $this->url('2024/05/b.jpg') . ' 600w"'
            . ' sizes="(max-width: 600px) 100vw, 600px" />';
        $out = \IWC_Sidecar::offer_webp_in_html($html);

        $this->assertStringContainsString('a.jpg.webp 300w', $out);
        $this->assertStringContainsString('b.jpg.webp 600w', $out);
        $this->assertStringContainsString('sizes="(max-width: 600px) 100vw, 600px"', $out);
    }

    public function test_nothing_is_rewritten_when_the_mode_is_off(): void {
        $source = $this->seedJpeg();
        \IWC_Sidecar::generate($source, 82);
        $this->setMode(\IWC_Sidecar::MODE_REPLACE, true);

        $html = '<img src="' . $this->url('2024/05/photo.jpg') . '" />';

        $this->assertSame($html, \IWC_Sidecar::offer_webp_in_html($html));
    }

    public function test_nothing_is_rewritten_when_the_plugin_is_disabled(): void {
        $source = $this->seedJpeg();
        \IWC_Sidecar::generate($source, 82);
        $this->setMode(\IWC_Sidecar::MODE_SIDECAR, false);

        $html = '<img src="' . $this->url('2024/05/photo.jpg') . '" />';

        $this->assertSame($html, \IWC_Sidecar::offer_webp_in_html($html));
    }

    public function test_content_without_images_is_returned_untouched(): void {
        $html = '<p>No pictures here.</p>';

        $this->assertSame($html, \IWC_Sidecar::offer_webp_in_html($html));
    }

    // -- mode selection ------------------------------------------------

    public function test_an_unrecognised_mode_falls_back_to_replace(): void {
        // A stray option value must never silently change how a working site
        // behaves.
        $this->setMode('something-else', true);

        $this->assertSame(\IWC_Sidecar::MODE_REPLACE, \IWC_Sidecar::mode());
        $this->assertFalse(\IWC_Sidecar::is_active());
    }
}
