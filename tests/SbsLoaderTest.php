<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SbsLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sbs_loader_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS');
        putenv('SBS_PLATFORM_MU_DIR');

        if (is_dir($this->tempDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function testExecutionWithNoneKeywordLoadsNothing(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS=none');
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir);
        file_put_contents($this->tempDir . '/wp-notify.php', '<?php $GLOBALS["test_none_loaded"] = true;');

        require __DIR__ . '/../mu-plugins/sbs-loader.php';

        $this->assertFalse(isset($GLOBALS['test_none_loaded']));
    }

    public function testExecutionWithDefaultFallbackLoadsNotify(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS');
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir);
        file_put_contents($this->tempDir . '/wp-notify.php', '<?php $GLOBALS["test_default_loaded"] = true;');

        require __DIR__ . '/../mu-plugins/sbs-loader.php';

        $this->assertTrue($GLOBALS['test_default_loaded'] ?? false);
    }

    public function testExecutionRejectsAllAndWildcard(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS=all');
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir);
        file_put_contents($this->tempDir . '/wp-all.php', '<?php $GLOBALS["test_all_loaded"] = true;');

        require __DIR__ . '/../mu-plugins/sbs-loader.php';
        $this->assertFalse(isset($GLOBALS['test_all_loaded']));

        putenv('WP_ENABLED_MU_PLUGINS=*');
        require __DIR__ . '/../mu-plugins/sbs-loader.php';
        $this->assertFalse(isset($GLOBALS['test_all_loaded']));
    }

    public function testExecutionFiltersCustomTokens(): void
    {
        putenv(
            'WP_ENABLED_MU_PLUGINS=snippets, performance, snippets, ../bad!slug, bad_underscore, bad123, ALL, none, '
        );
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir);
        file_put_contents($this->tempDir . '/wp-snippets.php', '<?php $GLOBALS["test_snippets_loaded"] = true;');
        file_put_contents($this->tempDir . '/wp-performance.php', '<?php $GLOBALS["test_perf_loaded"] = true;');

        require __DIR__ . '/../mu-plugins/sbs-loader.php';

        $this->assertTrue($GLOBALS['test_snippets_loaded'] ?? false);
        $this->assertTrue($GLOBALS['test_perf_loaded'] ?? false);
    }

    public function testExecutionSkipsWhenPlatformDirDoesNotExist(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS=notify');
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir . '/non_existent');

        require __DIR__ . '/../mu-plugins/sbs-loader.php';

        $this->assertTrue(true);
    }

    public function testExecutionWithEmptyPlatformDirFallback(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS=notify');
        putenv('SBS_PLATFORM_MU_DIR=   ');

        require __DIR__ . '/../mu-plugins/sbs-loader.php';

        $this->assertTrue(true);
    }

    public function testExecutionLoadsExistingFilesOnly(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS=notify, missing');
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir);
        file_put_contents($this->tempDir . '/wp-notify.php', '<?php $GLOBALS["test_exist_loaded"] = true;');

        require __DIR__ . '/../mu-plugins/sbs-loader.php';

        $this->assertTrue($GLOBALS['test_exist_loaded'] ?? false);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileRequireExecutesPlatformLoader(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS=notify');
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir);
        file_put_contents(
            $this->tempDir . '/wp-notify.php',
            '<?php $GLOBALS["test_require_loaded"] = true;'
        );

        require __DIR__ . '/../mu-plugins/sbs-loader.php';

        $this->assertTrue($GLOBALS['test_require_loaded'] ?? false);
    }
}
