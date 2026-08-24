<?php

namespace IWC\Tests\Unit;

use Brain\Monkey\Functions;
use IWC\Tests\fixtures\FixtureFactory;

/**
 * Proves the memory-usage guard in iwc_convert_image_file_to_webp() actually
 * refuses conversion when the estimated decoded size wouldn't fit in the
 * remaining memory_limit headroom, and is skipped entirely when
 * memory_limit is unlimited (-1).
 *
 * @covers iwc_convert_image_file_to_webp
 */
final class MemoryGuardTest extends TestCase {
    /** These encode real images, which a GD built without WEBP cannot do. */
    protected bool $requiresWebpEncoding = true;

    private string $originalMemoryLimit;

    protected function setUp(): void {
        parent::setUp();
        $this->originalMemoryLimit = (string) ini_get('memory_limit');
        Functions\when('wp_image_editor_supports')->justReturn(true);
    }

    protected function tearDown(): void {
        ini_set('memory_limit', $this->originalMemoryLimit);
        parent::tearDown();
    }

    public function test_conversion_is_refused_when_estimated_size_exceeds_headroom(): void {
        // A large-enough source that estimated_bytes (width*height*4*1.8,
        // ~45MB at 2500x2500) unambiguously exceeds the headroom left under
        // a 32M limit, regardless of this test process's baseline usage —
        // ini_set() itself refuses to set a limit below current usage, so
        // the target here must stay comfortably above that baseline.
        $source = $this->tmpPath('large.png');
        FixtureFactory::opaquePng($source, 2500);

        ini_set('memory_limit', '32M');

        $out = $this->tmpPath('large.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $out, 80);

        $this->assertFalse($ok, 'conversion should be refused rather than risk exhausting the 2M memory_limit');
        $this->assertFileDoesNotExist($out);
    }

    public function test_guard_is_skipped_entirely_when_memory_limit_is_unlimited(): void {
        $source = $this->tmpPath('large2.png');
        FixtureFactory::opaquePng($source, 1500);

        ini_set('memory_limit', '-1');

        $out = $this->tmpPath('large2.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $out, 80);

        $this->assertTrue($ok, 'conversion should proceed when memory_limit is unlimited, regardless of estimated size');
        $this->assertFileExists($out);
    }

    public function test_small_image_is_unaffected_by_a_reasonable_memory_limit(): void {
        $source = $this->tmpPath('small.png');
        FixtureFactory::opaquePng($source, 20);

        ini_set('memory_limit', '128M');

        $out = $this->tmpPath('small.webp');
        $ok = iwc_convert_image_file_to_webp($source, 'image/png', $out, 80);

        $this->assertTrue($ok);
    }
}
