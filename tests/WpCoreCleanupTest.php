<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use stdClass;
use WpCoreCleanup;

final class WpCoreCleanupTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WP_CLEANUP_HEAD_TAGS');
        putenv('WP_CLEANUP_COMMENTS');
        putenv('WP_CLEANUP_HIDE_POWERED_BY');
        putenv('WP_CLEANUP_DISABLE_SELF_PING');
        putenv('WP_CLEANUP_DISABLE_EMOJIS');
        putenv('WP_CLEANUP_DISABLE_GLOBAL_STYLES');
        putenv('WP_CLEANUP_DISABLE_JQUERY_MIGRATE');
        putenv('WP_CLEANUP_DISABLE_SPECULATIVE_LOADING');
        unset(
            $GLOBALS['wp_widget_factory'],
            $GLOBALS['test_wp_options'],
            $GLOBALS['test_registered_actions'],
            $GLOBALS['test_registered_filters'],
            $GLOBALS['test_removed_actions'],
            $GLOBALS['test_removed_filters'],
            $GLOBALS['test_is_admin'],
            $GLOBALS['test_deregistered_scripts']
        );
        parent::tearDown();
    }

    public function testDefaultConfigurationAndFallbacksBeforeStubsLoaded(): void
    {
        $cleanup = new WpCoreCleanup();

        $this->assertSame(
            [
                'generator',
                'wlwmanifest',
                'rsd',
                'shortlink',
                'rest_links',
                'feeds',
                'oembed',
                'dns_prefetch',
                'profile',
            ],
            $cleanup->getHeadTags()
        );
        $this->assertTrue($cleanup->isHeadTagDisabled('generator'));
        $this->assertFalse($cleanup->isHeadTagDisabled('unknown_tag'));

        $this->assertSame(['url_field', 'make_clickable', 'reply_js'], $cleanup->getCommentCleanups());
        $this->assertTrue($cleanup->isCommentCleanupEnabled('url_field'));
        $this->assertFalse($cleanup->isCommentCleanupEnabled('recent_styles'));

        $this->assertTrue($cleanup->isPoweredByHidden());
        $this->assertTrue($cleanup->isSelfPingDisabled());
        $this->assertTrue($cleanup->isEmojisDisabled());
        $this->assertFalse($cleanup->isGlobalStylesDisabled());
        $this->assertFalse($cleanup->isJqueryMigrateDisabled());
        $this->assertFalse($cleanup->isSpeculativeLoadingDisabled());

        $links = ['https://example.com/post-1'];
        $cleanup->disableSelfPing($links);
        $this->assertSame(['https://example.com/post-1'], $links);

        $cleanup->onInit();
        $cleanup->onWpFooter();
        $cleanup->registerHooks();

        $jquery = (object) ['deps' => ['jquery-core', 'jquery-migrate']];
        $scripts = (object) ['registered' => ['jquery' => $jquery]];
        $migrateCleanup = new WpCoreCleanup(
            envGetter: static fn(string $name): string => $name === 'WP_CLEANUP_DISABLE_JQUERY_MIGRATE' ? 'true' : ''
        );
        $migrateCleanup->removeJqueryMigrate($scripts);
        $this->assertSame(['jquery-core'], $jquery->deps);
    }

    #[Depends('testDefaultConfigurationAndFallbacksBeforeStubsLoaded')]
    #[DependsExternal(WpAcfEditorControlTest::class, 'testFallbacksWhenGlobalFunctionsUnavailable')]
    #[DependsExternal(WpPerformanceTest::class, 'testFinishFastcgiReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testOnUpgraderProcessCompleteReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(WpTelemetryBlockerTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(
        WpTranslationUpdatesDisablerTest::class,
        'testRegisterHooksReturnsEarlyWhenAddFilterDoesNotExist'
    )]
    public function testGlobalWordPressFunctionFallbacks(): void
    {
        ensure_wordpress_stubs();
        ensure_wordpress_filter_stubs();
        ensure_cleanup_stubs();

        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];
        $GLOBALS['test_removed_actions'] = [];
        $GLOBALS['test_removed_filters'] = [];
        $GLOBALS['test_deregistered_scripts'] = [];
        $GLOBALS['test_wp_options'] = ['home' => 'https://example.com'];
        $GLOBALS['test_is_admin'] = false;

        $cleanup = new WpCoreCleanup(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_CLEANUP_COMMENTS' => '*',
                'WP_CLEANUP_DISABLE_GLOBAL_STYLES' => 'true',
                'WP_CLEANUP_DISABLE_JQUERY_MIGRATE' => 'true',
                'WP_CLEANUP_DISABLE_SPECULATIVE_LOADING' => 'true',
                default => '',
            }
        );

        $cleanup->registerHooks();
        $cleanup->onInit();
        $cleanup->onWpFooter();

        $this->assertArrayHasKey('wp_head', $GLOBALS['test_removed_actions']);
        $this->assertArrayHasKey('comment_text', $GLOBALS['test_removed_filters']);
        $this->assertArrayHasKey('init', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('widgets_init', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_default_scripts', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_speculation_rules_configuration', $GLOBALS['test_registered_filters']);
        $this->assertSame(['comment-reply', 'wp-embed'], $GLOBALS['test_deregistered_scripts']);

        $links = [
            'https://example.com/internal-post',
            'https://example.org/external-post',
        ];
        $cleanup->disableSelfPing($links);
        $this->assertSame([1 => 'https://example.org/external-post'], $links);

        $GLOBALS['test_is_admin'] = true;
        $jquery = (object) ['deps' => ['jquery-core', 'jquery-migrate']];
        $scripts = (object) ['registered' => ['jquery' => $jquery]];
        $cleanup->removeJqueryMigrate($scripts);
        $this->assertSame(['jquery-core', 'jquery-migrate'], $jquery->deps);
    }

    public function testTagListParsingAndBooleanEnvironmentOverrides(): void
    {
        $customCleanup = new WpCoreCleanup(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_CLEANUP_HEAD_TAGS' => ' generator , rsd , invalid_tag , generator ',
                'WP_CLEANUP_COMMENTS' => '*',
                'WP_CLEANUP_HIDE_POWERED_BY' => 'false',
                'WP_CLEANUP_DISABLE_SELF_PING' => '0',
                'WP_CLEANUP_DISABLE_EMOJIS' => 'off',
                'WP_CLEANUP_DISABLE_GLOBAL_STYLES' => '1',
                'WP_CLEANUP_DISABLE_JQUERY_MIGRATE' => 'yes',
                'WP_CLEANUP_DISABLE_SPECULATIVE_LOADING' => 'on',
                default => '',
            }
        );

        $this->assertSame(['generator', 'rsd'], $customCleanup->getHeadTags());
        $this->assertSame(
            ['url_field', 'make_clickable', 'reply_js', 'recent_styles'],
            $customCleanup->getCommentCleanups()
        );
        $this->assertFalse($customCleanup->isPoweredByHidden());
        $this->assertFalse($customCleanup->isSelfPingDisabled());
        $this->assertFalse($customCleanup->isEmojisDisabled());
        $this->assertTrue($customCleanup->isGlobalStylesDisabled());
        $this->assertTrue($customCleanup->isJqueryMigrateDisabled());
        $this->assertTrue($customCleanup->isSpeculativeLoadingDisabled());

        $disabledCleanup = new WpCoreCleanup(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_CLEANUP_HEAD_TAGS' => 'none',
                'WP_CLEANUP_COMMENTS' => 'false',
                default => '',
            }
        );

        $this->assertSame([], $disabledCleanup->getHeadTags());
        $this->assertSame([], $disabledCleanup->getCommentCleanups());

        $wildcardHeadCleanup = new WpCoreCleanup(
            envGetter: static fn(string $name): string => $name === 'WP_CLEANUP_HEAD_TAGS' ? '*' : ''
        );
        $this->assertCount(9, $wildcardHeadCleanup->getHeadTags());
    }

    public function testHeaderPingbackAndCommentFieldCallbacks(): void
    {
        /** @var list<string> $removedHeaders */
        $removedHeaders = [];

        $cleanup = new WpCoreCleanup(
            headerRemover: static function (string $name) use (&$removedHeaders): void {
                $removedHeaders[] = $name;
            },
            homeUrlGetter: static fn(): string => 'https://example.com'
        );

        $cleanup->removePoweredByHeader();
        $this->assertSame(['X-Powered-By'], $removedHeaders);

        $headers = ['X-Pingback' => 'https://example.com/xmlrpc.php', 'Content-Type' => 'text/html'];
        $this->assertSame(['Content-Type' => 'text/html'], $cleanup->filterWpHeaders($headers));

        $fields = ['author' => '<input>', 'url' => '<input>', 'email' => '<input>'];
        $this->assertSame(['author' => '<input>', 'email' => '<input>'], $cleanup->filterCommentFormFields($fields));

        $selfPingLinks = ['https://example.com/post-1', 'https://example.net/post-2'];
        $cleanup->disableSelfPing($selfPingLinks);
        $this->assertSame([1 => 'https://example.net/post-2'], $selfPingLinks);

        $disabledCleanup = new WpCoreCleanup(
            envGetter: static fn(): string => 'false',
            headerRemover: static function (string $name) use (&$removedHeaders): void {
                $removedHeaders[] = $name;
            }
        );

        $disabledCleanup->removePoweredByHeader();
        $this->assertCount(1, $removedHeaders);

        $links = ['https://example.com/post-1'];
        $disabledCleanup->disableSelfPing($links);
        $this->assertCount(1, $links);
    }

    public function testOnWidgetsInitAndRemoveJqueryMigrateEdgeCases(): void
    {
        /** @var list<array{string, mixed, int}> $removedActions */
        $removedActions = [];

        $cleanup = new WpCoreCleanup(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_CLEANUP_COMMENTS' => 'recent_styles',
                'WP_CLEANUP_DISABLE_JQUERY_MIGRATE' => 'true',
                default => 'none',
            },
            adminChecker: static fn(): bool => false,
            actionRemover: static function (string $tag, mixed $cb, int $prio) use (&$removedActions): void {
                $removedActions[] = [$tag, $cb, $prio];
            }
        );

        $cleanup->onWidgetsInit();
        $this->assertSame([], $removedActions);

        $GLOBALS['wp_widget_factory'] = (object) ['widgets' => 'not_an_array'];
        $cleanup->onWidgetsInit();
        $this->assertSame([], $removedActions);

        $recentWidget = new stdClass();
        $GLOBALS['wp_widget_factory'] = (object) [
            'widgets' => [
                'WP_Widget_Recent_Comments' => $recentWidget,
            ],
        ];
        $cleanup->onWidgetsInit();
        $this->assertCount(1, $removedActions);
        $this->assertSame('wp_head', $removedActions[0][0]);

        $disabledRecentCleanup = new WpCoreCleanup(
            envGetter: static fn(): string => 'none'
        );
        $disabledRecentCleanup->onWidgetsInit();

        $cleanup->removeJqueryMigrate('not_an_object');
        $cleanup->removeJqueryMigrate((object) ['registered' => 'not_an_array']);
        $cleanup->removeJqueryMigrate((object) ['registered' => ['jquery' => 'not_an_object']]);
        $cleanup->removeJqueryMigrate((object) ['registered' => ['jquery' => new stdClass()]]);
    }

    public function testRegisterHooksWithInjectedHandlersWhenAllDisabled(): void
    {
        /** @var list<string> $addedActions */
        $addedActions = [];
        /** @var list<string> $removedActions */
        $removedActions = [];
        /** @var list<string> $addedFilters */
        $addedFilters = [];
        /** @var list<string> $removedFilters */
        $removedFilters = [];
        /** @var list<string> $deregistered */
        $deregistered = [];

        $cleanup = new WpCoreCleanup(
            envGetter: static fn(): string => 'false',
            scriptDeregisterer: static function (string $handle) use (&$deregistered): void {
                $deregistered[] = $handle;
            },
            actionAdder: static function (string $tag) use (&$addedActions): void {
                $addedActions[] = $tag;
            },
            actionRemover: static function (string $tag) use (&$removedActions): void {
                $removedActions[] = $tag;
            },
            filterAdder: static function (string $tag) use (&$addedFilters): void {
                $addedFilters[] = $tag;
            },
            filterRemover: static function (string $tag) use (&$removedFilters): void {
                $removedFilters[] = $tag;
            }
        );

        $cleanup->registerHooks();
        $cleanup->onInit();
        $cleanup->onWpFooter();

        $this->assertSame([], $addedActions);
        $this->assertSame([], $removedActions);
        $this->assertSame([], $addedFilters);
        $this->assertSame([], $removedFilters);
        $this->assertSame([], $deregistered);

        $enabledCleanup = new WpCoreCleanup(
            scriptDeregisterer: static function (string $handle) use (&$deregistered): void {
                $deregistered[] = $handle;
            },
            actionAdder: static function (string $tag) use (&$addedActions): void {
                $addedActions[] = $tag;
            },
            actionRemover: static function (string $tag) use (&$removedActions): void {
                $removedActions[] = $tag;
            },
            filterAdder: static function (string $tag) use (&$addedFilters): void {
                $addedFilters[] = $tag;
            },
            filterRemover: static function (string $tag) use (&$removedFilters): void {
                $removedFilters[] = $tag;
            }
        );
        $enabledCleanup->registerHooks();
        $enabledCleanup->onInit();
        $enabledCleanup->onWpFooter();
        $this->assertSame(['comment-reply', 'wp-embed'], $deregistered);
        $this->assertContains('init', $addedActions);
        $this->assertContains('wp_head', $removedActions);
        $this->assertContains('the_generator', $addedFilters);
        $this->assertContains('comment_text', $removedFilters);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenWordPressFunctionsExist(): void
    {
        ensure_wordpress_stubs();
        ensure_wordpress_filter_stubs();
        ensure_cleanup_stubs();
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];
        $GLOBALS['test_removed_actions'] = [];

        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-core-cleanup.php.example';

        $this->assertArrayHasKey('wp_head', $GLOBALS['test_removed_actions']);
        $this->assertArrayHasKey('pre_ping', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_headers', $GLOBALS['test_registered_filters']);
    }
}
