<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpSnippets;

final class WpSnippetsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['test_registered_actions'],
            $GLOBALS['test_is_user_logged_in'],
            $GLOBALS['test_current_user'],
            $GLOBALS['test_wp_is_mobile'],
            $GLOBALS['test_is_front_page'],
            $GLOBALS['test_is_home'],
            $GLOBALS['test_is_page'],
            $GLOBALS['test_last_is_page_arg'],
            $GLOBALS['test_is_single'],
            $GLOBALS['test_last_is_single_arg'],
            $GLOBALS['test_is_singular'],
            $GLOBALS['test_last_is_singular_arg'],
            $GLOBALS['test_is_archive'],
            $GLOBALS['test_is_post_type_archive'],
            $GLOBALS['test_last_is_post_type_archive_arg'],
            $GLOBALS['test_is_category'],
            $GLOBALS['test_last_is_category_arg'],
            $GLOBALS['test_is_tag'],
            $GLOBALS['test_last_is_tag_arg'],
            $GLOBALS['test_is_tax'],
            $GLOBALS['test_last_is_tax_args'],
            $GLOBALS['test_dequeued_styles'],
            $GLOBALS['test_deregistered_styles'],
            $GLOBALS['test_dequeued_scripts'],
            $GLOBALS['test_deregistered_scripts'],
            $GLOBALS['test_acf_fields'],
            $GLOBALS['test_queried_object'],
            $GLOBALS['test_last_get_field_args']
        );
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFallbacksWhenWordPressFunctionsAreNotDefined(): void
    {
        $output = '';
        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_GTM_ID' => 'GTM-ABC1234',
                'WP_FONTS_PRELOAD' => '/content/fonts/a.woff2|front;'
                    . ' /content/fonts/b.woff2|tax:product_cat; /content/fonts/c.woff2|acf:enable_math',
                'WP_DEREGISTER_STYLES' => 'wp-block-library|single',
                'WP_DEREGISTER_SCRIPTS' => 'comment-reply|page:10',
                default => false,
            },
            outputWriter: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            }
        );

        $plugin->register();
        $plugin->renderHeadAssets();
        $plugin->deregisterStyles();
        $plugin->deregisterScripts();

        $this->assertStringContainsString('GTM-ABC1234', $output);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testRegisterOnlyHooksConfiguredFeatures(): void
    {
        ensure_snippets_stubs();
        $GLOBALS['test_registered_actions'] = [];

        $emptyPlugin = new WpSnippets(envReader: static fn(): false => false);
        $emptyPlugin->register();

        $this->assertSame([], $GLOBALS['test_registered_actions']);

        $configuredPlugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_GTM_ID' => 'GTM-TEST99',
                'WP_FONTS_PRELOAD' => '/content/fonts/main.woff2|editor',
                'WP_SNIPPETS_HEAD' => 'meta.html',
                'WP_SNIPPETS_BODY' => 'banner.html',
                'WP_SNIPPETS_FOOTER' => 'popup.css',
                'WP_DEREGISTER_STYLES' => 'wp-block-library',
                'WP_DEREGISTER_SCRIPTS' => 'wp-embed',
                default => false,
            }
        );
        $configuredPlugin->register();

        $this->assertArrayHasKey('wp_head', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_body_open', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_footer', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_enqueue_scripts', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_print_styles', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_print_scripts', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('admin_head', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('elementor/editor/after_enqueue_styles', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('elementor/preview/enqueue_styles', $GLOBALS['test_registered_actions']);
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testGtmValidationAndDefaultGuestCondition(): void
    {
        ensure_snippets_stubs();
        $headOut = '';
        $bodyOut = '';

        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_GTM_ID' => 'invalid-gtm; gtm-ab12cd; ; |guest',
                default => false,
            },
            outputWriter: static function (string $chunk) use (&$headOut): void {
                $headOut .= $chunk;
            }
        );

        $GLOBALS['test_is_user_logged_in'] = true;
        $plugin->renderHeadAssets();
        $this->assertSame('', $headOut);

        $GLOBALS['test_is_user_logged_in'] = false;
        $plugin->renderHeadAssets();
        $this->assertStringContainsString(
            '<link rel="preconnect" href="https://www.googletagmanager.com" crossorigin>',
            $headOut
        );
        $this->assertStringContainsString("'GTM-AB12CD'", $headOut);

        $bodyPlugin = new WpSnippets(
            envReader: static fn(string $name): string|false => $name === 'WP_GTM_ID' ? 'GTM-AB12CD' : false,
            outputWriter: static function (string $chunk) use (&$bodyOut): void {
                $bodyOut .= $chunk;
            }
        );
        $bodyPlugin->renderBodyOpen();
        $this->assertStringContainsString(
            '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-AB12CD"',
            $bodyOut
        );
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testFontsAndImagePreloadAndEditorRendering(): void
    {
        ensure_snippets_stubs();
        $output = '';

        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_FONTS_PRELOAD' => '/fonts/a.woff2|editor; /fonts/b.woff; /fonts/c.ttf; /fonts/d.otf; '
                    . 'javascript:alert(1); /bad"url.woff2',
                'WP_FONTS_STYLES' => '/fonts/wp-custom-fonts.css|editor; '
                    . '/fonts/wordpress.css; https://example.com/f.css; javascript:bad()',
                'WP_PRELOAD_IMAGES' => '/uploads/hero-desktop.webp|page:10,20,contact|desktop; '
                    . '/uploads/hero-mobile.webp|page:10|mobile; javascript:evil()',
                default => false,
            },
            outputWriter: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            }
        );

        $GLOBALS['test_is_page'] = static fn(mixed $arg): bool => $arg === [10, 20, 'contact'];
        $GLOBALS['test_wp_is_mobile'] = false;

        $plugin->renderHeadAssets();

        $this->assertStringContainsString(
            '<link rel="preload" as="font" href="/fonts/a.woff2" type="font/woff2" crossorigin>',
            $output
        );
        $this->assertStringContainsString('type="font/woff"', $output);
        $this->assertStringContainsString('type="font/ttf"', $output);
        $this->assertStringContainsString('type="font/otf"', $output);
        $this->assertStringContainsString(
            '<link rel="stylesheet" id="custom-fonts" href="/fonts/wp-custom-fonts.css" media="all">',
            $output
        );
        $this->assertStringContainsString(
            '<link rel="stylesheet" id="asset-snippet" href="/fonts/wordpress.css" media="all">',
            $output
        );
        $this->assertStringContainsString(
            '<link rel="preload" as="image" href="/uploads/hero-desktop.webp">',
            $output
        );
        $this->assertStringNotContainsString('hero-mobile.webp', $output);
        $this->assertStringNotContainsString('javascript:', $output);

        $output = '';
        $plugin->renderEditorFonts();
        $this->assertStringContainsString('/fonts/a.woff2', $output);
        $this->assertStringNotContainsString('/fonts/b.woff', $output);
        $this->assertStringContainsString('id="custom-fonts"', $output);
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testAudienceDeviceAndLocationConditions(): void
    {
        ensure_snippets_stubs();
        $output = '';

        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_PRELOAD_IMAGES' => implode('; ', [
                    '/img/auth.webp|auth|front',
                    '/img/role.webp|role:editor,author|home',
                    '/img/single.webp|single:42,my-post|!page:100',
                    '/img/singular.webp|singular:product,brand',
                    '/img/archive-all.webp|archive',
                    '/img/archive-cpt.webp|archive:product',
                    '/img/cat.webp|category:1,news',
                    '/img/tag.webp|tag:promo',
                    '/img/tax-any.webp|tax',
                    '/img/tax-name.webp|tax:product_cat',
                    '/img/tax-terms.webp|tax:product_cat:12,shoes',
                    '/img/unknown-token.webp|unknown:123|!|',
                ]),
                default => false,
            },
            outputWriter: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            }
        );

        $GLOBALS['test_is_user_logged_in'] = true;
        $GLOBALS['test_current_user'] = (object) ['roles' => ['editor']];
        $GLOBALS['test_is_front_page'] = true;
        $GLOBALS['test_is_home'] = true;
        $GLOBALS['test_is_page'] = false;
        $GLOBALS['test_is_single'] = true;
        $GLOBALS['test_is_singular'] = true;
        $GLOBALS['test_is_archive'] = true;
        $GLOBALS['test_is_post_type_archive'] = true;
        $GLOBALS['test_is_category'] = true;
        $GLOBALS['test_is_tag'] = true;
        $GLOBALS['test_is_tax'] = true;

        $plugin->renderHeadAssets();

        $this->assertStringContainsString('/img/auth.webp', $output);
        $this->assertStringContainsString('/img/role.webp', $output);
        $this->assertStringContainsString('/img/single.webp', $output);
        $this->assertStringContainsString('/img/singular.webp', $output);
        $this->assertStringContainsString('/img/archive-all.webp', $output);
        $this->assertStringContainsString('/img/archive-cpt.webp', $output);
        $this->assertStringContainsString('/img/cat.webp', $output);
        $this->assertStringContainsString('/img/tag.webp', $output);
        $this->assertStringContainsString('/img/tax-any.webp', $output);
        $this->assertStringContainsString('/img/tax-name.webp', $output);
        $this->assertStringContainsString('/img/tax-terms.webp', $output);
        $this->assertStringContainsString('/img/unknown-token.webp', $output);

        $output = '';
        $GLOBALS['test_current_user'] = (object) ['roles' => ['subscriber']];
        $GLOBALS['test_is_page'] = true;
        $GLOBALS['test_is_front_page'] = false;
        $GLOBALS['test_is_single'] = false;
        $plugin->renderHeadAssets();

        $this->assertStringNotContainsString('/img/auth.webp', $output);
        $this->assertStringNotContainsString('/img/role.webp', $output);
        $this->assertStringNotContainsString('/img/single.webp', $output);

        $output = '';
        $GLOBALS['test_current_user'] = (object) [];
        $plugin->renderHeadAssets();
        $this->assertStringNotContainsString('/img/role.webp', $output);

        $output = '';
        $GLOBALS['test_is_user_logged_in'] = false;
        $plugin->renderHeadAssets();
        $this->assertStringNotContainsString('/img/role.webp', $output);
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testDeregisterStylesAndScripts(): void
    {
        ensure_snippets_stubs();
        $GLOBALS['test_dequeued_styles'] = [];
        $GLOBALS['test_deregistered_styles'] = [];
        $GLOBALS['test_dequeued_scripts'] = [];
        $GLOBALS['test_deregistered_scripts'] = [];

        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_DEREGISTER_STYLES' => 'wp-block-library, global-styles|!single; modal-style|guest|page:10',
                'WP_DEREGISTER_SCRIPTS' => 'wp-embed, comment-reply|guest; jquery-migrate|single',
                default => false,
            }
        );

        $GLOBALS['test_is_user_logged_in'] = false;
        $GLOBALS['test_is_single'] = false;
        $GLOBALS['test_is_page'] = true;

        $plugin->deregisterStyles();
        $plugin->deregisterScripts();

        $this->assertSame(
            ['wp-block-library', 'global-styles', 'modal-style'],
            $GLOBALS['test_dequeued_styles']
        );
        $this->assertSame(
            ['wp-block-library', 'global-styles', 'modal-style'],
            $GLOBALS['test_deregistered_styles']
        );
        $this->assertSame(['wp-embed', 'comment-reply'], $GLOBALS['test_dequeued_scripts']);
        $this->assertSame(['wp-embed', 'comment-reply'], $GLOBALS['test_deregistered_scripts']);
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testFileBasedSnippetsAndSecurityChecks(): void
    {
        ensure_snippets_stubs();
        $files = [
            '/custom/snippets/meta-verification.html' => '  <meta name="verify" content="123">  ',
            '/custom/snippets/modal-critical.css' => '.modal-container{display:none}',
            '/custom/snippets/wp-passive-touch.js' => 'window.passiveReady = true;',
            '/custom/snippets/empty.html' => '   ',
            '/custom/snippets/breakout.css' => 'body { color: red; } /* </style> */',
            '/custom/snippets/breakout.js' => 'const tag = "</script>";',
            '/custom/snippets/comment.js' => '<!-- <script>',
        ];

        $headOut = '';
        $bodyOut = '';
        $footerOut = '';

        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_SNIPPETS_HEAD' => 'meta-verification.html; skipped.html|auth; ../secret.html; sub/evil.html; '
                    . 'hack.php; missing.html; empty.html; breakout.css; breakout.js; comment.js; '
                    . 'a..b.html; .hidden.html',
                'WP_SNIPPETS_BODY' => 'meta-verification.html|guest',
                'WP_SNIPPETS_FOOTER' => 'modal-critical.css|guest|page:10,20,30; wp-passive-touch.js; breakout.js',
                default => false,
            },
            fileReader: static fn(string $path): string|false => $files[$path] ?? false,
            outputWriter: static function (string $chunk) use (&$headOut, &$bodyOut, &$footerOut): void {
                if (str_contains($chunk, '<meta')) {
                    if ($headOut === '') {
                        $headOut .= $chunk;
                    } else {
                        $bodyOut .= $chunk;
                    }
                    return;
                }
                $footerOut .= $chunk;
            },
            snippetsDir: '/custom/snippets'
        );

        $GLOBALS['test_is_user_logged_in'] = false;
        $GLOBALS['test_is_page'] = true;

        $plugin->renderHeadSnippets();
        $plugin->renderBodyOpen();
        $plugin->renderFooterSnippets();

        $this->assertSame("<meta name=\"verify\" content=\"123\">\n", $headOut);
        $this->assertSame("<meta name=\"verify\" content=\"123\">\n", $bodyOut);
        $this->assertStringContainsString(
            "<style id=\"modal-critical\">\n.modal-container{display:none}\n</style>",
            $footerOut
        );
        $this->assertStringContainsString(
            "<script id=\"passive-touch\">\nwindow.passiveReady = true;\n</script>",
            $footerOut
        );
        $this->assertStringNotContainsString('breakout', $headOut);
        $this->assertStringNotContainsString('breakout', $footerOut);
        $this->assertStringNotContainsString('id="wp-', $footerOut);
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testAcfConditionsPositiveAndNegative(): void
    {
        ensure_snippets_stubs();
        $out = '';
        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_SNIPPETS_FOOTER' => 'math.html|single|acf:enable_math,enable_latex;'
                    . ' quiz.html|single|acf:enable_quiz|!acf:disable_quiz|!acf:bad$field;'
                    . ' always.html|acf:|acf:bad$field',
                default => false,
            },
            fileReader: static fn(string $path): string|false => match (basename($path)) {
                'math.html' => '<script src="/math.js"></script>',
                'quiz.html' => '<link rel="stylesheet" href="/quiz.css">',
                'always.html' => '<!-- always -->',
                default => false,
            },
            outputWriter: static function (string $chunk) use (&$out): void {
                $out .= $chunk;
            }
        );

        $queriedPost = (object) ['ID' => 42];
        $GLOBALS['test_queried_object'] = $queriedPost;
        $GLOBALS['test_is_single'] = true;
        $GLOBALS['test_acf_fields'] = [
            'enable_math' => false,
            'enable_latex' => false,
            'enable_quiz' => false,
        ];

        $plugin->renderFooterSnippets();
        $this->assertSame("<!-- always -->\n", $out);
        $this->assertSame(['enable_quiz', $queriedPost, false], $GLOBALS['test_last_get_field_args']);

        $out = '';
        $GLOBALS['test_acf_fields'] = [
            'enable_math' => false,
            'enable_latex' => 1,
            'enable_quiz' => true,
            'disable_quiz' => false,
        ];

        $plugin->renderFooterSnippets();
        $this->assertStringContainsString('<script src="/math.js"></script>', $out);
        $this->assertStringContainsString('<link rel="stylesheet" href="/quiz.css">', $out);

        $out = '';
        $GLOBALS['test_acf_fields'] = [
            'enable_quiz' => true,
            'disable_quiz' => true,
        ];

        $plugin->renderFooterSnippets();
        $this->assertStringNotContainsString('/quiz.css', $out);
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testDefaultEnvFileReaderAndEchoOutput(): void
    {
        ensure_snippets_stubs();
        $tmpDir = sys_get_temp_dir() . '/wp_snippets_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700, true);
        file_put_contents($tmpDir . '/inline.html', '<div>Snippet OK</div>');
        file_put_contents($tmpDir . '/oversized.html', str_repeat('a', 262145));
        @symlink($tmpDir . '/inline.html', $tmpDir . '/symlinked.html');

        putenv('WP_SNIPPETS_HEAD=inline.html; oversized.html; symlinked.html; nonexistent.html');

        try {
            $plugin = new WpSnippets(snippetsDir: $tmpDir);
            ob_start();
            $plugin->renderHeadSnippets();
            $out = (string) ob_get_clean();

            $this->assertSame("<div>Snippet OK</div>\n", $out);
            $this->assertStringNotContainsString('symlinked', $out);
        } finally {
            putenv('WP_SNIPPETS_HEAD');
            @unlink($tmpDir . '/symlinked.html');
            @unlink($tmpDir . '/oversized.html');
            @unlink($tmpDir . '/inline.html');
            @rmdir($tmpDir);
        }
    }

    #[Depends('testRegisterOnlyHooksConfiguredFeatures')]
    public function testSnippetsDirIsImmutableHardcodedToEtcWordPressSnippets(): void
    {
        ensure_snippets_stubs();
        $requestedPath = '';

        $plugin = new WpSnippets(
            envReader: static fn(string $name): string|false => match ($name) {
                'WP_SNIPPETS_DIR' => 'phar://evil.phar/code',
                'WP_SNIPPETS_HEAD' => 'snippet.html',
                default => false,
            },
            fileReader: static function (string $path) use (&$requestedPath): string|false {
                $requestedPath = $path;
                return false;
            }
        );

        $plugin->renderHeadSnippets();
        $this->assertSame('/etc/wordpress/snippets/snippet.html', $requestedPath);
        $this->assertSame('/etc/wordpress/snippets', WpSnippets::SNIPPETS_DIR);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapAndWpContentDirResolution(): void
    {
        ensure_snippets_stubs();
        $GLOBALS['test_registered_actions'] = [];

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/var/www/html/');
        }
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', '/var/www/html/content');
        }

        putenv('WP_GTM_ID=GTM-BOOT123');

        try {
            require __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-snippets.php.example';
            $this->assertArrayHasKey('wp_head', $GLOBALS['test_registered_actions']);

            $requestedPath = '';
            $plugin = new WpSnippets(
                envReader: static fn(string $name): string|false => match ($name) {
                    'WP_SNIPPETS_DIR' => '/var/www/html/content/snippets',
                    'WP_SNIPPETS_HEAD' => 'check.html',
                    default => false,
                },
                fileReader: static function (string $path) use (&$requestedPath): string|false {
                    $requestedPath = $path;
                    return false;
                }
            );
            $plugin->renderHeadSnippets();
            $this->assertSame('/etc/wordpress/snippets/check.html', $requestedPath);
        } finally {
            putenv('WP_GTM_ID');
        }
    }
}
