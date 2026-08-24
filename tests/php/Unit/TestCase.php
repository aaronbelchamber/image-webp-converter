<?php

namespace IWC\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
    /**
     * Whether this test needs GD to actually be able to encode WEBP.
     *
     * Some hosts ship a GD compiled without WebP, and the plugin's whole
     * response to that is to refuse to convert — correctly, since a conversion
     * it cannot verify is worse than none. Tests that encode a real image (or
     * call imagewebp() directly in a helper) therefore cannot run there and
     * should stand down rather than report the plugin as broken.
     *
     * Set to true by any test class that produces or inspects real WEBP bytes.
     * Left false for tests of path handling, reference scanning, locking and
     * the like, which have no imaging dependency at all.
     */
    protected bool $requiresWebpEncoding = false;

    /** @var string[] */
    private array $tmpFiles = [];
    private ?string $tmpDir = null;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Translation and escaping functions, so the plugin's user-facing
        // strings can go through __() and esc_html__() without every test
        // having to mock them. Brain Monkey's stubs return the string
        // unchanged, which is what these tests want to assert against.
        //
        // _n() is not among them and is stubbed in bootstrap.php instead.
        Monkey\Functions\stubTranslationFunctions();
        Monkey\Functions\stubEscapeFunctions();

        if ($this->requiresWebpEncoding && !function_exists('imagewebp')) {
            $this->markTestSkipped('GD on this host was built without WEBP support.');
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        if ($this->tmpDir !== null) {
            $this->rrmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    protected function tmpPath(string $filename): string {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/iwc-test-' . uniqid('', true);
            mkdir($this->tmpDir, 0777, true);
        }
        $path = $this->tmpDir . '/' . $filename;
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
