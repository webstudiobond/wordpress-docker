<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\TestCase;
use WordPressDocker\NativeFilesystem;

final class NativeFilesystemTest extends TestCase
{
    private string $testTmpDir;
    private NativeFilesystem $fs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testTmpDir = sys_get_temp_dir() . '/fs_test_' . bin2hex(random_bytes(6));
        mkdir($this->testTmpDir, 0755, true);
        $this->fs = new NativeFilesystem();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testTmpDir);
        parent::tearDown();
    }

    public function testFilesystemOperations(): void
    {
        $dir = $this->testTmpDir . '/test_dir';
        $file = $dir . '/test.txt';
        $renamed = $dir . '/renamed.txt';
        $copied = $dir . '/copied.txt';
        $link = $dir . '/link.txt';

        $this->assertTrue($this->fs->mkdir($dir, 0755, true));
        $this->assertTrue($this->fs->isDir($dir));
        $this->assertFalse($this->fs->isFile($dir));

        file_put_contents($file, 'hello world');
        $this->assertTrue($this->fs->fileExists($file));
        $this->assertTrue($this->fs->isFile($file));
        $this->assertFalse($this->fs->isDir($file));
        $this->assertSame('hello world', $this->fs->fileGetContents($file));

        $this->assertTrue($this->fs->copy($file, $copied));
        $this->assertTrue($this->fs->fileExists($copied));

        $this->assertTrue($this->fs->chmod($copied, 0644));

        $this->assertTrue($this->fs->rename($copied, $renamed));
        $this->assertTrue($this->fs->fileExists($renamed));
        $this->assertFalse($this->fs->fileExists($copied));

        symlink($file, $link);
        $this->assertTrue($this->fs->isLink($link));
        $this->assertFalse($this->fs->isLink($file));

        $items = $this->fs->scandir($dir);
        $this->assertIsArray($items);
        $this->assertContains('test.txt', $items);
        $this->assertContains('renamed.txt', $items);

        $this->assertTrue($this->fs->unlink($link));
        $this->assertTrue($this->fs->unlink($file));
        $this->assertTrue($this->fs->unlink($renamed));
        $this->assertTrue($this->fs->rmdir($dir));
        $this->assertFalse($this->fs->isDir($dir));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
