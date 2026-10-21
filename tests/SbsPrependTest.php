<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\TestCase;

final class SbsPrependTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sbs_prepend_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        putenv('SBS_TEST_MODE');
        putenv('SBS_TEST_WORDFENCE_WAF_PATH');

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

        unset($GLOBALS['test_wordfence_waf_loaded']);

        parent::tearDown();
    }

    public function testExecutionGracefullySkipsWhenFileDoesNotExist(): void
    {
        putenv('SBS_TEST_MODE=1');
        putenv('SBS_TEST_WORDFENCE_WAF_PATH=' . $this->tempDir . '/non_existent_wordfence-waf.php');

        require __DIR__ . '/../prepend/sbs-prepend.php';

        $this->assertFalse(isset($GLOBALS['test_wordfence_waf_loaded']));
    }

    public function testExecutionLoadsConfiguredWafFileInTestMode(): void
    {
        $targetWaf = $this->tempDir . '/wordfence-waf.php';
        file_put_contents($targetWaf, '<?php $GLOBALS["test_wordfence_waf_loaded"] = true;');
        putenv('SBS_TEST_MODE=1');
        putenv('SBS_TEST_WORDFENCE_WAF_PATH=' . $targetWaf);

        require __DIR__ . '/../prepend/sbs-prepend.php';

        $this->assertTrue($GLOBALS['test_wordfence_waf_loaded'] ?? false);
    }

    public function testExecutionIgnoresTestPathWhenTestModeDisabled(): void
    {
        $targetWaf = $this->tempDir . '/wordfence-waf.php';
        file_put_contents($targetWaf, '<?php $GLOBALS["test_wordfence_waf_loaded"] = true;');
        putenv('SBS_TEST_MODE');
        putenv('SBS_TEST_WORDFENCE_WAF_PATH=' . $targetWaf);

        require __DIR__ . '/../prepend/sbs-prepend.php';

        $this->assertFalse(isset($GLOBALS['test_wordfence_waf_loaded']));
    }

    public function testExecutionRejectsPathsNotEndingWithWordfenceWafPhp(): void
    {
        $targetMalicious = $this->tempDir . '/arbitrary_script.php';
        file_put_contents($targetMalicious, '<?php $GLOBALS["test_wordfence_waf_loaded"] = true;');
        putenv('SBS_TEST_MODE=1');
        putenv('SBS_TEST_WORDFENCE_WAF_PATH=' . $targetMalicious);

        require __DIR__ . '/../prepend/sbs-prepend.php';

        $this->assertFalse(isset($GLOBALS['test_wordfence_waf_loaded']));
    }

    public function testExecutionWithEmptyTestPathDefaultsGracefully(): void
    {
        putenv('SBS_TEST_MODE=1');
        putenv('SBS_TEST_WORDFENCE_WAF_PATH=');

        require __DIR__ . '/../prepend/sbs-prepend.php';

        $this->assertFalse(isset($GLOBALS['test_wordfence_waf_loaded']));
    }
}
