<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SbsPlatformLoader;

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
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function testResolveEnabledSlugsWithNoneKeyword(): void
    {
        $loader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => $name === 'WP_ENABLED_MU_PLUGINS' ? 'none' : false
        );

        $this->assertSame([], $loader->resolveEnabledSlugs());
    }

    public function testResolveEnabledSlugsDefaultFallback(): void
    {
        $loader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => false
        );

        $this->assertSame(['notify'], $loader->resolveEnabledSlugs());

        $emptyLoader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => '   '
        );

        $this->assertSame(['notify'], $emptyLoader->resolveEnabledSlugs());
    }

    public function testResolveEnabledSlugsRejectsAllAndWildcard(): void
    {
        $allLoader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => 'all'
        );
        $this->assertSame([], $allLoader->resolveEnabledSlugs());

        $starLoader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => '*'
        );
        $this->assertSame([], $starLoader->resolveEnabledSlugs());
    }

    public function testResolveEnabledSlugsCustomTokensFiltering(): void
    {
        $slugList = 'snippets, performance, snippets, ../bad!slug, bad_underscore, bad123, ALL, none, ';
        $loader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => $slugList
        );

        $this->assertSame(['snippets', 'performance'], $loader->resolveEnabledSlugs());
    }

    public function testResolvePlatformDirDefaultAndCustom(): void
    {
        $defaultLoader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => false
        );
        $this->assertSame('/usr/local/share/wordpress/mu-plugins', $defaultLoader->resolvePlatformDir());

        $emptyLoader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => '  '
        );
        $this->assertSame('/usr/local/share/wordpress/mu-plugins', $emptyLoader->resolvePlatformDir());

        $customLoader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => '/opt/custom/mu-plugins'
        );
        $this->assertSame('/opt/custom/mu-plugins', $customLoader->resolvePlatformDir());
    }

    public function testLoadSkipsWhenNoSlugsEnabled(): void
    {
        $loadedFiles = [];
        $loader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => 'none',
            fileIncluder: static function (string $path) use (&$loadedFiles): void {
                $loadedFiles[] = $path;
            }
        );

        $loader->load();
        $this->assertSame([], $loadedFiles);
    }

    public function testLoadSkipsWhenPlatformDirDoesNotExist(): void
    {
        $loadedFiles = [];
        $loader = new SbsPlatformLoader(
            envGetter: static fn(string $name): string|false => 'notify',
            dirChecker: static fn(string $path): bool => false,
            fileIncluder: static function (string $path) use (&$loadedFiles): void {
                $loadedFiles[] = $path;
            }
        );

        $loader->load();
        $this->assertSame([], $loadedFiles);
    }

    public function testLoadIncludesExistingFilesOnly(): void
    {
        $loadedFiles = [];
        $loader = new SbsPlatformLoader(
            envGetter: static function (string $name): string|false {
                if ($name === 'WP_ENABLED_MU_PLUGINS') {
                    return 'notify, performance, missing';
                }
                return '/virtual/mu-plugins';
            },
            dirChecker: static fn(string $path): bool => true,
            fileChecker: static fn(string $path): bool => str_contains($path, 'notify')
                || str_contains($path, 'performance'),
            fileIncluder: static function (string $path) use (&$loadedFiles): void {
                $loadedFiles[] = $path;
            }
        );

        $loader->load();
        $this->assertSame([
            '/virtual/mu-plugins/wp-notify.php',
            '/virtual/mu-plugins/wp-performance.php',
        ], $loadedFiles);
    }

    public function testDefaultBuiltinFallbacks(): void
    {
        putenv('WP_ENABLED_MU_PLUGINS=notify');
        putenv('SBS_PLATFORM_MU_DIR=' . $this->tempDir);

        $targetFile = $this->tempDir . '/wp-notify.php';
        file_put_contents($targetFile, '<?php $GLOBALS["test_builtin_loaded"] = true;');

        $loader = new SbsPlatformLoader();
        $loader->load();

        $this->assertTrue($GLOBALS['test_builtin_loaded'] ?? false);
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

        require __DIR__ . '/../examples/data/wp-content/mu-plugins/sbs-loader.php.example';

        $this->assertTrue($GLOBALS['test_require_loaded'] ?? false);
    }
}
