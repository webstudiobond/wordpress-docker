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
                ],
            ],
        ];

        $control = new WpAcfEditorControl(
            fieldGroupsProvider: static fn(): array => $fieldGroups
        );

        $this->assertTrue($control->updateCache());
    }

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
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                    ],
                ],
            ],
        ];

        $control = new WpAcfEditorControl();

        $this->assertTrue($control->updateCache());
        $this->assertFalse($control->disableForPostType(true, 'portfolio'));
        $this->assertFalse($control->disableForSpecificPost(true, (object) ['ID' => 11, 'post_type' => 'page']));
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
                        ['param' => 'post_type', 'operator' => '!=', 'value' => 'ignored_type'],
                        ['param' => 'page', 'operator' => '==', 'value' => '42'],
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'posts_page'],
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
        $this->assertSame([42, 43, 10, 11, 20, 21], $savedOptions[WpAcfEditorControl::OPTION_PAGE_IDS]);
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
                        ['param' => 'post_type', 'operator' => '==', 'value' => '   '],
                        ['param' => 'page', 'operator' => '==', 'value' => 0],
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'front_page'],
                        ['param' => 'page_type', 'operator' => '==', 'value' => 'posts_page'],
                        ['param' => 'invalid', 'operator' => '==', 'value' => []],
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
            optionGetter: static fn(string $name, mixed $default): mixed => ['portfolio', 'landing']
        );

        $this->assertFalse($control->disableForPostType(true, 'portfolio'));
        $this->assertTrue($control->disableForPostType(true, 'post'));
        $this->assertFalse($control->disableForPostType(false, 'post'));
    }

    public function testDisableForSpecificPostChecksPostTypeAndPageIds(): void
    {
        $options = [
            WpAcfEditorControl::OPTION_POST_TYPES => ['portfolio'],
            WpAcfEditorControl::OPTION_PAGE_IDS => [15, '25'],
        ];

        $control = new WpAcfEditorControl(
            optionGetter: static fn(string $name, mixed $default): mixed => $options[$name] ?? $default
        );

        $portfolioPost = (object) ['ID' => 99, 'post_type' => 'portfolio'];
        $hiddenPage = (object) ['ID' => 25, 'post_type' => 'page'];
        $normalPage = (object) ['ID' => 30, 'post_type' => 'page'];
        $invalidIdPost = (object) ['ID' => 0, 'post_type' => 'page'];

        $this->assertFalse($control->disableForSpecificPost(true, $portfolioPost));
        $this->assertFalse($control->disableForSpecificPost(true, $hiddenPage));
        $this->assertTrue($control->disableForSpecificPost(true, $normalPage));
        $this->assertTrue($control->disableForSpecificPost(true, $invalidIdPost));
        $this->assertTrue($control->disableForSpecificPost(true, 'not_an_object'));
    }

    public function testDisableHandlesNonArrayOptionGracefully(): void
    {
        $control = new WpAcfEditorControl(
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

        $this->assertCount(7, $actions);
        $this->assertSame('acf/update_field_group', $actions[0][0]);
        $this->assertSame('acf/trash_field_group', $actions[1][0]);
        $this->assertSame('acf/untrash_field_group', $actions[2][0]);
        $this->assertSame('acf/delete_field_group', $actions[3][0]);
        $this->assertSame('update_option_page_on_front', $actions[4][0]);
        $this->assertSame('update_option_page_for_posts', $actions[5][0]);
        $this->assertSame('after_switch_theme', $actions[6][0]);

        $this->assertCount(2, $filters);
        $this->assertSame('use_block_editor_for_post_type', $filters[0][0]);
        $this->assertSame('use_block_editor_for_post', $filters[1][0]);
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
