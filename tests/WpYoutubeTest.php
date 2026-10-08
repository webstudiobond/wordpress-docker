<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpYoutube;

final class WpYoutubeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WP_YOUTUBE_SHORTCODES');
        putenv('WP_YOUTUBE_OEMBED_CLEANUP');
        putenv('WP_YOUTUBE_NOCOOKIE');
        putenv('WP_YOUTUBE_OEMBED_PARAMS');
        putenv('WP_YOUTUBE_API_KEY');
        putenv('WP_YOUTUBE_CHANNEL_ID');
        unset(
            $GLOBALS['test_docker_secrets'],
            $GLOBALS['test_transients'],
            $GLOBALS['test_transient_expirations'],
            $GLOBALS['test_remote_get_calls'],
            $GLOBALS['test_remote_get_response'],
            $GLOBALS['test_registered_shortcodes'],
            $GLOBALS['test_registered_filters']
        );
        parent::tearDown();
    }

    public function testDefaultConfigurationAndFallbacksBeforeStubsLoaded(): void
    {
        $youtube = new WpYoutube();

        $this->assertFalse($youtube->isShortcodesEnabled());
        $this->assertTrue($youtube->isOembedCleanupEnabled());
        $this->assertTrue($youtube->isNocookieEnabled());
        $this->assertSame(
            'rel=0&playsinline=1&iv_load_policy=3',
            $youtube->getOembedParams()
        );
        $this->assertSame('', $youtube->getDefaultApiKey());
        $this->assertSame('', $youtube->getDefaultChannelId());

        $this->assertSame('', $youtube->renderViewCountShortcode([]));
        $this->assertSame(
            '',
            $youtube->renderViewCountShortcode(['apikey' => 'valid_key', 'channel' => 'UC123456'])
        );

        $iframe = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed"></iframe>';
        $this->assertSame(
            '<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'
            . '?rel=0&playsinline=1&iv_load_policy=3"></iframe>',
            $youtube->filterOembedHtml($iframe)
        );
        $this->assertSame('', $youtube->filterOembedHtml(['invalid_non_scalar']));

        $nocookieDisabled = new WpYoutube(
            envGetter: static fn(string $name): string => $name === 'WP_YOUTUBE_NOCOOKIE' ? 'false' : ''
        );
        $this->assertFalse($nocookieDisabled->isNocookieEnabled());
        $this->assertSame(
            '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ'
            . '?rel=0&playsinline=1&iv_load_policy=3"></iframe>',
            $nocookieDisabled->filterOembedHtml($iframe)
        );

        $shortcodesEnabledNoWordPress = new WpYoutube(
            envGetter: static fn(string $name): string => $name === 'WP_YOUTUBE_SHORTCODES' ? 'true' : ''
        );
        $shortcodesEnabledNoWordPress->registerHooks();

        $youtube->registerHooks();
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
        ensure_wordpress_filter_stubs();
        ensure_youtube_stubs();

        $GLOBALS['test_docker_secrets'] = [
            'wp_youtube_api_key' => 'secret_api_key',
            'wp_youtube_channel_id' => 'UC_SECRET_CHANNEL',
        ];
        $GLOBALS['test_transients'] = [];
        $GLOBALS['test_transient_expirations'] = [];
        $GLOBALS['test_remote_get_calls'] = [];
        $GLOBALS['test_registered_shortcodes'] = [];
        $GLOBALS['test_registered_filters'] = [];

        $GLOBALS['test_remote_get_response'] = [
            'body' => json_encode([
                'items' => [
                    [
                        'statistics' => [
                            'viewCount' => '1234567',
                            'subscriberCount' => '89012',
                            'videoCount' => '345',
                        ],
                    ],
                ],
            ]),
        ];

        $youtube = new WpYoutube(
            envGetter: static fn(string $name): string => $name === 'WP_YOUTUBE_SHORTCODES' ? 'true' : ''
        );

        $this->assertTrue($youtube->isShortcodesEnabled());
        $this->assertSame('secret_api_key', $youtube->getDefaultApiKey());
        $this->assertSame('UC_SECRET_CHANNEL', $youtube->getDefaultChannelId());

        $this->assertSame('1&nbsp;234&nbsp;567', $youtube->renderViewCountShortcode(['apikey' => 'AIza']));
        $this->assertSame('1&nbsp;234&nbsp;567', $youtube->renderViewCountShortcode(['apikey' => 'AIza']));
        $this->assertCount(1, $GLOBALS['test_remote_get_calls']);

        $this->assertSame('89&nbsp;012', $youtube->renderSubscriberCountShortcode(['channel' => 'UCg']));
        $this->assertSame('345', $youtube->renderVideoCountShortcode(['channel' => 'UCgJ', 'n' => '0']));

        $GLOBALS['test_remote_get_response'] = ['wp_error' => true];
        $this->assertSame('', $youtube->renderViewCountShortcode(['channel' => 'UC_ERROR_CHANNEL']));

        $youtube->registerHooks();

        $this->assertArrayHasKey('ytv', $GLOBALS['test_registered_shortcodes']);
        $this->assertArrayHasKey('yts', $GLOBALS['test_registered_shortcodes']);
        $this->assertArrayHasKey('ytc', $GLOBALS['test_registered_shortcodes']);
        $this->assertArrayHasKey('embed_oembed_html', $GLOBALS['test_registered_filters']);
    }

    public function testInjectedDependenciesAndEdgeCases(): void
    {
        /** @var array<string, string> $cacheStore */
        $cacheStore = [];
        /** @var list<string> $shortcodes */
        $shortcodes = [];
        /** @var list<string> $filters */
        $filters = [];

        $youtube = new WpYoutube(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_YOUTUBE_SHORTCODES' => '1',
                'WP_YOUTUBE_API_KEY' => 'env_api_key',
                'WP_YOUTUBE_CHANNEL_ID' => 'UC_ENV_CHANNEL',
                'WP_YOUTUBE_OEMBED_PARAMS' => '?rel=0&controls=0',
                default => '',
            },
            secretGetter: static fn(): string => '',
            transientGetter: static fn(string $key): mixed => $cacheStore[$key] ?? false,
            transientSetter: static function (string $key, string $val) use (&$cacheStore): bool {
                $cacheStore[$key] = $val;
                return true;
            },
            httpGetter: static fn(): string => (string) json_encode([
                'items' => [
                    [
                        'statistics' => [
                            'viewCount' => '5000',
                        ],
                    ],
                ],
            ]),
            shortcodeAdder: static function (string $tag) use (&$shortcodes): void {
                $shortcodes[] = $tag;
            },
            filterAdder: static function (string $tag) use (&$filters): void {
                $filters[] = $tag;
            }
        );

        $this->assertSame('env_api_key', $youtube->getDefaultApiKey());
        $this->assertSame('UC_ENV_CHANNEL', $youtube->getDefaultChannelId());
        $this->assertSame('rel=0&controls=0', $youtube->getOembedParams());

        $this->assertSame(
            '5&nbsp;000',
            $youtube->renderViewCountShortcode(['cache' => '600'])
        );
        $this->assertSame(
            '5&nbsp;000',
            $youtube->renderViewCountShortcode(['cache' => '600'])
        );

        $nocookieIframe = '<iframe src="https://www.youtube-nocookie.com/embed/abc?feature=oembed"></iframe>';
        $this->assertSame(
            '<iframe src="https://www.youtube-nocookie.com/embed/abc?rel=0&controls=0"></iframe>',
            $youtube->filterOembedHtml($nocookieIframe)
        );

        $vimeoIframe = '<iframe src="https://player.vimeo.com/video/123?feature=oembed"></iframe>';
        $this->assertSame($vimeoIframe, $youtube->filterOembedHtml($vimeoIframe));

        $youtube->registerHooks();
        $this->assertSame(['ytv', 'yts', 'ytc'], $shortcodes);
        $this->assertSame(['embed_oembed_html'], $filters);

        $invalidJsonYoutube = new WpYoutube(
            envGetter: static fn(): string => '',
            secretGetter: static fn(): string => 'sec_val',
            httpGetter: static fn(): string => '{"items":[]}'
        );
        $this->assertSame('', $invalidJsonYoutube->renderViewCountShortcode('non_array_atts'));

        $disabledYoutube = new WpYoutube(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_YOUTUBE_SHORTCODES' => 'false',
                'WP_YOUTUBE_OEMBED_CLEANUP' => '0',
                default => '',
            },
            shortcodeAdder: static function (string $tag) use (&$shortcodes): void {
                $shortcodes[] = $tag;
            },
            filterAdder: static function (string $tag) use (&$filters): void {
                $filters[] = $tag;
            }
        );

        $this->assertFalse($disabledYoutube->isShortcodesEnabled());
        $this->assertFalse($disabledYoutube->isOembedCleanupEnabled());
        $this->assertSame($nocookieIframe, $disabledYoutube->filterOembedHtml($nocookieIframe));
        $disabledYoutube->registerHooks();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenAddFilterExists(): void
    {
        ensure_wordpress_filter_stubs();
        ensure_youtube_stubs();
        $GLOBALS['test_registered_shortcodes'] = [];
        $GLOBALS['test_registered_filters'] = [];

        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-youtube.php.example';

        $this->assertSame([], $GLOBALS['test_registered_shortcodes']);
        $this->assertArrayHasKey('embed_oembed_html', $GLOBALS['test_registered_filters']);
    }
}
