<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpPerformance;

final class WpPerformanceTest extends TestCase
{
    /**
     * @var string|null
     */
    private ?string $originalPagenow = null;

    /**
     * @var string|null
     */
    private ?string $originalScriptName = null;

    /**
     * @var string|null
     */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPagenow = $GLOBALS['pagenow'] ?? null;
        $this->originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalPagenow !== null) {
            $GLOBALS['pagenow'] = $this->originalPagenow;
        } else {
            unset($GLOBALS['pagenow']);
        }

        if ($this->originalScriptName !== null) {
            $_SERVER['SCRIPT_NAME'] = $this->originalScriptName;
        } else {
            unset($_SERVER['SCRIPT_NAME']);
        }

        if ($this->originalPhpSelf !== null) {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        } else {
            unset($_SERVER['PHP_SELF']);
        }

        putenv('WP_PERF_FORCE_IPV4');
        putenv('WP_PERF_FASTCGI_FINISH');

        parent::tearDown();
    }

    public function testDefaultStateEnablesIpv4AndFastcgi(): void
    {
        $perf = new WpPerformance();

        $this->assertTrue($perf->isIpv4Enforced());
        $this->assertTrue($perf->isFastcgiFinishEnabled());
    }

    public function testIpv4DisabledViaEnvironmentVariable(): void
    {
        $disabledValues = ['false', '0', 'off', 'no', 'FALSE'];

        foreach ($disabledValues as $val) {
            $perf = new WpPerformance(envGetter: fn(string $name): string => $val);
            $this->assertFalse($perf->isIpv4Enforced());
        }
    }

    public function testIpv4EnabledViaEnvironmentVariable(): void
    {
        $enabledValues = ['true', '1', 'on', 'yes', 'TRUE', ''];

        foreach ($enabledValues as $val) {
            $perf = new WpPerformance(envGetter: fn(string $name): string => $val);
            $this->assertTrue($perf->isIpv4Enforced());
        }
    }

    public function testFastcgiFinishDisabledViaEnvironmentVariable(): void
    {
        $disabledValues = ['false', '0', 'off', 'no', 'FALSE'];

        foreach ($disabledValues as $val) {
            $perf = new WpPerformance(envGetter: fn(string $name): string => $val);
            $this->assertFalse($perf->isFastcgiFinishEnabled());
        }
    }

    public function testFastcgiFinishEnabledViaEnvironmentVariable(): void
    {
        $enabledValues = ['true', '1', 'on', 'yes', ''];

        foreach ($enabledValues as $val) {
            $perf = new WpPerformance(envGetter: fn(string $name): string => $val);
            $this->assertTrue($perf->isFastcgiFinishEnabled());
        }
    }

    public function testConfigureCurlReturnsFalseWhenIpv4Disabled(): void
    {
        $configured = false;
        $perf = new WpPerformance(
            curlConfigurator: function () use (&$configured): bool {
                $configured = true;
                return true;
            },
            envGetter: fn(): string => 'false'
        );

        $dummyHandle = new \stdClass();
        $result = $perf->configureCurl($dummyHandle);

        $this->assertFalse($result);
        $this->assertFalse($configured);
    }

    public function testConfigureCurlReturnsFalseForInvalidHandle(): void
    {
        $perf = new WpPerformance();

        $this->assertFalse($perf->configureCurl(null));
        $this->assertFalse($perf->configureCurl('invalid-handle'));
        $this->assertFalse($perf->configureCurl(12345));
        $this->assertFalse($perf->configureCurl([]));
        $this->assertFalse($perf->configureCurl(new \stdClass()));
    }

    public function testConfigureCurlWithCustomConfigurator(): void
    {
        /** @var array{object|resource|null, int|null, int|null} $receivedArgs */
        $receivedArgs = [null, null, null];

        $configurator = function (mixed $handle, int $option, int $value) use (&$receivedArgs): bool {
            $receivedArgs = [$handle, $option, $value];
            return true;
        };

        $perf = new WpPerformance(curlConfigurator: $configurator);
        $dummyHandle = new \stdClass();

        $result = $perf->configureCurl($dummyHandle);

        $this->assertTrue($result);
        $this->assertSame($dummyHandle, $receivedArgs[0]);
        $this->assertSame(CURLOPT_IPRESOLVE, $receivedArgs[1]);
        $this->assertSame(CURL_IPRESOLVE_V4, $receivedArgs[2]);
    }

    public function testConfigureCurlWithNativeCurlHandle(): void
    {
        $perf = new WpPerformance();
        $ch = curl_init('https://example.com');
        $this->assertInstanceOf(\CurlHandle::class, $ch);

        $result = $perf->configureCurl($ch);

        $this->assertTrue($result);
    }

    public function testShouldFinishFastcgiReturnsFalseWhenDisabled(): void
    {
        $perf = new WpPerformance(envGetter: fn(): string => 'false');

        $this->assertFalse($perf->shouldFinishFastcgi('update.php'));
        $this->assertFalse($perf->shouldFinishFastcgi('update-core.php'));
    }

    public function testShouldFinishFastcgiWithExplicitPages(): void
    {
        $perf = new WpPerformance();

        $this->assertTrue($perf->shouldFinishFastcgi('update.php'));
        $this->assertTrue($perf->shouldFinishFastcgi('update-core.php'));
        $this->assertFalse($perf->shouldFinishFastcgi('plugins.php'));
        $this->assertFalse($perf->shouldFinishFastcgi('index.php'));
        $this->assertFalse($perf->shouldFinishFastcgi(''));
        $this->assertFalse($perf->shouldFinishFastcgi(null));
    }

    public function testShouldFinishFastcgiResolvesPageFromPagenowGlobal(): void
    {
        $perf = new WpPerformance();

        $GLOBALS['pagenow'] = 'update.php';
        $this->assertTrue($perf->shouldFinishFastcgi());

        $GLOBALS['pagenow'] = '/wp-admin/update-core.php';
        $this->assertTrue($perf->shouldFinishFastcgi());

        $GLOBALS['pagenow'] = 'themes.php';
        $this->assertFalse($perf->shouldFinishFastcgi());

        unset($GLOBALS['pagenow']);
        $this->assertFalse($perf->shouldFinishFastcgi());
    }

    public function testShouldFinishFastcgiResolvesPageFromScriptNameServer(): void
    {
        $perf = new WpPerformance();
        unset($GLOBALS['pagenow']);

        $_SERVER['SCRIPT_NAME'] = '/wp-admin/update-core.php';
        $this->assertTrue($perf->shouldFinishFastcgi());

        $_SERVER['SCRIPT_NAME'] = '/wp-admin/options-general.php';
        $this->assertFalse($perf->shouldFinishFastcgi());

        unset($_SERVER['SCRIPT_NAME']);
        $this->assertFalse($perf->shouldFinishFastcgi());
    }

    public function testShouldFinishFastcgiResolvesPageFromPhpSelfWhenScriptNameEmpty(): void
    {
        $perf = new WpPerformance();
        $GLOBALS['pagenow'] = '   ';
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['PHP_SELF'] = '/wp-admin/update.php';

        $this->assertTrue($perf->shouldFinishFastcgi());

        $_SERVER['PHP_SELF'] = '   ';
        $this->assertFalse($perf->shouldFinishFastcgi());
    }

    public function testCustomTargetPages(): void
    {
        $perf = new WpPerformance(targetPages: ['custom-upgrader.php']);

        $this->assertTrue($perf->shouldFinishFastcgi('custom-upgrader.php'));
        $this->assertFalse($perf->shouldFinishFastcgi('update.php'));
        $this->assertFalse($perf->shouldFinishFastcgi('update-core.php'));
    }

    public function testFinishFastcgiReturnsFalseWhenPageDoesNotMatch(): void
    {
        $flushed = false;
        $perf = new WpPerformance(
            fastcgiFlusher: function () use (&$flushed): bool {
                $flushed = true;
                return true;
            }
        );

        $result = $perf->finishFastcgi('dashboard.php');

        $this->assertFalse($result);
        $this->assertFalse($flushed);
    }

    public function testFinishFastcgiInvokesInjectedFlusherOnTargetPage(): void
    {
        $flushed = false;
        $perf = new WpPerformance(
            fastcgiFlusher: function () use (&$flushed): bool {
                $flushed = true;
                return true;
            }
        );

        $result = $perf->finishFastcgi('update.php');

        $this->assertTrue($result);
        $this->assertTrue($flushed);

        $secondCallResult = $perf->finishFastcgi('update.php');
        $this->assertFalse($secondCallResult);
    }

    public function testFinishFastcgiReturnsFalseWhenFunctionDoesNotExist(): void
    {
        $perf = new WpPerformance();

        $this->assertFalse($perf->finishFastcgi('update.php'));
    }

    public function testOnUpgraderProcessCompleteReturnsFalseWhenFunctionDoesNotExist(): void
    {
        $perf = new WpPerformance();

        $this->assertFalse($perf->onUpgraderProcessComplete());
    }

    public function testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist(): void
    {
        $perf = new WpPerformance();
        $perf->registerHooks();

        $this->assertArrayNotHasKey('test_registered_actions', $GLOBALS);
    }

    #[Depends('testFinishFastcgiReturnsFalseWhenFunctionDoesNotExist')]
    public function testFinishFastcgiFallsBackToBuiltinOrFalse(): void
    {
        ensure_wordpress_stubs();
        $perf = new WpPerformance();

        $result = $perf->finishFastcgi('update.php');
        $this->assertTrue($result);
    }

    public function testOnUpgraderProcessCompleteCallsFlusherWhenEnabled(): void
    {
        $flushed = false;
        $perf = new WpPerformance(
            fastcgiFlusher: function () use (&$flushed): bool {
                $flushed = true;
                return true;
            }
        );

        $result = $perf->onUpgraderProcessComplete();

        $this->assertTrue($result);
        $this->assertTrue($flushed);
    }

    public function testOnUpgraderProcessCompleteReturnsFalseWhenDisabled(): void
    {
        $flushed = false;
        $perf = new WpPerformance(
            fastcgiFlusher: function () use (&$flushed): bool {
                $flushed = true;
                return true;
            },
            envGetter: fn(): string => 'false'
        );

        $result = $perf->onUpgraderProcessComplete();

        $this->assertFalse($result);
        $this->assertFalse($flushed);
    }

    #[Depends('testOnUpgraderProcessCompleteReturnsFalseWhenFunctionDoesNotExist')]
    public function testOnUpgraderProcessCompleteFallsBackToBuiltinOrFalse(): void
    {
        ensure_wordpress_stubs();
        $perf = new WpPerformance();

        $result = $perf->onUpgraderProcessComplete();
        $this->assertTrue($result);
    }

    public function testOnUpgraderProcessCompletePreventsSubsequentFastcgiFinish(): void
    {
        $flushCount = 0;
        $perf = new WpPerformance(
            fastcgiFlusher: function () use (&$flushCount): bool {
                $flushCount++;
                return true;
            }
        );

        $firstResult = $perf->onUpgraderProcessComplete();
        $secondResult = $perf->onUpgraderProcessComplete();
        $shutdownResult = $perf->finishFastcgi('update.php');

        $this->assertTrue($firstResult);
        $this->assertFalse($secondResult);
        $this->assertFalse($shutdownResult);
        $this->assertSame(1, $flushCount);
    }

    public function testRegisterHooksWithInjectedRegistrar(): void
    {
        /** @var list<array{string, callable, int, int}> $registered */
        $registered = [];

        $registrar = function (
            string $tag,
            callable $callback,
            int $priority,
            int $acceptedArgs
        ) use (&$registered): void {
            $registered[] = [$tag, $callback, $priority, $acceptedArgs];
        };

        $perf = new WpPerformance();
        $perf->registerHooks($registrar);

        $this->assertCount(3, $registered);
        $this->assertSame('http_api_curl', $registered[0][0]);
        $this->assertSame(10, $registered[0][2]);
        $this->assertSame(1, $registered[0][3]);

        $this->assertSame('upgrader_process_complete', $registered[1][0]);
        $this->assertSame(0, $registered[1][2]);
        $this->assertSame(2, $registered[1][3]);

        $this->assertSame('shutdown', $registered[2][0]);
        $this->assertSame(0, $registered[2][2]);
        $this->assertSame(0, $registered[2][3]);
    }

    #[Depends('testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    public function testRegisterHooksWithoutRegistrarUsesGlobalAddAction(): void
    {
        ensure_wordpress_stubs();
        $GLOBALS['test_registered_actions'] = [];

        $perf = new WpPerformance();
        $perf->registerHooks();

        $this->assertArrayHasKey('http_api_curl', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('upgrader_process_complete', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('shutdown', $GLOBALS['test_registered_actions']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenAddActionExists(): void
    {
        ensure_wordpress_stubs();
        $GLOBALS['test_registered_actions'] = [];
        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-performance.php.example';

        $this->assertArrayHasKey('http_api_curl', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('upgrader_process_complete', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('shutdown', $GLOBALS['test_registered_actions']);
    }
}
