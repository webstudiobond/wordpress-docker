<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressDocker\FilesystemInterface;
use WordPressDocker\NativeFilesystem;
use WordPressDocker\WordPressSync;

final class WordPressSyncTest extends TestCase
{
    private string $testTmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testTmpDir = sys_get_temp_dir() . '/wp_sync_test_' . bin2hex(random_bytes(6));
        mkdir($this->testTmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testTmpDir);
        parent::tearDown();
    }

    public function testSourceDirectoryNotFoundSkipsSync(): void
    {
        $source = $this->testTmpDir . '/non_existent_source';
        $target = $this->testTmpDir . '/target';

        $syncer = new WordPressSync($source, $target);

        ob_start();
        $exitCode = $syncer->run();
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Source directory', $output);
        $this->assertStringContainsString('Skipping synchronization', $output);
    }

    public function testGetWpVersion(): void
    {
        $syncer = new WordPressSync();

        $versionFile = $this->testTmpDir . '/version.php';
        file_put_contents($versionFile, "<?php\n\$wp_version = '6.7.1';\n");
        $this->assertSame('6.7.1', $syncer->getWpVersion($versionFile));

        file_put_contents($versionFile, "<?php\n\$wp_version = \"6.8.0-RC1\";\n");
        $this->assertSame('6.8.0-RC1', $syncer->getWpVersion($versionFile));

        file_put_contents($versionFile, "<?php\n// no version here\n");
        $this->assertNull($syncer->getWpVersion($versionFile));

        $this->assertNull($syncer->getWpVersion($this->testTmpDir . '/missing.php'));
    }

    public function testGetContentDirName(): void
    {
        $syncer = new WordPressSync();

        $this->assertSame('wp-content', $syncer->getContentDirName());
        $this->assertSame('my-content', $syncer->getContentDirName('my-content'));
        $this->assertSame('custom_dir', $syncer->getContentDirName('/var/www/custom_dir/'));
        $this->assertSame('wp-content', $syncer->getContentDirName(''));
        $this->assertSame('wp-content', $syncer->getContentDirName('   '));
        $this->assertSame('wp-content', $syncer->getContentDirName('.'));
        $this->assertSame('wp-content', $syncer->getContentDirName('..'));
        $this->assertSame('wp-content', $syncer->getContentDirName('/'));
    }

    public function testCopyRecursiveAndPermissions(): void
    {
        $syncer = new WordPressSync();

        $src = $this->testTmpDir . '/source';
        $dst = $this->testTmpDir . '/dest';

        mkdir($src . '/sub/nested', 0755, true);
        file_put_contents($src . '/root.txt', 'root content');
        file_put_contents($src . '/sub/nested/file.txt', 'nested content');

        $syncer->copyRecursive($src, $dst);

        $this->assertFileExists($dst . '/root.txt');
        $this->assertFileExists($dst . '/sub/nested/file.txt');
        $this->assertSame('root content', file_get_contents($dst . '/root.txt'));
        $this->assertSame('nested content', file_get_contents($dst . '/sub/nested/file.txt'));

        $this->assertSame('0755', substr(sprintf('%o', fileperms($dst . '/sub/nested')), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($dst . '/root.txt')), -4));
    }

    public function testDeleteRecursive(): void
    {
        $syncer = new WordPressSync();

        $dir = $this->testTmpDir . '/to_delete';
        mkdir($dir . '/sub/dir', 0755, true);
        file_put_contents($dir . '/a.txt', 'a');
        file_put_contents($dir . '/sub/b.txt', 'b');
        file_put_contents($dir . '/sub/dir/c.txt', 'c');

        $syncer->deleteRecursive($dir);

        $this->assertFileDoesNotExist($dir);

        $syncer->deleteRecursive($this->testTmpDir . '/non_existent');
        $this->assertFileDoesNotExist($this->testTmpDir . '/non_existent');
    }

    public function testAtomicSyncDirectorySuccess(): void
    {
        $syncer = new WordPressSync();

        $src = $this->testTmpDir . '/src_admin';
        $target = $this->testTmpDir . '/target_admin';

        mkdir($src, 0755, true);
        file_put_contents($src . '/admin-header.php', 'new admin');

        mkdir($target, 0755, true);
        file_put_contents($target . '/admin-header.php', 'old admin');
        file_put_contents($target . '/obsolete.php', 'obsolete');

        $syncer->atomicSyncDirectory($src, $target);

        $this->assertFileExists($target . '/admin-header.php');
        $this->assertSame('new admin', file_get_contents($target . '/admin-header.php'));
        $this->assertFileDoesNotExist($target . '/obsolete.php');
    }

    public function testSyncCustomContentDirectory(): void
    {
        $syncer = new WordPressSync();

        $target = $this->testTmpDir . '/target_site';
        mkdir($target . '/wp-content/themes', 0755, true);
        file_put_contents($target . '/wp-content/index.php', 'wp-content index');
        file_put_contents($target . '/wp-content/themes/style.css', 'theme style');

        $syncer->syncCustomContentDirectory($target, 'app-content');

        $this->assertFileDoesNotExist($target . '/wp-content');
        $this->assertFileExists($target . '/app-content/index.php');
        $this->assertFileExists($target . '/app-content/themes/style.css');

        mkdir($target . '/wp-content', 0755, true);
        file_put_contents($target . '/wp-content/new-plugin.php', 'new plugin');
        file_put_contents($target . '/wp-content/index.php', 'ignored overwrite');

        $syncer->syncCustomContentDirectory($target, 'app-content');

        $this->assertFileDoesNotExist($target . '/wp-content');
        $this->assertFileExists($target . '/app-content/new-plugin.php');
        $this->assertSame('wp-content index', file_get_contents($target . '/app-content/index.php'));
    }

    public function testRunCleanInstallation(): void
    {
        $source = $this->testTmpDir . '/donor';
        $target = $this->testTmpDir . '/webroot';

        mkdir($source . '/wp-includes', 0755, true);
        mkdir($source . '/wp-admin', 0755, true);
        mkdir($source . '/wp-content', 0755, true);

        file_put_contents($source . '/wp-includes/version.php', "<?php\n\$wp_version = '6.7.1';\n");
        file_put_contents($source . '/wp-admin/admin.php', 'admin core');
        file_put_contents($source . '/index.php', 'frontend index');

        $syncer = new WordPressSync($source, $target);

        ob_start();
        $exitCode = $syncer->run();
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Initializing clean WordPress installation (version 6.7.1)', $output);
        $this->assertStringContainsString('Clean installation complete', $output);

        $this->assertFileExists($target . '/index.php');
        $this->assertFileExists($target . '/wp-includes/version.php');
        $this->assertFileExists($target . '/wp-admin/admin.php');
    }

    public function testRunCoreUpToDate(): void
    {
        $source = $this->testTmpDir . '/donor';
        $target = $this->testTmpDir . '/webroot';

        mkdir($source . '/wp-includes', 0755, true);
        file_put_contents($source . '/wp-includes/version.php', "<?php\n\$wp_version = '6.7.1';\n");
        file_put_contents($source . '/index.php', 'donor index');

        mkdir($target . '/wp-includes', 0755, true);
        file_put_contents($target . '/wp-includes/version.php', "<?php\n\$wp_version = '6.7.1';\n");
        file_put_contents($target . '/index.php', 'live index');

        $syncer = new WordPressSync($source, $target);

        ob_start();
        $exitCode = $syncer->run();
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('WordPress core is up-to-date (version 6.7.1)', $output);
    }

    public function testRunVersionMismatchUpgrade(): void
    {
        $source = $this->testTmpDir . '/donor';
        $target = $this->testTmpDir . '/webroot';

        mkdir($source . '/wp-includes', 0755, true);
        mkdir($source . '/wp-admin', 0755, true);
        mkdir($source . '/wp-content', 0755, true);
        file_put_contents($source . '/wp-includes/version.php', "<?php\n\$wp_version = '6.8.0';\n");
        file_put_contents($source . '/wp-admin/admin.php', 'new admin 6.8');
        file_put_contents($source . '/wp-login.php', 'new login 6.8');
        file_put_contents($source . '/index.php', 'new index 6.8');

        mkdir($target . '/wp-includes', 0755, true);
        mkdir($target . '/wp-admin', 0755, true);
        mkdir($target . '/wp-content/uploads', 0755, true);
        file_put_contents($target . '/wp-includes/version.php', "<?php\n\$wp_version = '6.7.1';\n");
        file_put_contents($target . '/wp-admin/admin.php', 'old admin 6.7');
        file_put_contents($target . '/wp-login.php', 'old login 6.7');
        file_put_contents($target . '/index.php', 'old index 6.7');
        file_put_contents($target . '/wp-content/uploads/photo.jpg', 'user photo');

        $syncer = new WordPressSync($source, $target);

        ob_start();
        $exitCode = $syncer->run();
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Upgrading WordPress core from 6.7.1 to 6.8.0', $output);
        $this->assertStringContainsString('WordPress core synchronized successfully to 6.8.0', $output);

        $this->assertSame('new admin 6.8', file_get_contents($target . '/wp-admin/admin.php'));
        $this->assertSame('new login 6.8', file_get_contents($target . '/wp-login.php'));
        $this->assertSame('user photo', file_get_contents($target . '/wp-content/uploads/photo.jpg'));
    }

    public function testRunMissingSourceVersionThrowsError(): void
    {
        $source = $this->testTmpDir . '/donor';
        $target = $this->testTmpDir . '/webroot';

        mkdir($source, 0755, true);

        $syncer = new WordPressSync($source, $target);

        ob_start();
        $exitCode = $syncer->run();
        ob_end_clean();

        $this->assertSame(1, $exitCode);
    }

    public function testCopyRecursiveSkipsSymlinks(): void
    {
        $syncer = new WordPressSync();
        $src = $this->testTmpDir . '/source_symlink';
        $dst = $this->testTmpDir . '/dest_symlink';
        mkdir($src, 0755, true);
        file_put_contents($src . '/real.txt', 'real');
        symlink($src . '/real.txt', $src . '/link.txt');

        $syncer->copyRecursive($src, $dst);
        $this->assertFileExists($dst . '/real.txt');
        $this->assertFileDoesNotExist($dst . '/link.txt');

        $syncer->copyRecursive($src . '/link.txt', $dst . '/link.txt');
        $this->assertFileDoesNotExist($dst . '/link.txt');
    }

    public function testCopyRecursiveThrowsWhenDirectoryCreationFails(): void
    {
        $syncer = new WordPressSync();
        $src = $this->testTmpDir . '/source_dir';
        mkdir($src, 0755, true);

        $readOnlyParent = $this->testTmpDir . '/ro_parent_1';
        mkdir($readOnlyParent, 0555, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create directory');

        try {
            $syncer->copyRecursive($src, $readOnlyParent . '/sub_dest');
        } finally {
            chmod($readOnlyParent, 0755);
        }
    }

    public function testCopyRecursiveThrowsWhenDirectoryUnreadable(): void
    {
        $syncer = new WordPressSync();
        $src = $this->testTmpDir . '/unreadable_src';
        mkdir($src, 0000, true);

        $dst = $this->testTmpDir . '/unreadable_dst';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read directory');

        try {
            $syncer->copyRecursive($src, $dst);
        } finally {
            chmod($src, 0755);
        }
    }

    public function testCopyRecursiveThrowsWhenFileCannotBeWritten(): void
    {
        $syncer = new WordPressSync();
        $src = $this->testTmpDir . '/source_file.txt';
        file_put_contents($src, 'data');

        $readOnlyParent = $this->testTmpDir . '/ro_parent_2';
        mkdir($readOnlyParent, 0555, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to copy file');

        try {
            $syncer->copyRecursive($src, $readOnlyParent . '/dest_file.txt');
        } finally {
            chmod($readOnlyParent, 0755);
        }
    }

    public function testAtomicSyncDirectoryWithNonExistentSourceReturnsEarly(): void
    {
        $syncer = new WordPressSync();
        $target = $this->testTmpDir . '/atomic_target';
        $syncer->atomicSyncDirectory($this->testTmpDir . '/non_existent_source', $target);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function testAtomicSyncDirectoryCleansUpTmpOnFailure(): void
    {
        $syncer = new WordPressSync();
        $src = $this->testTmpDir . '/atomic_src';
        mkdir($src, 0755, true);
        file_put_contents($src . '/unreadable.txt', 'test');
        chmod($src . '/unreadable.txt', 0000);

        $target = $this->testTmpDir . '/atomic_target';
        mkdir($target, 0755, true);

        $this->expectException(RuntimeException::class);

        try {
            $syncer->atomicSyncDirectory($src, $target);
        } finally {
            chmod($src . '/unreadable.txt', 0644);
        }
    }

    public function testSyncCustomContentDirectoryThrowsWhenRenameFails(): void
    {
        $syncer = new WordPressSync();
        $target = $this->testTmpDir . '/ro_target_rename';
        mkdir($target . '/wp-content', 0755, true);
        chmod($target, 0555);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to rename');

        try {
            $syncer->syncCustomContentDirectory($target, 'app-content');
        } finally {
            chmod($target, 0755);
        }
    }

    public function testSyncCustomContentDirectoryEarlyReturnWhenSourceMissing(): void
    {
        $syncer = new WordPressSync();
        $target = $this->testTmpDir . '/no_wp_content_target';
        mkdir($target, 0755, true);

        $syncer->syncCustomContentDirectory($target, 'app-content');
        $this->assertDirectoryDoesNotExist($target . '/app-content');
    }

    public function testRunVersionMismatchRollbackAndStaleDirCleanup(): void
    {
        $source = $this->testTmpDir . '/donor_rollback';
        $target = $this->testTmpDir . '/webroot_rollback';

        mkdir($source . '/wp-includes', 0755, true);
        mkdir($source . '/wp-admin', 0755, true);
        file_put_contents($source . '/wp-includes/version.php', "<?php\n\$wp_version = '6.7.0';\n");
        file_put_contents($source . '/wp-admin/admin.php', 'donor admin');
        file_put_contents($source . '/index.php', 'donor index');

        mkdir($target . '/wp-includes', 0755, true);
        mkdir($target . '/wp-admin', 0755, true);
        mkdir($target . '/.wp-admin.tmp_1234abcd', 0755, true);
        mkdir($target . '/.wp-includes.bak_5678ef01', 0755, true);
        file_put_contents($target . '/wp-includes/version.php', "<?php\n\$wp_version = '6.7.1';\n");
        file_put_contents($target . '/wp-admin/admin.php', 'live admin');
        file_put_contents($target . '/index.php', 'live index');

        $syncer = new WordPressSync($source, $target);

        ob_start();
        $exitCode = $syncer->run();
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Rolling back WordPress core from 6.7.1 to 6.7.0', $output);
        $this->assertDirectoryDoesNotExist($target . '/.wp-admin.tmp_1234abcd');
        $this->assertDirectoryDoesNotExist($target . '/.wp-includes.bak_5678ef01');
    }

    public function testDeleteRecursiveHandlesUnreadableDirectory(): void
    {
        $syncer = new WordPressSync();
        $dir = $this->testTmpDir . '/unreadable_dir';
        mkdir($dir, 0000, true);

        try {
            $syncer->deleteRecursive($dir);
            $this->assertFileExists($dir);
        } finally {
            chmod($dir, 0755);
        }
    }

    public function testRunHandlesPhpWarningAsFatalError(): void
    {
        $source = $this->testTmpDir . '/donor_warning';
        $target = $this->testTmpDir . '/target_warning';

        mkdir($source . '/wp-includes', 0755, true);
        mkdir($source . '/wp-admin', 0755, true);
        file_put_contents($source . '/wp-includes/version.php', "<?php\n\$wp_version = '6.8.0';\n");
        file_put_contents($source . '/wp-admin/admin.php', 'admin');
        file_put_contents($source . '/index.php', 'index');
        file_put_contents($source . '/unreadable.php', 'unreadable');

        mkdir($target . '/wp-includes', 0755, true);
        mkdir($target . '/wp-admin', 0755, true);
        file_put_contents($target . '/wp-includes/version.php', "<?php\n\$wp_version = '6.7.0';\n");

        $syncer = new WordPressSync($source, $target);

        chmod($source . '/unreadable.php', 0000);

        $prevReporting = error_reporting(E_ALL);
        ob_start();
        try {
            $exitCode = $syncer->run();
        } finally {
            error_reporting($prevReporting);
            chmod($source . '/unreadable.php', 0644);
            ob_end_clean();
        }

        $this->assertSame(1, $exitCode);
    }

    public function testWpSyncScriptDirectExecution(): void
    {
        $script = dirname(__DIR__) . '/build/wp-sync.php';
        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($script));
        $output = shell_exec($command);
        $this->assertIsString($output);
        $this->assertStringContainsString('[wp-sync] Source directory /usr/src/wordpress not found.', $output);
    }

    public function testAtomicSyncDirectoryThrowsWhenMoveToBackupFails(): void
    {
        $mockFs = $this->createStub(FilesystemInterface::class);
        $mockFs->method('isDir')->willReturn(true);
        $mockFs->method('scandir')->willReturn([]);
        $mockFs->method('rename')->willReturn(false);

        $syncer = new WordPressSync('/src', '/dst', $mockFs);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to move');

        $syncer->atomicSyncDirectory('/src', '/dst');
    }

    public function testAtomicSyncDirectoryRollbackWhenActivationFails(): void
    {
        $mockFs = $this->createStub(FilesystemInterface::class);
        $mockFs->method('isDir')->willReturn(true);
        $mockFs->method('scandir')->willReturn([]);

        $mockFs->method('rename')
            ->willReturnCallback(static function (string $source, string $target): bool {
                if (str_contains($source, '.bak_') || str_contains($target, '.bak_')) {
                    return true;
                }
                return false;
            });

        $syncer = new WordPressSync('/src', '/dst', $mockFs);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to activate new');

        $syncer->atomicSyncDirectory('/src', '/dst');
    }

    public function testRunCoreUpgradeThrowsWhenCopyFileFails(): void
    {
        $mockFs = $this->createStub(FilesystemInterface::class);
        $mockFs->method('isDir')->willReturnCallback(static function (string $path): bool {
            return in_array(
                $path,
                ['/donor', '/webroot', '/donor/wp-admin', '/donor/wp-includes'],
                true
            );
        });
        $mockFs->method('isFile')->willReturnCallback(static function (string $path): bool {
            return str_ends_with($path, '.php');
        });
        $mockFs->method('fileGetContents')->willReturnCallback(static function (string $path): string {
            if (str_contains($path, 'donor/wp-includes/version.php')) {
                return "\$wp_version = '6.8.0';";
            }
            return "\$wp_version = '6.7.0';";
        });
        $mockFs->method('fileExists')->willReturn(true);
        $mockFs->method('scandir')->willReturnCallback(static function (string $path): array {
            if ($path === '/donor') {
                return ['wp-login.php'];
            }
            return [];
        });
        $mockFs->method('mkdir')->willReturn(true);
        $mockFs->method('rename')->willReturn(true);
        $mockFs->method('copy')->willReturn(false);

        $syncer = new WordPressSync('/donor', '/webroot', $mockFs);

        ob_start();
        $exitCode = $syncer->run();
        ob_end_clean();

        $this->assertSame(1, $exitCode);
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
