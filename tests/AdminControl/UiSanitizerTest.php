<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use stdClass;
use WordPressDocker\AdminControl\Modules\UiSanitizer;
use WordPressDocker\Tests\WpCoreCleanupTest;

/**
 * Validates administrative UI sanitization, notice suppression, Google Fonts blocking,
 * contextual help elimination, meta box removals, footer customization, and stealth die handlers.
 *
 * @phpstan-type AdminRule array{
 *     targets: list<string>,
 *     isAll: bool,
 *     superadmins: bool,
 *     invertedSuperadmins: bool,
 *     roles: list<string>,
 *     invertedRoles: list<string>,
 *     users: list<int>,
 *     invertedUsers: list<int>,
 *     action: 'hide'|'block'
 * }
 */
final class UiSanitizerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp_styles']);
        parent::tearDown();
    }

    public function testIsGoogleFontsDisabledScenarios(): void
    {
        $disabledSanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'true'
        );
        $this->assertTrue($disabledSanitizer->isGoogleFontsDisabled());

        $numericSanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => '1'
        );
        $this->assertTrue($numericSanitizer->isGoogleFontsDisabled());

        $enabledSanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'false'
        );
        $this->assertFalse($enabledSanitizer->isGoogleFontsDisabled());

        $defaultSanitizer = new UiSanitizer();
        $this->assertFalse($defaultSanitizer->isGoogleFontsDisabled());
    }

    public function testGoogleFontsHooksAndResourceHints(): void
    {
        $removedActions = [];
        $sanitizer = new UiSanitizer(
            actionRemover: static function (string $tag, callable|string $cb, int $prio) use (&$removedActions): void {
                $removedActions[] = ['tag' => $tag, 'cb' => $cb, 'prio' => $prio];
            }
        );

        $sanitizer->handleDisableGoogleFontsInit();
        $this->assertSame([['tag' => 'admin_head', 'cb' => 'wp_resource_hints', 'prio' => 1]], $removedActions);

        $urls = [
            'https://fonts.googleapis.com/css?family=Open+Sans',
            'https://fonts.gstatic.com/s/opensans/v17/mem8YaGs126MiZpBA-UFVZ0e.ttf',
            'https://example.com/custom.css',
        ];

        $filtered = $sanitizer->filterResourceHints($urls, 'dns-prefetch');
        $this->assertSame(['https://example.com/custom.css'], \array_values($filtered));
    }

    public function testStripGoogleFontsStylesModifiesWpStyles(): void
    {
        $dequeued = [];
        $deregistered = [];

        $sanitizer = new UiSanitizer(
            styleDequeuer: static function (string $handle) use (&$dequeued): void {
                $dequeued[] = $handle;
            },
            styleDeregisterer: static function (string $handle) use (&$deregistered): void {
                $deregistered[] = $handle;
            }
        );

        $wpStyles = new class {
            /** @var array<string, object> */
            public array $registered = [];

            /** @var list<string> */
            public array $queue = ['google-font-1', 'local-style'];
        };

        $wpStyles->registered['google-font-1'] = (object) ['src' => 'https://fonts.googleapis.com/css?family=Roboto'];
        $wpStyles->registered['local-style'] = (object) ['src' => 'https://example.com/style.css'];

        $GLOBALS['wp_styles'] = $wpStyles;

        $sanitizer->stripGoogleFontsStyles();
        $this->assertSame(['google-font-1'], $dequeued);
        $this->assertSame(['google-font-1'], $deregistered);
    }

    public function testShouldHideAdminNoticesAndHandlers(): void
    {
        $user = (object) ['ID' => 3, 'roles' => ['editor']];
        $allRemoved = [];
        $dequeuedScripts = [];

        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => '!superadmins',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['notices'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => true,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user,
            allActionsRemover: static function (string $tag, int|false $prio) use (&$allRemoved): void {
                $allRemoved[] = ['tag' => $tag, 'prio' => $prio];
            },
            scriptDequeuer: static function (string $handle) use (&$dequeuedScripts): void {
                $dequeuedScripts[] = $handle;
            }
        );

        $this->assertTrue($sanitizer->shouldHideAdminNotices($user));

        $sanitizer->handleAdminPrintScripts();
        $this->assertSame([
            ['tag' => 'admin_notices', 'prio' => false],
            ['tag' => 'all_admin_notices', 'prio' => false],
            ['tag' => 'user_admin_notices', 'prio' => false],
            ['tag' => 'network_admin_notices', 'prio' => false],
        ], $allRemoved);
        $this->assertSame(['wp-pointer'], $dequeuedScripts);
    }

    public function testShouldHideScreenOptionsAndHelpAndScreenHandling(): void
    {
        $user = (object) ['ID' => 3, 'roles' => ['editor']];
        $addedFilters = [];

        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => '!superadmins',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['screen-options-help'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => true,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user,
            filterAdder: static function (string $tag, mixed $cb, int $prio, int $args) use (&$addedFilters): void {
                $addedFilters[$tag] = ['callback' => $cb, 'priority' => $prio, 'args' => $args];
            }
        );

        $this->assertTrue($sanitizer->shouldHideScreenOptionsAndHelp($user));
        $this->assertFalse($sanitizer->filterScreenOptionsShowScreen(true));

        $mockScreen = new class {
            public string $id = 'edit-post';

            public bool $helpTabsRemoved = false;

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_help_tabs') {
                    $this->helpTabsRemoved = true;
                    return null;
                }
                return null;
            }
        };

        $sanitizer->handleCurrentScreen($mockScreen);
        $this->assertTrue($mockScreen->helpTabsRemoved);
        $this->assertArrayHasKey('manage_edit-post_columns', $addedFilters);
        $this->assertSame(999, $addedFilters['manage_edit-post_columns']['priority']);
    }

    public function testHandleAdminHeadRendersStyles(): void
    {
        $user = (object) ['ID' => 3, 'roles' => ['editor']];
        $echoed = '';

        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string|false => match ($name) {
                'WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP' => '!superadmins',
                'WP_ADMIN_HIDE_NOTICES' => '!superadmins',
                'WP_ADMIN_FOOTER_LEFT' => 'remove|!superadmins',
                'WP_ADMIN_FOOTER_RIGHT' => 'remove|!superadmins',
                default => false,
            },
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['screen-options-help', 'footer', 'notices'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => true,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user,
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed .= $html;
            }
        );

        $sanitizer->handleAdminHead();
        $this->assertStringContainsString('#screen-options-link-wrap', $echoed);
        $this->assertStringContainsString('#contextual-help-link-wrap', $echoed);
        $this->assertStringContainsString('#wpfooter { display: none !important; }', $echoed);
        $this->assertStringContainsString('#wpbody-content > .notice:not(.inline)', $echoed);
    }

    public function testHiddenMetaBoxesAndColumnsFiltering(): void
    {
        $user = (object) ['ID' => 3, 'roles' => ['editor']];

        $sanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $name): string => 'comments,author|hide:editor',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['comments', 'author'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user,
            userRestrictedChecker: static fn(array $rule, ?object $u): bool => $u === $user
        );

        $hiddenMeta = $sanitizer->filterHiddenMetaBoxes(['existing_box']);
        $this->assertContains('existing_box', $hiddenMeta);
        $this->assertContains('comments', $hiddenMeta);
        $this->assertContains('author', $hiddenMeta);

        $hiddenCols = $sanitizer->filterHiddenColumns(['cb']);
        $this->assertContains('cb', $hiddenCols);
        $this->assertContains('comments', $hiddenCols);
        $this->assertContains('author', $hiddenCols);

        $screenColumns = [
            'cb' => '<input type="checkbox">',
            'title' => 'Title',
            'comments' => 'Comments',
            'author' => 'Author',
        ];

        $filteredColumns = $sanitizer->filterScreenColumns($screenColumns);
        $this->assertArrayHasKey('cb', $filteredColumns);
        $this->assertArrayHasKey('title', $filteredColumns);
        $this->assertArrayNotHasKey('comments', $filteredColumns);
        $this->assertArrayNotHasKey('author', $filteredColumns);
    }

    public function testResolveMetaBoxTargetIdsAliases(): void
    {
        $sanitizer = new UiSanitizer();

        $tagsTargets = $sanitizer->resolveMetaBoxTargetIds('tags');
        $this->assertSame(['tagsdiv-post_tag', 'post_tag', 'tags'], $tagsTargets);

        $catTargets = $sanitizer->resolveMetaBoxTargetIds('category');
        $this->assertSame(['categorydiv', 'category', 'categories'], $catTargets);

        $unknownTargets = $sanitizer->resolveMetaBoxTargetIds('custom_meta');
        $this->assertSame(['custom_meta'], $unknownTargets);
    }

    public function testHandleMetaBoxesSetupRemovesMetaBoxes(): void
    {
        $removedBoxes = [];
        $user = (object) ['ID' => 3, 'roles' => ['editor']];

        $sanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $name): string => 'comments|hide:editor',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['comments'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user,
            userRestrictedChecker: static fn(array $rule, ?object $u): bool => $u === $user,
            metaBoxRemover: static function (
                string $id,
                string|object $screen,
                string $context
            ) use (&$removedBoxes): void {
                $removedBoxes[] = ['id' => $id, 'screen' => $screen, 'context' => $context];
            }
        );

        $sanitizer->handleMetaBoxesSetup('post');
        $this->assertNotEmpty($removedBoxes);
        $this->assertSame('comments', $removedBoxes[0]['id']);
    }

    public function testEnforcePostTypeSupportRestrictions(): void
    {
        $removedSupports = [];
        $user = (object) ['ID' => 3, 'roles' => ['editor']];

        $sanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $name): string => 'author|hide:editor',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['author'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user,
            userRestrictedChecker: static fn(array $rule, ?object $u): bool => $u === $user,
            postTypeSupportRemover: static function (string $postType, string $feature) use (&$removedSupports): void {
                $removedSupports[] = ['postType' => $postType, 'feature' => $feature];
            }
        );

        $sanitizer->enforcePostTypeSupportRestrictions();
        $this->assertNotEmpty($removedSupports);
        $this->assertSame('author', $removedSupports[0]['feature']);
    }

    public function testCustomFooterTextAndContainerHiding(): void
    {
        $user = (object) ['ID' => 3, 'roles' => ['editor']];

        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string|false => match ($name) {
                'WP_ADMIN_FOOTER_LEFT' => 'Custom Admin Footer|!superadmins',
                'WP_ADMIN_FOOTER_RIGHT' => 'v2.5.0|!superadmins',
                default => false,
            },
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['footer'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => true,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user
        );

        $this->assertSame('Custom Admin Footer', $sanitizer->filterAdminFooterText('Default WordPress'));
        $this->assertSame('v2.5.0', $sanitizer->filterUpdateFooter('Version 6.7'));
    }

    public function testFilterWpDieHandlerAndRenderStealthDie(): void
    {
        $terminated = false;
        $echoed = '';

        $defaultSanitizer = new UiSanitizer();
        /** @var callable(mixed, int|string, array<string, mixed>): void $defaultHandler */
        $defaultHandler = static function (mixed $msg, int|string $title, array $args): void {
        };
        $this->assertSame($defaultHandler, $defaultSanitizer->filterWpDieHandler($defaultHandler));

        $falseSanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'false'
        );
        $this->assertFalse($falseSanitizer->shouldUseStealthDie());

        $nullParserSanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'all',
            ruleParser: null
        );
        $this->assertFalse($nullParserSanitizer->shouldUseStealthDie());

        $emptyRuleSanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'all',
            ruleParser: static fn(string $raw): array => []
        );
        $this->assertFalse($emptyRuleSanitizer->shouldUseStealthDie());

        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'all',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['stealth_die'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => true,
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed .= $html;
            },
            terminator: static function () use (&$terminated): never {
                $terminated = true;
                throw new \RuntimeException('TERMINATED');
            }
        );

        $customHandler = $sanitizer->filterWpDieHandler($defaultHandler);
        $this->assertNotSame($defaultHandler, $customHandler);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TERMINATED');

        $sanitizer->renderStealthDie('Forbidden access', '403 Forbidden', ['response' => 403]);
        $this->assertTrue($terminated);
        $this->assertStringContainsString('403 Forbidden', $echoed);
    }

    public function testGoogleFontsHeadAndStylesHandlersAndEdgeCases(): void
    {
        $dequeued = [];
        $deregistered = [];
        $removedActions = [];

        $sanitizer = new UiSanitizer(
            actionRemover: static function (string $tag, callable|string $cb, int $prio) use (&$removedActions): void {
                $removedActions[] = ['tag' => $tag, 'cb' => $cb, 'prio' => $prio];
            },
            styleDequeuer: static function (string $handle) use (&$dequeued): void {
                $dequeued[] = $handle;
            },
            styleDeregisterer: static function (string $handle) use (&$deregistered): void {
                $deregistered[] = $handle;
            }
        );

        $wpStyles = new class {
            /** @var array<string, mixed> */
            public array $registered = [];
        };
        $wpStyles->registered['not_object'] = 'string_entry';
        $wpStyles->registered['google-font'] = (object) ['src' => 'https://fonts.gstatic.com/font.woff2'];
        $GLOBALS['wp_styles'] = $wpStyles;

        $sanitizer->handleDisableGoogleFontsHead();
        $sanitizer->handleDisableGoogleFontsStyles();

        $this->assertContains('google-font', $dequeued);
        $this->assertContains('google-font', $deregistered);

        unset($GLOBALS['wp_styles']);
        $sanitizer->stripGoogleFontsStyles();
    }

    public function testAdminNoticesAndScreenOptionsEmptyRules(): void
    {
        $sanitizerEmptyEnv = new UiSanitizer(
            envGetter: static fn(string $name): false => false
        );
        $this->assertFalse($sanitizerEmptyEnv->shouldHideAdminNotices());
        $this->assertFalse($sanitizerEmptyEnv->shouldHideScreenOptionsAndHelp());
        $this->assertTrue($sanitizerEmptyEnv->filterScreenOptionsShowScreen(true));
        $this->assertFalse($sanitizerEmptyEnv->filterScreenOptionsShowScreen(false));

        $sanitizerNoRules = new UiSanitizer(
            envGetter: static fn(string $name): string => 'notices',
            ruleParser: static fn(string $raw): array => []
        );
        $this->assertFalse($sanitizerNoRules->shouldHideAdminNotices());
        $this->assertFalse($sanitizerNoRules->shouldHideScreenOptionsAndHelp());

        $dequeuedStyles = [];
        $dequeuedScripts = [];
        $sanitizerScripts = new UiSanitizer(
            envGetter: static fn(string $name): string => 'all',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['notices'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $r, ?object $u): bool => true,
            currentUserGetter: static fn(): object => (object) ['ID' => 1],
            styleDequeuer: static function (string $h) use (&$dequeuedStyles): void {
                $dequeuedStyles[] = $h;
            },
            scriptDequeuer: static function (string $h) use (&$dequeuedScripts): void {
                $dequeuedScripts[] = $h;
            }
        );
        $sanitizerScripts->handleAdminEnqueueScripts();
        $this->assertSame(['wp-pointer'], $dequeuedStyles);
        $this->assertSame(['wp-pointer'], $dequeuedScripts);
    }

    public function testGutenbergPanelsAndMetaBoxesRendering(): void
    {
        $user = (object) ['ID' => 2];
        $echoed = '';

        $panels = [
            'tags' => 'taxonomy-panel-post_tag',
            'category' => 'taxonomy-panel-category',
            'featured_image' => 'featured-image',
            'excerpt' => 'post-excerpt',
            'discussion' => 'discussion-panel',
            'author' => null,
            'template' => null,
        ];

        $sanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $n): string => \implode(',', \array_keys($panels)),
            ruleParser: static fn(string $raw): array => [[
                'targets' => \array_keys($panels),
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            gutenbergPanelResolver: static fn(string $t): ?string => $panels[$t] ?? null,
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed .= $html;
            }
        );

        $sanitizer->renderHiddenMetaBoxesStyles();
        $this->assertStringContainsString('editor-post-author', $echoed);
        $this->assertStringContainsString('editor-post-template', $echoed);
        $this->assertStringContainsString('components-form-token-field__label', $echoed);
        $this->assertStringContainsString('editor-post-taxonomies__terms-list', $echoed);
        $this->assertStringContainsString('editor-post-featured-image', $echoed);
        $this->assertStringContainsString('editor-post-excerpt', $echoed);
        $this->assertStringContainsString('editor-post-discussion', $echoed);

        $this->assertContains('existing', $sanitizer->filterDefaultHiddenColumns(['existing']));
    }

    public function testHandleMetaBoxesSetupWithGlobalWpMetaBoxes(): void
    {
        $user = (object) ['ID' => 2];
        $screen = (object) ['id' => 'post'];
        $removedBoxes = [];
        $removedSupports = [];

        $GLOBALS['wp_meta_boxes'] = [
            'post' => [
                'normal' => [
                    'high' => [
                        'authordiv' => ['title' => 'Author'],
                        'keep_box' => ['title' => 'Keep'],
                    ],
                ],
                'side' => 'invalid_context',
            ],
            'page' => 'invalid_page',
        ];

        $sanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            currentScreenGetter: static fn(): object => $screen,
            envGetter: static fn(string $n): string => 'author,template',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['author', 'template'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            metaBoxRemover: static function (string $id, mixed $scr, string $ctx) use (&$removedBoxes): void {
                $removedBoxes[] = $id;
            },
            postTypeSupportRemover: static function (string $type, string $feat) use (&$removedSupports): void {
                $removedSupports[] = [$type, $feat];
            }
        );

        $sanitizer->handleMetaBoxesSetup();
        $this->assertArrayNotHasKey('authordiv', $GLOBALS['wp_meta_boxes']['post']['normal']['high']);
        $this->assertArrayHasKey('keep_box', $GLOBALS['wp_meta_boxes']['post']['normal']['high']);
        $this->assertNotEmpty($removedBoxes);
        $this->assertNotEmpty($removedSupports);

        $emptySanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $n): false => false
        );
        $emptySanitizer->handleMetaBoxesSetup();
        $this->assertSame([], $emptySanitizer->resolveMetaBoxTargetIds(''));
    }

    public function testRenderStealthDieVariations(): void
    {
        $echoedHtml = [];
        $sanitizer = new UiSanitizer(
            htmlEchoer: static function (string $html) use (&$echoedHtml): void {
                $echoedHtml[] = $html;
            },
            terminator: static function (): never {
                throw new \RuntimeException('STEALTH_DIE_CALLED');
            }
        );

        try {
            $wpError = new \WP_Error('test_code', 'Custom Error Message');
            $sanitizer->renderStealthDie($wpError, 'Error Title', ['response' => 500]);
        } catch (\RuntimeException $e) {
            $this->assertSame('STEALTH_DIE_CALLED', $e->getMessage());
        }

        try {
            $sanitizer->renderStealthDie('', '', ['response' => 200]);
        } catch (\RuntimeException $e) {
            $this->assertSame('STEALTH_DIE_CALLED', $e->getMessage());
        }

        try {
            $sanitizer->renderStealthDie('', '', ['response' => 403]);
        } catch (\RuntimeException $e) {
            $this->assertSame('STEALTH_DIE_CALLED', $e->getMessage());
        }

        $this->assertCount(3, $echoedHtml);
        $this->assertStringContainsString('Custom Error Message', $echoedHtml[0]);
        $this->assertStringContainsString('#ef4444', $echoedHtml[0]);
        $this->assertStringContainsString('500 Error', $echoedHtml[1]);
        $this->assertStringContainsString('#ef4444', $echoedHtml[1]);
        $this->assertStringContainsString('Access denied.', $echoedHtml[2]);
        $this->assertStringContainsString('#f59e0b', $echoedHtml[2]);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testUiSanitizerDefaultFallbacksWithGlobalStubs(): void
    {
        ensure_admin_control_stubs();

        $backupCurrentUser = $GLOBALS['test_current_user'] ?? null;
        $backupCurrentScreen = $GLOBALS['test_current_screen'] ?? null;

        try {
            $GLOBALS['test_current_user'] = (object) ['ID' => 1, 'roles' => ['administrator']];
            $GLOBALS['test_current_screen'] = (object) ['id' => 'dashboard'];

            $sanitizer = new UiSanitizer();
            $this->assertSame([], $sanitizer->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES'));

            \ob_start();
            $sanitizer->handleAdminHead();
            \ob_get_clean();

            $screen = new class {
                public string $id = 'dashboard';
            };
            $sanitizer->handleCurrentScreen($screen);
        } finally {
            $GLOBALS['test_current_user'] = $backupCurrentUser;
            $GLOBALS['test_current_screen'] = $backupCurrentScreen;
        }
    }

    public function testStripGoogleFontsStylesWhenRegisteredIsNotArray(): void
    {
        $wpStyles = new stdClass();
        $wpStyles->registered = null;
        $GLOBALS['wp_styles'] = $wpStyles;

        $sanitizer = new UiSanitizer();
        $sanitizer->stripGoogleFontsStyles();
        $this->assertNull($wpStyles->registered);
    }

    public function testNoticeAndScreenOptionsNullRuleParserBranches(): void
    {
        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => '!superadmins',
            ruleParser: null
        );

        $this->assertFalse($sanitizer->shouldHideAdminNotices());
        $this->assertFalse($sanitizer->shouldHideScreenOptionsAndHelp());
    }

    public function testHandleAdminHeadCallsRemoveHelpTabsOnCurrentScreen(): void
    {
        $screen = new class {
            public bool $removed = false;

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_help_tabs') {
                    $this->removed = true;
                    return null;
                }
                return null;
            }
        };

        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string|false => 'WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP' === $name
                ? '!superadmins'
                : false,
            currentScreenGetter: static fn(): object => $screen,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['screen-options-help'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => true,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $user): bool => true,
            htmlEchoer: static fn(string $html): null => null
        );

        $sanitizer->handleAdminHead();
        $this->assertTrue($screen->removed);
    }

    public function testFilterScreenColumnsWhenNoTargetsToHide(): void
    {
        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): false => false
        );

        $cols = ['cb' => '<input>', 'title' => 'Title'];
        $this->assertSame($cols, $sanitizer->filterScreenColumns($cols));
    }

    public function testResolveMetaBoxTargetIdsRemainingAliases(): void
    {
        $sanitizer = new UiSanitizer();
        $this->assertSame(['slugdiv', 'slug'], $sanitizer->resolveMetaBoxTargetIds('slug'));
        $this->assertSame(['revisionsdiv', 'revisions'], $sanitizer->resolveMetaBoxTargetIds('revisions'));
        $this->assertSame(
            ['postcustom', 'custom-fields', 'custom_fields'],
            $sanitizer->resolveMetaBoxTargetIds('custom_fields')
        );
        $this->assertSame(['wpseo_meta', 'yoast-seo', 'yoast'], $sanitizer->resolveMetaBoxTargetIds('yoast'));
    }

    public function testEnforcePostTypeSupportRestrictionsBranches(): void
    {
        $emptySanitizer = new UiSanitizer(
            envGetter: static fn(string $name): false => false
        );
        $emptySanitizer->enforcePostTypeSupportRestrictions();

        $removed = [];
        $templateSanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => (object) ['ID' => 2],
            envGetter: static fn(string $name): string => 'template',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['template'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            postTypeSupportRemover: static function (string $type, string $feat) use (&$removed): void {
                $removed[] = [$type, $feat];
            }
        );

        $templateSanitizer->enforcePostTypeSupportRestrictions();
        $this->assertSame([['post', 'page-attributes'], ['page', 'page-attributes']], $removed);
    }

    public function testGetCustomFooterTextEdgeCases(): void
    {
        $sanitizerNoRuleParser = new UiSanitizer(
            envGetter: static fn(string $name): string => 'Custom Text|editor',
            ruleParser: null
        );
        $this->assertNull($sanitizerNoRuleParser->getCustomFooterText('WP_ADMIN_FOOTER_LEFT'));

        $sanitizerEmptyRules = new UiSanitizer(
            envGetter: static fn(string $name): string => 'Custom Text|editor',
            ruleParser: static fn(string $raw): array => []
        );
        $this->assertNull($sanitizerEmptyRules->getCustomFooterText('WP_ADMIN_FOOTER_LEFT'));

        $user = (object) ['ID' => 5];
        $sanitizerNoMatch = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $name): string => '   ;  |editor ; Custom Text|administrator',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['footer'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['administrator'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => false
        );
        $this->assertNull($sanitizerNoMatch->getCustomFooterText('WP_ADMIN_FOOTER_LEFT'));

        $sanitizerRemove = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $name): string => 'remove|all',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['footer'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => true
        );
        $this->assertSame('', $sanitizerRemove->getCustomFooterText('WP_ADMIN_FOOTER_LEFT'));
        $this->assertSame('', $sanitizerRemove->filterAdminFooterText('Default Left'));
        $this->assertSame('', $sanitizerRemove->filterUpdateFooter('Default Right'));
    }

    public function testFilterFooterTextSanitizesHtml(): void
    {
        $user = (object) ['ID' => 5];
        $malicious = 'Custom <a href="https://example.com">Link</a><script>alert(1)</script>';

        $sanitizer = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $name): string => $malicious . '|all',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['footer'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => true
        );

        $expected = 'Custom &lt;a href=&quot;https://example.com&quot;&gt;Link&lt;/a&gt;'
            . '&lt;script&gt;alert(1)&lt;/script&gt;';
        $left = $sanitizer->filterAdminFooterText('Default Left');
        $this->assertSame($expected, $left);

        $right = $sanitizer->filterUpdateFooter('Default Right');
        $this->assertSame($expected, $right);

        $sanitizerDefault = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            envGetter: static fn(string $name): false => false
        );
        $this->assertSame('Default Text', $sanitizerDefault->filterAdminFooterText('Default Text'));
        $this->assertSame('Default Version', $sanitizerDefault->filterUpdateFooter('Default Version'));
    }

    public function testShouldHideAdminFooterContainerWhenUserIsNull(): void
    {
        $sanitizer = new UiSanitizer(
            currentUserGetter: static fn(): ?object => null
        );
        $this->assertFalse($sanitizer->shouldHideAdminFooterContainer());
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testFilterWpDieHandlerInvokesStealthDieAndFallbackToWpDie(): void
    {
        ensure_admin_control_stubs();

        $echoed = '';
        $terminated = false;
        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'all',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['stealth_die'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => true,
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed .= $html;
            },
            terminator: static function () use (&$terminated): void {
                $terminated = true;
            }
        );

        $handler = $sanitizer->filterWpDieHandler(static function (): void {
        });
        $handler('Blocked request', '403 Forbidden', ['response' => 403]);

        $this->assertStringContainsString('Blocked request', $echoed);
        $this->assertTrue($terminated);
        $this->assertSame(403, $GLOBALS['test_status_header'] ?? 0);
        $this->assertTrue($GLOBALS['test_nocache_headers_called'] ?? false);
    }

    public function testApplyHiddenListAndGetHiddenTargetsForCurrentUserBranches(): void
    {
        $sanitizerNoUser = new UiSanitizer(
            currentUserGetter: static fn(): ?object => null
        );
        $this->assertSame(['existing'], $sanitizerNoUser->filterHiddenMetaBoxes(['existing']));

        $user = (object) ['ID' => 1];
        $sanitizerSuperAdmin = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => true
        );
        $this->assertSame([], $sanitizerSuperAdmin->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES'));

        $sanitizerNullParser = new UiSanitizer(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => false,
            envGetter: static fn(string $name): string => 'author',
            ruleParser: null
        );
        $this->assertSame([], $sanitizerNullParser->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES'));
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testGlobalFallbacksForPostTypesActionsAndStyles(): void
    {
        ensure_admin_control_stubs();

        $GLOBALS['test_post_types'] = ['post' => 'post', 'page' => 'page', 'custom' => 'custom'];
        $GLOBALS['test_current_user'] = (object) ['ID' => 10, 'roles' => ['editor']];
        $GLOBALS['test_current_screen'] = (object) ['id' => 'post'];
        $GLOBALS['test_removed_post_type_supports'] = [];
        $GLOBALS['test_removed_meta_boxes'] = [];
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_removed_all_actions'] = [];

        $sanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string|false => match ($name) {
                'WP_ADMIN_HIDDEN_META_BOXES' => 'author',
                'WP_ADMIN_HIDE_NOTICES' => 'all',
                'WP_ADMIN_FOOTER_LEFT' => '<b>Footer</b><script>evil()</script>|all',
                'WP_ADMIN_FOOTER_RIGHT' => '<i>v1.0</i><script>evil()</script>|all',
                default => false,
            },
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['author', 'notices', 'footer'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true
        );

        $wpStyles = new class {
            /** @var array<string, object> */
            public array $registered = [];
        };
        $wpStyles->registered['google-font'] = (object) ['src' => 'https://fonts.googleapis.com/font.woff2'];
        $GLOBALS['wp_styles'] = $wpStyles;

        $sanitizer->handleDisableGoogleFontsInit();
        $sanitizer->handleAdminPrintScripts();
        $sanitizer->handleMetaBoxesSetup('post');
        $sanitizer->stripGoogleFontsStyles();
        $this->assertSame(
            '&lt;b&gt;Footer&lt;/b&gt;&lt;script&gt;evil()&lt;/script&gt;',
            $sanitizer->filterAdminFooterText('Default')
        );
        $this->assertSame(
            '&lt;i&gt;v1.0&lt;/i&gt;&lt;script&gt;evil()&lt;/script&gt;',
            $sanitizer->filterUpdateFooter('Default')
        );

        $this->assertNotEmpty($GLOBALS['test_removed_post_type_supports']);
        $this->assertNotEmpty($GLOBALS['test_removed_meta_boxes']);
        $this->assertNotEmpty($GLOBALS['test_removed_all_actions']);

        $GLOBALS['test_post_types'] = [];
        $sanitizer->enforcePostTypeSupportRestrictions();

        \ob_start();
        $echoSanitizer = new UiSanitizer(
            envGetter: static fn(string $name): string => 'all',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['screen-options-help'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $r, ?object $u): bool => true
        );
        $echoSanitizer->handleAdminHead();
        $output = \ob_get_clean();
        $this->assertIsString($output);
        $this->assertStringContainsString('wp-admin-control-hide-screen-options-help', $output);
    }

    public function testRenderStealthDieNormalReturnBranch(): void
    {
        $called = false;
        $terminatorSanitizer = new UiSanitizer(
            htmlEchoer: static fn(string $h): null => null,
            terminator: static function () use (&$called): void {
                $called = true;
            }
        );
        $terminatorSanitizer->renderStealthDie('msg');
        $this->assertTrue($called);
    }
}
