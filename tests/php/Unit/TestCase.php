<?php

namespace IWC\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
    /** @var string[] */
    private array $tmpFiles = [];
    private ?string $tmpDir = null;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
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
