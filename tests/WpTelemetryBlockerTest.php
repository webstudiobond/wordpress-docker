<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpTelemetryBlocker;

final class WpTelemetryBlockerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WP_TELEMETRY_BLOCKED_URLS');
        unset(
            $GLOBALS['test_is_user_logged_in'],
            $GLOBALS['test_wp_doing_ajax'],
            $GLOBALS['test_wp_is_json_request']
        );
        parent::tearDown();
    }

    public function testDefaultStateBlocksNothingAndDoesNotInject(): void
    {
        $blocker = new WpTelemetryBlocker();

        $this->assertSame([], $blocker->getBlockedUrls());
        $this->assertFalse($blocker->shouldInject());
        $this->assertSame('', $blocker->buildScriptTag());

        $this->expectOutputString('');
        $blocker->injectSilencer();
        $blocker->injectFrontend();
    }

    #[Depends('testDefaultStateBlocksNothingAndDoesNotInject')]
    public function testFallbacksWhenWordPressStateFunctionsDoNotExist(): void
    {
        $blocker = new WpTelemetryBlocker(
            blockedUrls: ['telemetry.example.com']
        );

        $this->assertTrue($blocker->shouldInject());

        $this->expectOutputString('');
        $blocker->injectFrontend();
    }

    #[Depends('testFallbacksWhenWordPressStateFunctionsDoNotExist')]
    public function testGlobalWordPressStateFunctionFallbacks(): void
    {
        ensure_telemetry_stubs();

        $blocker = new WpTelemetryBlocker(
            blockedUrls: ['telemetry.example.com']
        );

        $GLOBALS['test_wp_doing_ajax'] = true;
        $this->assertFalse($blocker->shouldInject());

        $GLOBALS['test_wp_doing_ajax'] = false;
        $GLOBALS['test_wp_is_json_request'] = true;
        $this->assertFalse($blocker->shouldInject());

        $GLOBALS['test_wp_is_json_request'] = false;
        $this->assertTrue($blocker->shouldInject());

        $GLOBALS['test_is_user_logged_in'] = true;
        ob_start();
        $blocker->injectFrontend();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('<script id="wp-telemetry-blocker">', $output);
        $this->assertStringContainsString('telemetry.example.com', $output);
    }

    public function testParsesAndDeduplicatesBlockedUrlsFromEnvironment(): void
    {
        $blocker = new WpTelemetryBlocker(
            envGetter: static fn(string $name): string => $name === 'WP_TELEMETRY_BLOCKED_URLS'
                ? ' telemetry.example.com , tracker.example.net/api/v1 , , telemetry.example.com '
                : ''
        );

        $this->assertSame(
            ['telemetry.example.com', 'tracker.example.net/api/v1'],
            $blocker->getBlockedUrls()
        );
    }

    public function testShouldInjectReturnsFalseForAjaxOrJsonRequests(): void
    {
        $ajaxBlocker = new WpTelemetryBlocker(
            ajaxChecker: static fn(): bool => true,
            blockedUrls: ['telemetry.example.com']
        );
        $this->assertFalse($ajaxBlocker->shouldInject());

        $jsonBlocker = new WpTelemetryBlocker(
            ajaxChecker: static fn(): bool => false,
            jsonRequestChecker: static fn(): bool => true,
            blockedUrls: ['telemetry.example.com']
        );
        $this->assertFalse($jsonBlocker->shouldInject());
    }

    public function testInjectSilencerOutputsScriptTagWithEncodedTargets(): void
    {
        $blocker = new WpTelemetryBlocker(
            ajaxChecker: static fn(): bool => false,
            jsonRequestChecker: static fn(): bool => false,
            blockedUrls: ['telemetry.example.com', 'beacon.example.org/collect']
        );

        ob_start();
        $blocker->injectSilencer();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('<script id="wp-telemetry-blocker">', $output);
        $this->assertStringContainsString(
            'const BLOCKED_TARGETS = ["telemetry.example.com","beacon.example.org/collect"];',
            $output
        );
        $this->assertStringContainsString('window.fetch', $output);
        $this->assertStringContainsString('XMLHttpRequest.prototype.open', $output);
        $this->assertStringContainsString('navigator.sendBeacon', $output);
    }

    public function testInjectFrontendOnlyOutputsWhenUserIsLoggedIn(): void
    {
        $anonymousBlocker = new WpTelemetryBlocker(
            userLoggedInChecker: static fn(): bool => false,
            ajaxChecker: static fn(): bool => false,
            jsonRequestChecker: static fn(): bool => false,
            blockedUrls: ['telemetry.example.com']
        );

        ob_start();
        $anonymousBlocker->injectFrontend();
        $anonOutput = (string) ob_get_clean();
        $this->assertSame('', $anonOutput);

        $loggedInBlocker = new WpTelemetryBlocker(
            userLoggedInChecker: static fn(): bool => true,
            ajaxChecker: static fn(): bool => false,
            jsonRequestChecker: static fn(): bool => false,
            blockedUrls: ['telemetry.example.com']
        );

        ob_start();
        $loggedInBlocker->injectFrontend();
        $loggedInOutput = (string) ob_get_clean();
        $this->assertStringContainsString('<script id="wp-telemetry-blocker">', $loggedInOutput);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist(): void
    {
        $blocker = new WpTelemetryBlocker();
        unset($GLOBALS['test_registered_actions']);

        $blocker->registerHooks();

        $this->assertArrayNotHasKey('test_registered_actions', $GLOBALS);
    }

    public function testRegisterHooksWithInjectedRegistrar(): void
    {
        /** @var list<array{string, callable, int, int}> $registered */
        $registered = [];

        $registrar = static function (
            string $tag,
            callable $callback,
            int $priority,
            int $acceptedArgs
        ) use (&$registered): void {
            $registered[] = [$tag, $callback, $priority, $acceptedArgs];
        };

        $blocker = new WpTelemetryBlocker();
        $blocker->registerHooks($registrar);

        $this->assertCount(4, $registered);
        $this->assertSame('admin_print_scripts', $registered[0][0]);
        $this->assertSame(0, $registered[0][2]);
        $this->assertSame(0, $registered[0][3]);

        $this->assertSame('login_head', $registered[1][0]);
        $this->assertSame('elementor/editor/before_enqueue_scripts', $registered[2][0]);
        $this->assertSame('wp_head', $registered[3][0]);
    }

    #[Depends('testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testFinishFastcgiReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testOnUpgraderProcessCompleteReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    public function testRegisterHooksWithoutRegistrarUsesGlobalAddAction(): void
    {
        ensure_wordpress_stubs();
        $GLOBALS['test_registered_actions'] = [];

        $blocker = new WpTelemetryBlocker();
        $blocker->registerHooks();

        $this->assertArrayHasKey('admin_print_scripts', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('login_head', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('elementor/editor/before_enqueue_scripts', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_head', $GLOBALS['test_registered_actions']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenAddActionExists(): void
    {
        ensure_wordpress_stubs();
        $GLOBALS['test_registered_actions'] = [];
        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-telemetry-blocker.php.example';

        $this->assertArrayHasKey('admin_print_scripts', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('login_head', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('elementor/editor/before_enqueue_scripts', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_head', $GLOBALS['test_registered_actions']);
    }
}
