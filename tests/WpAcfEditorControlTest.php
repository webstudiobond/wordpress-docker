<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use stdClass;
use WpAcfEditorControl;

final class WpAcfEditorControlTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['test_posts'],
            $GLOBALS['test_wp_options'],
            $GLOBALS['test_pll_translations'],
            $GLOBALS['test_acf_field_groups']
        );
        parent::tearDown();
    }

    public function testUpdateCacheReturnsFalseWhenAcfUnavailable(): void
    {
        $control = new WpAcfEditorControl();

        $this->assertFalse($control->updateCache());
        $this->assertTrue($control->disableForPostType(true, 'page'));
    }

    #[Depends('testUpdateCacheReturnsFalseWhenAcfUnavailable')]
    public function testFallbacksWhenGlobalFunctionsUnavailable(): void
    {
        $fieldGroups = [
            [
                'hide_on_screen' => ['the_content'],
                'location' => [
                    [
                        ['param' => 'page', 'operator' => '==', 'value' => 42],
                    ],
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                    ],
                ],
            ],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => $fieldGroups
        );

        $this->assertTrue($control->updateCache());
        $this->assertFalse($control->disableForSpecificPost(false, 999));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[Depends('testFallbacksWhenGlobalFunctionsUnavailable')]
    public function testGlobalWordPressAndAcfFunctionFallbacks(): void
    {
        ensure_acf_stubs();

        $GLOBALS['test_wp_options'] = [
            'page_on_front' => 10,
        ];
        $GLOBALS['test_pll_translations'] = [
            10 => ['en' => 10, 'de' => 11],
        ];
        $GLOBALS['test_acf_field_groups'] = [
            [
                'hide_on_screen' => ['the_content'],
                'location' => [
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'portfolio'],
                    ],
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                    ],
                ],
            ],
        ];
        $GLOBALS['test_posts'] = [
            50 => (object) [
                'ID' => 50,
                'post_type' => 'invalid post type!!',
            ],
        ];

        $control = new WpAcfEditorControl();

        $this->assertTrue($control->updateCache());
        $this->assertFalse($control->disableForPostType(true, 'portfolio'));
        $this->assertFalse($control->disableForSpecificPost(true, (object) ['ID' => 11, 'post_type' => 'page']));
        $this->assertTrue($control->disableForSpecificPost(true, 999));

        ensure_post_stubs();

        $revision = (object) ['ID' => 51, 'post_type' => 'revision', 'post_parent' => 50];
        $this->assertTrue($control->disableForSpecificPost(true, $revision));
        $this->assertTrue($control->disableForSpecificPost(true, 777));
    }

    public function testUpdateCacheProcessesPostTypesPagesPageTypesAndPolylangTranslations(): void
    {
        /** @var array<string, mixed> $savedOptions */
        $savedOptions = [
            'page_on_front' => 10,
            'page_for_posts' => '20',
        ];

        $fieldGroups = [
            [
                'hide_on_screen' => ['the_content', 'excerpt'],
                'location' => [
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'portfolio'],
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'portfolio'],
                    ],
                    [
                        ['param' => 'post_type', 'operator' => '!=', 'value' => 'ignored_type'],
                    ],
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'page'],
                        ['param' => 'page', 'operator' => '==', 'value' => '42'],
                    ],
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                    ],
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'posts_page'],
                    ],
                    [
                        ['param' => 'post', 'operator' => '==', 'value' => ' 99 '],
                    ],
                ],
            ],
            [
                'active' => false,
                'hide_on_screen' => ['the_content'],
                'location' => [
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'inactive_type'],
                    ],
                ],
            ],
            [
                'post_status' => 'trash',
                'hide_on_screen' => ['the_content'],
                'location' => [
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'trashed_type'],
                    ],
                ],
            ],
            [
                'post_status' => 'draft',
                'hide_on_screen' => ['the_content'],
                'location' => [
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'draft_type'],
                    ],
                ],
            ],
            [
                'hide_on_screen' => ['custom_fields'],
                'location' => [
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'visible_type'],
                    ],
                ],
            ],
            [
                'hide_on_screen' => 'invalid_not_array',
            ],
            'invalid_group_entry',
        ];

        $translationsMap = [
            10 => ['en' => 10, 'de' => 11],
            20 => ['en' => 20, 'de' => '21'],
            42 => ['en' => 42, 'fr' => 43],
            99 => ['en' => 99, 'es' => ' 100 '],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => $fieldGroups,
            optionGetter: static function (string $name, mixed $default) use (&$savedOptions): mixed {
                return $savedOptions[$name] ?? $default;
            },
            optionUpdater: static function (
                string $name,
                mixed $value,
                bool $autoload
            ) use (&$savedOptions): bool {
                $savedOptions[$name] = $value;
                return $autoload;
            },
            translationsGetter: static fn(int $postId): array => $translationsMap[$postId] ?? []
        );

        $this->assertTrue($control->updateCache());
        $this->assertSame(['portfolio'], $savedOptions[WpAcfEditorControl::OPTION_POST_TYPES]);
        $this->assertSame([42, 43, 10, 11, 20, 21, 99, 100], $savedOptions[WpAcfEditorControl::OPTION_PAGE_IDS]);
        $this->assertNotContains('page', $savedOptions[WpAcfEditorControl::OPTION_POST_TYPES]);
        $this->assertNotContains('inactive_type', $savedOptions[WpAcfEditorControl::OPTION_POST_TYPES]);
        $this->assertNotContains('trashed_type', $savedOptions[WpAcfEditorControl::OPTION_POST_TYPES]);
        $this->assertNotContains('draft_type', $savedOptions[WpAcfEditorControl::OPTION_POST_TYPES]);
    }

    public function testUpdateCacheSkipsMalformedLocationsAndRules(): void
    {
        /** @var array<string, mixed> $savedOptions */
        $savedOptions = [];

        $fieldGroups = [
            [
                'hide_on_screen' => ['the_content'],
                'location' => 'not_an_array',
            ],
            [
                'hide_on_screen' => ['the_content'],
                'location' => [
                    'not_a_location_group',
                    [
                        'not_a_rule_array',
                    ],
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => '   '],
                    ],
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 12345],
                    ],
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'slug_exceeds_twenty_ch'],
                    ],
                    [
                        ['param' => 'page', 'operator' => '==', 'value' => 0],
                    ],
                    [
                        ['param' => 'page', 'operator' => '==', 'value' => -5],
                    ],
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'unknown_type'],
                    ],
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                    ],
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'posts_page'],
                    ],
                    [
                        ['param' => 'unknown_param', 'operator' => '==', 'value' => 'something'],
                    ],
                    [
                        ['param' => ['not_a_string'], 'operator' => '==', 'value' => 'something'],
                    ],
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'post'],
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'page'],
                    ],
                    [
                        ['param' => 'page', 'operator' => '==', 'value' => 10],
                        ['param' => 'page', 'operator' => '==', 'value' => 20],
                    ],
                ],
            ],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => $fieldGroups,
            optionGetter: static fn(string $name, mixed $default): mixed => 'non_numeric',
            optionUpdater: static function (string $name, mixed $value) use (&$savedOptions): bool {
                $savedOptions[$name] = $value;
                return true;
            }
        );

        $this->assertTrue($control->updateCache());
        $this->assertSame([], $savedOptions[WpAcfEditorControl::OPTION_POST_TYPES]);
        $this->assertSame([], $savedOptions[WpAcfEditorControl::OPTION_PAGE_IDS]);
    }

    public function testDisableForPostTypeUsesCachedOption(): void
    {
        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => [],
            optionGetter: static fn(string $name, mixed $default): mixed => ['portfolio', 'landing']
        );

        $this->assertFalse($control->disableForPostType(true, 'portfolio'));
        $this->assertFalse($control->disableForPostType(true, 'Portfolio'));
        $this->assertFalse($control->disableForPostType(true, '  LANDING  '));
        $this->assertTrue($control->disableForPostType(true, 'post'));
        $this->assertFalse($control->disableForPostType(false, 'post'));
    }

    public function testDisableForPostTypeHandlesInvalidSlugAndInitializesCacheWhenMissing(): void
    {
        $updateCount = 0;
        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static function () use (&$updateCount): array {
                $updateCount++;
                return [];
            },
            optionGetter: static fn(string $name, mixed $default): mixed => $default,
            optionUpdater: static fn(): bool => true
        );

        $this->assertTrue($control->disableForPostType(true, 'invalid slug!'));
        $this->assertFalse($control->disableForPostType(false, 'invalid slug!'));
        $this->assertSame(0, $updateCount);

        $this->assertTrue($control->disableForPostType(true, 'valid_type'));
        $this->assertSame(1, $updateCount);
    }

    public function testDisableForSpecificPostChecksPostTypeAndPageIds(): void
    {
        $options = [
            WpAcfEditorControl::OPTION_POST_TYPES => ['portfolio'],
            WpAcfEditorControl::OPTION_PAGE_IDS => [15, '25'],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => [],
            optionGetter: static fn(string $name, mixed $default): mixed => $options[$name] ?? $default
        );

        $portfolioPost = (object) ['ID' => 99, 'post_type' => 'portfolio'];
        $portfolioUppercasePost = (object) ['ID' => 98, 'post_type' => '  Portfolio  '];
        $hiddenPage = (object) ['ID' => 25, 'post_type' => 'page'];
        $normalPage = (object) ['ID' => 30, 'post_type' => 'page'];
        $invalidIdPost = (object) ['ID' => 0, 'post_type' => 'page'];

        $this->assertFalse($control->disableForSpecificPost(true, $portfolioPost));
        $this->assertFalse($control->disableForSpecificPost(true, $portfolioUppercasePost));
        $this->assertFalse($control->disableForSpecificPost(true, $hiddenPage));
        $this->assertTrue($control->disableForSpecificPost(true, $normalPage));
        $this->assertTrue($control->disableForSpecificPost(true, $invalidIdPost));
        $this->assertTrue($control->disableForSpecificPost(true, 'not_an_object'));
    }

    public function testDisableForSpecificPostHandlesInvalidInputs(): void
    {
        $options = [
            WpAcfEditorControl::OPTION_POST_TYPES => ['portfolio'],
            WpAcfEditorControl::OPTION_PAGE_IDS => [15],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => [],
            optionGetter: static fn(string $name, mixed $default): mixed => $options[$name] ?? $default
        );

        $postWithInvalidSlug = (object) ['ID' => 15, 'post_type' => 'invalid slug!'];
        $postWithoutType = (object) ['ID' => 15];
        $postWithoutId = (object) ['post_type' => 'page'];
        $normalPost = (object) ['ID' => 20, 'post_type' => 'page'];

        $this->assertFalse($control->disableForSpecificPost(false, $postWithInvalidSlug));
        $this->assertFalse($control->disableForSpecificPost(true, $postWithInvalidSlug));
        $this->assertFalse($control->disableForSpecificPost(true, $postWithoutType));
        $this->assertTrue($control->disableForSpecificPost(true, $postWithoutId));
        $this->assertTrue($control->disableForSpecificPost(true, $normalPost));
    }

    public function testTranslationsGetterHandlesInvalidAndDuplicateIds(): void
    {
        $translations = [
            'en' => 10,
            'de' => '10',
            'fr' => -1,
            'es' => 'invalid',
            'it' => 20,
        ];

        $fieldGroups = [
            [
                'hide_on_screen' => ['the_content'],
                'location' => [
                    [
                        ['param' => 'page', 'operator' => '==', 'value' => 10],
                    ],
                ],
            ],
        ];

        /** @var array<string, mixed> $savedOptions */
        $savedOptions = [];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => $fieldGroups,
            optionUpdater: static function (string $name, mixed $value) use (&$savedOptions): bool {
                $savedOptions[$name] = $value;
                return true;
            },
            translationsGetter: static fn(int $postId): array => $translations
        );

        $this->assertTrue($control->updateCache());
        $this->assertSame([10, 20], $savedOptions[WpAcfEditorControl::OPTION_PAGE_IDS]);
    }

    public function testOptionListParsersFilterDuplicatesAndInvalidValues(): void
    {
        $options = [
            WpAcfEditorControl::OPTION_POST_TYPES => ['portfolio', 'portfolio', 'invalid slug!', 123],
            WpAcfEditorControl::OPTION_PAGE_IDS => [15, 15, '15', ' 15 ', -1, 'invalid'],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => [],
            optionGetter: static fn(string $name, mixed $default): mixed => $options[$name] ?? $default
        );

        $this->assertFalse($control->disableForPostType(true, 'portfolio'));
        $this->assertTrue($control->disableForPostType(true, 'other'));

        $matchingPost = (object) ['ID' => 15, 'post_type' => 'other'];
        $this->assertFalse($control->disableForSpecificPost(true, $matchingPost));
    }

    public function testDisableHandlesNonArrayOptionGracefully(): void
    {
        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => [],
            optionGetter: static fn(): string => 'corrupted_option_value'
        );

        $post = new stdClass();
        $post->ID = 10;
        $post->post_type = 'page';

        $this->assertTrue($control->disableForPostType(true, 'page'));
        $this->assertTrue($control->disableForSpecificPost(true, $post));
    }

    public function testRegisterHooksWithInjectedRegistrars(): void
    {
        /** @var list<array{string, callable, int, int}> $actions */
        $actions = [];
        /** @var list<array{string, callable, int, int}> $filters */
        $filters = [];

        $actionRegistrar = static function (
            string $tag,
            callable $callback,
            int $priority,
            int $acceptedArgs
        ) use (&$actions): void {
            $actions[] = [$tag, $callback, $priority, $acceptedArgs];
        };

        $filterRegistrar = static function (
            string $tag,
            callable $callback,
            int $priority,
            int $acceptedArgs
        ) use (&$filters): void {
            $filters[] = [$tag, $callback, $priority, $acceptedArgs];
        };

        $control = new WpAcfEditorControl();
        $control->registerHooks($actionRegistrar, $filterRegistrar);

        $this->assertCount(14, $actions);
        $this->assertSame('acf/update_field_group', $actions[0][0]);
        $this->assertSame('acf/trash_field_group', $actions[1][0]);
        $this->assertSame('acf/untrash_field_group', $actions[2][0]);
        $this->assertSame('acf/delete_field_group', $actions[3][0]);
        $this->assertSame('update_option_page_on_front', $actions[4][0]);
        $this->assertSame('update_option_page_for_posts', $actions[5][0]);
        $this->assertSame('update_option_show_on_front', $actions[6][0]);
        $this->assertSame('delete_option_page_on_front', $actions[7][0]);
        $this->assertSame('delete_option_page_for_posts', $actions[8][0]);
        $this->assertSame('delete_option_show_on_front', $actions[9][0]);
        $this->assertSame('pll_save_post', $actions[10][0]);
        $this->assertSame('after_switch_theme', $actions[11][0]);
        $this->assertSame('activated_plugin', $actions[12][0]);
        $this->assertSame('deactivated_plugin', $actions[13][0]);

        $this->assertCount(2, $filters);
        $this->assertSame('use_block_editor_for_post_type', $filters[0][0]);
        $this->assertSame('use_block_editor_for_post', $filters[1][0]);
    }

    public function testResolveOptionPageIdSkipsWhenShowOnFrontIsPosts(): void
    {
        $fieldGroups = [
            [
                'hide_on_screen' => ['the_content'],
                'location' => [
                    [
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                    ],
                ],
            ],
        ];

        $options = [
            'show_on_front' => 'posts',
            'page_on_front' => 10,
        ];
        /** @var array<string, mixed> $savedOptions */
        $savedOptions = [];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => $fieldGroups,
            optionGetter: static fn(string $name, mixed $default): mixed => $options[$name] ?? $default,
            optionUpdater: static function (string $name, mixed $value) use (&$savedOptions): bool {
                $savedOptions[$name] = $value;
                return true;
            }
        );

        $this->assertTrue($control->updateCache());
        $this->assertSame([], $savedOptions[WpAcfEditorControl::OPTION_PAGE_IDS]);
    }

    public function testDisableForSpecificPostHandlesRevisionsAndNumericIds(): void
    {
        $posts = [
            50 => (object) [
                'ID' => 50,
                'post_type' => 'portfolio',
            ],
            60 => (object) [
                'ID' => 60,
                'post_type' => 'page',
            ],
            501 => (object) [
                'ID' => 501,
                'post_type' => 'revision',
                'post_parent' => 15,
            ],
            502 => (object) [
                'ID' => 502,
                'post_type' => 'revision',
                'post_parent' => 50,
            ],
            503 => (object) [
                'ID' => 503,
                'post_type' => 'revision',
                'post_parent' => 60,
            ],
        ];

        $options = [
            WpAcfEditorControl::OPTION_POST_TYPES => ['portfolio'],
            WpAcfEditorControl::OPTION_PAGE_IDS => [15],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => [],
            optionGetter: static fn(string $name, mixed $default): mixed => $options[$name] ?? $default,
            postGetter: static fn(int $id): ?object => $posts[$id] ?? null
        );

        $revisionOfDisabledPage = (object) [
            'ID' => 501,
            'post_type' => 'revision',
            'post_parent' => 15,
        ];
        $this->assertFalse($control->disableForSpecificPost(true, $revisionOfDisabledPage));

        $revisionOfDisabledType = (object) [
            'ID' => 502,
            'post_type' => 'revision',
            'post_parent' => 50,
        ];
        $this->assertFalse($control->disableForSpecificPost(true, $revisionOfDisabledType));

        $revisionOfNormalPage = (object) [
            'ID' => 503,
            'post_type' => 'revision',
            'post_parent' => 60,
        ];
        $this->assertTrue($control->disableForSpecificPost(true, $revisionOfNormalPage));

        $this->assertFalse($control->disableForSpecificPost(true, 15));
        $this->assertFalse($control->disableForSpecificPost(true, '15'));
        $this->assertFalse($control->disableForSpecificPost(true, ' 15 '));
        $this->assertFalse($control->disableForSpecificPost(true, 50));
        $this->assertFalse($control->disableForSpecificPost(true, 501));
        $this->assertFalse($control->disableForSpecificPost(true, '501'));
        $this->assertFalse($control->disableForSpecificPost(true, ' 501 '));
        $this->assertFalse($control->disableForSpecificPost(true, 502));
        $this->assertTrue($control->disableForSpecificPost(true, 503));
        $this->assertTrue($control->disableForSpecificPost(true, 60));
        $this->assertTrue($control->disableForSpecificPost(true, 0));
        $this->assertTrue($control->disableForSpecificPost(true, ' 0 '));
        $this->assertTrue($control->disableForSpecificPost(true, '   '));
    }

    public function testPageTypeWhenFrontPageNotConfiguredDoesNotDisablePostType(): void
    {
        $fieldGroups = [
            [
                'hide_on_screen' => ['the_content' => 1],
                'location' => [
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'page'],
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                    ],
                    [
                        ['param' => 'post_type', 'operator' => '==', 'value' => 'page'],
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'posts_page'],
                    ],
                ],
            ],
        ];

        /** @var array<string, mixed> $savedOptions */
        $savedOptions = [];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => $fieldGroups,
            optionGetter: static fn(string $name, mixed $default): mixed => 0,
            optionUpdater: static function (string $name, mixed $value) use (&$savedOptions): bool {
                $savedOptions[$name] = $value;
                return true;
            }
        );

        $this->assertTrue($control->updateCache());
        $this->assertSame([], $savedOptions[WpAcfEditorControl::OPTION_POST_TYPES]);
        $this->assertSame([], $savedOptions[WpAcfEditorControl::OPTION_PAGE_IDS]);
    }

    public function testUpdateCacheRecursionGuardAndColdStartOnce(): void
    {
        $innerResult = true;
        $control = null;

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static function () use (&$control, &$innerResult): array {
                if ($control !== null) {
                    $innerResult = $control->updateCache();
                }
                return [];
            },
            optionUpdater: static fn(): bool => true
        );

        $this->assertTrue($control->updateCache());
        $this->assertFalse($innerResult);

        $callCount = 0;
        $uninitializedControl = new WpAcfEditorControl(
            fieldGroupsProvider: static function () use (&$callCount): ?array {
                $callCount++;
                return null;
            },
            optionGetter: static fn(string $name, mixed $default): mixed => null
        );

        $this->assertTrue($uninitializedControl->disableForPostType(true, 'post'));
        $this->assertTrue($uninitializedControl->disableForPostType(true, 'page'));
        $this->assertSame(1, $callCount);
    }

    #[DependsExternal(WpPerformanceTest::class, 'testFinishFastcgiReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testOnUpgraderProcessCompleteReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(WpTelemetryBlockerTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(
        WpTranslationUpdatesDisablerTest::class,
        'testRegisterHooksReturnsEarlyWhenAddFilterDoesNotExist'
    )]
    public function testRegisterHooksWithoutRegistrarsUsesGlobalFunctions(): void
    {
        ensure_wordpress_stubs();
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];

        $control = new WpAcfEditorControl();
        $control->registerHooks();

        $this->assertArrayHasKey('acf/update_field_group', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('acf/delete_field_group', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('use_block_editor_for_post_type', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('use_block_editor_for_post', $GLOBALS['test_registered_filters']);
        unset($GLOBALS['test_registered_actions'], $GLOBALS['test_registered_filters']);
    }

    public function testDisableForSpecificPostWithInvalidPostSkipsCacheInitialization(): void
    {
        $cacheCheckAttempted = false;
        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static function () use (&$cacheCheckAttempted): array {
                $cacheCheckAttempted = true;
                return [];
            },
            optionGetter: static function (string $name, mixed $default) use (&$cacheCheckAttempted): mixed {
                $cacheCheckAttempted = true;
                return $default;
            }
        );

        $this->assertTrue($control->disableForSpecificPost(true, 'not_an_object'));
        $this->assertTrue($control->disableForSpecificPost(true, null));
        $this->assertTrue($control->disableForSpecificPost(true, 0));
        $this->assertTrue($control->disableForSpecificPost(true, -10));
        $this->assertTrue($control->disableForSpecificPost(true, (object) ['ID' => 0, 'post_type' => 'invalid slug!']));
        $this->assertTrue($control->disableForSpecificPost(true, (object) []));
        $this->assertFalse($cacheCheckAttempted);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlockEditorRemainsEnabledWhenAcfIsDeactivated(): void
    {
        $options = [
            WpAcfEditorControl::OPTION_POST_TYPES => ['portfolio'],
            WpAcfEditorControl::OPTION_PAGE_IDS => [10],
        ];

        $control = new WpAcfEditorControl(
            optionGetter: static fn(string $name, mixed $default): mixed => $options[$name] ?? $default
        );

        $this->assertTrue($control->disableForPostType(true, 'portfolio'));
        $this->assertFalse($control->disableForPostType(false, 'portfolio'));

        $portfolioPost = (object) ['ID' => 99, 'post_type' => 'portfolio'];
        $hiddenPage = (object) ['ID' => 10, 'post_type' => 'page'];

        $this->assertTrue($control->disableForSpecificPost(true, $portfolioPost));
        $this->assertFalse($control->disableForSpecificPost(false, $portfolioPost));
        $this->assertTrue($control->disableForSpecificPost(true, $hiddenPage));
        $this->assertFalse($control->disableForSpecificPost(false, $hiddenPage));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenWordPressFunctionsExist(): void
    {
        ensure_wordpress_stubs();
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];

        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-acf-editor-control.php.example';

        $this->assertArrayHasKey('acf/update_field_group', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('acf/delete_field_group', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('use_block_editor_for_post_type', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('use_block_editor_for_post', $GLOBALS['test_registered_filters']);
    }
}
