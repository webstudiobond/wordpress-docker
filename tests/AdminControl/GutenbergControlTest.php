<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Modules\GutenbergControl;

/**
 * Validates Gutenberg block filtering, panel resolution, asset registration, and template hardening.
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
final class GutenbergControlTest extends TestCase
{
    public function testGetBlocksToRemoveWithCustomResolver(): void
    {
        $control = new GutenbergControl(
            blocksResolver: static fn(?object $user): array => ['core/paragraph', 'core/heading']
        );

        $this->assertSame(['core/paragraph', 'core/heading'], $control->getBlocksToRemoveForUser());
    }

    public function testGetBlocksToRemoveWhenEnvNotSetOrEmpty(): void
    {
        $scenarios = [
            'false' => false,
            'empty' => '',
            'whitespace' => '   ',
        ];

        foreach ($scenarios as $val) {
            $control = new GutenbergControl(
                envGetter: static fn(string $name): string|false => $val
            );
            $this->assertSame([], $control->getBlocksToRemoveForUser());
        }
    }

    public function testGetBlocksToRemoveWhenParserOrMatcherMissing(): void
    {
        $controlNoParser = new GutenbergControl(
            envGetter: static fn(string $name): string => 'category:theme'
        );
        $this->assertSame([], $controlNoParser->getBlocksToRemoveForUser());

        $controlNoMatcher = new GutenbergControl(
            envGetter: static fn(string $name): string => 'category:theme',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['category:theme'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]]
        );
        $this->assertSame([], $controlNoMatcher->getBlocksToRemoveForUser());
    }

    public function testGetBlocksToRemoveWithCategoryAndAliasesAndWildcards(): void
    {
        $currentUser = (object) ['ID' => 10, 'user_login' => 'editor_user'];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => [
                    'category:theme',
                    'category:unknown_category',
                    'query',
                    'login/out',
                    'custom/block',
                    'core/paragraph',
                    'heading',
                    'vendor/*',
                    '   ',
                ],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ],
            [
                'targets' => ['core/image'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['administrator'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ],
        ];

        $control = new GutenbergControl(
            envGetter: static fn(string $name): string => 'sample-rule-string',
            currentUserGetter: static fn(): object => $currentUser,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static function (array $rule, ?object $user): bool {
                return $rule['isAll'];
            }
        );

        $removed = $control->getBlocksToRemoveForUser();

        $this->assertContains('category:theme', $removed);
        $this->assertContains('core/navigation', $removed);
        $this->assertContains('navigation', $removed);
        $this->assertContains('category:unknown_category', $removed);
        $this->assertContains('core/query', $removed);
        $this->assertContains('core/loginout', $removed);
        $this->assertContains('custom/block', $removed);
        $this->assertContains('core/paragraph', $removed);
        $this->assertContains('paragraph', $removed);
        $this->assertContains('heading', $removed);
        $this->assertContains('core/heading', $removed);
        $this->assertContains('vendor/*', $removed);

        $explicitUser = (object) ['ID' => 99];
        $removedForExplicit = $control->getBlocksToRemoveForUser($explicitUser);
        $this->assertSame($removed, $removedForExplicit);
    }

    public function testFilterAllowedBlockTypesWhenNoBlocksToRemove(): void
    {
        $control = new GutenbergControl(
            blocksResolver: static fn(?object $user): array => []
        );

        $this->assertTrue($control->filterAllowedBlockTypesAll(true));
        $this->assertFalse($control->filterAllowedBlockTypesAll(false));

        $list = ['core/paragraph', 999, 'core/heading'];
        $this->assertSame(['core/paragraph', 'core/heading'], $control->filterAllowedBlockTypesAll($list));
    }

    public function testFilterAllowedBlockTypesWhenInputIsFalse(): void
    {
        $control = new GutenbergControl(
            blocksResolver: static fn(?object $user): array => ['core/paragraph']
        );

        $this->assertFalse($control->filterAllowedBlockTypesAll(false));
    }

    public function testFilterAllowedBlockTypesWhenInputIsTrueAndRegistryEmpty(): void
    {
        $control = new GutenbergControl(
            blocksResolver: static fn(?object $user): array => ['core/paragraph'],
            registeredBlockTypesGetter: static fn(): array => []
        );

        $this->assertTrue($control->filterAllowedBlockTypesAll(true));
    }

    public function testFilterAllowedBlockTypesWhenInputIsTrueWithRegisteredBlocks(): void
    {
        $registered = ['core/paragraph', 'core/heading', 'core/image', 'core/quote'];
        $control = new GutenbergControl(
            blocksResolver: static fn(?object $user): array => ['core/heading'],
            registeredBlockTypesGetter: static fn(): array => $registered
        );

        $result = $control->filterAllowedBlockTypesAll(true);
        $this->assertSame(['core/paragraph', 'core/image', 'core/quote'], $result);
    }

    public function testFilterAllowedBlockTypesWithExactWildcardAndCategoryFiltering(): void
    {
        $toRemove = [
            'category:widgets',
            'vendor/*',
            'core/image',
        ];

        $inputBlocks = [
            'core/paragraph',
            'core/image',
            'vendor/slider',
            'vendor/banner',
            'core/calendar',
            'core/quote',
            123,
            null,
        ];

        $categories = [
            'core/calendar' => 'widgets',
            'core/paragraph' => 'text',
            'core/quote' => 'text',
        ];

        $control = new GutenbergControl(
            blocksResolver: static fn(?object $user): array => $toRemove,
            blockTypeCategoryGetter: static fn(string $name): ?string => $categories[$name] ?? null
        );

        $clean = $control->filterAllowedBlockTypesAll(
            allowedBlockTypes: $inputBlocks,
            blockEditorContext: null,
            blocksToRemove: $toRemove
        );

        $this->assertSame(['core/paragraph', 'core/quote'], $clean);
    }

    public function testResolveGutenbergPanelId(): void
    {
        $control = new GutenbergControl();

        $cases = [
            'empty' => ['', null],
            'whitespace' => ['   ', null],
            'tags' => ['tags', 'taxonomy-panel-post_tag'],
            'post_tag' => ['post_tag', 'taxonomy-panel-post_tag'],
            'tagsdiv-post_tag' => ['tagsdiv-post_tag', 'taxonomy-panel-post_tag'],
            'categories' => ['categories', 'taxonomy-panel-category'],
            'category' => ['category', 'taxonomy-panel-category'],
            'categorydiv' => ['categorydiv', 'taxonomy-panel-category'],
            'featured_image' => ['featured_image', 'featured-image'],
            'featured-image' => ['featured-image', 'featured-image'],
            'postimagediv' => ['postimagediv', 'featured-image'],
            'excerpt' => ['excerpt', 'post-excerpt'],
            'postexcerpt' => ['postexcerpt', 'post-excerpt'],
            'discussion' => ['discussion', 'discussion-panel'],
            'discussion-panel' => ['discussion-panel', 'discussion-panel'],
            'commentstatusdiv' => ['commentstatusdiv', 'discussion-panel'],
            'page_attributes' => ['page_attributes', 'page-attributes'],
            'page-attributes' => ['page-attributes', 'page-attributes'],
            'pageparentdiv' => ['pageparentdiv', 'page-attributes'],
            'template' => ['template', 'page-attributes'],
            'yoast' => ['yoast', 'yoast-seo'],
            'yoast-seo' => ['yoast-seo', 'yoast-seo'],
            'wpseo_meta' => ['wpseo_meta', 'yoast-seo'],
            'custom-prefix' => ['taxonomy-panel-genre', 'taxonomy-panel-genre'],
            'unsupported' => ['custom_box', null],
        ];

        foreach ($cases as [$target, $expected]) {
            $this->assertSame($expected, $control->resolveGutenbergPanelId($target));
        }
    }

    public function testGetPanelsToHideWithEmptyOrPopulatedTargets(): void
    {
        $controlEmpty = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => []
        );
        $this->assertSame([], $controlEmpty->getPanelsToHide());

        $controlPopulated = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => [
                'tags',
                'featured_image',
                'unknown_box',
                'tags',
            ]
        );
        $this->assertSame(['taxonomy-panel-post_tag', 'featured-image'], $controlPopulated->getPanelsToHide());

        $explicit = ['excerpt', 'discussion'];
        $this->assertSame(['post-excerpt', 'discussion-panel'], $controlEmpty->getPanelsToHide($explicit));
    }

    public function testHandleBlockEditorAssetsWithPanelsAndEmpty(): void
    {
        $added = [];
        $control = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => ['tags', 'featured_image'],
            inlineScriptAdder: static function (string $handle, string $data, string $position) use (&$added): bool {
                $added[] = ['handle' => $handle, 'data' => $data, 'position' => $position];
                return true;
            }
        );

        $control->handleBlockEditorAssets();
        $this->assertCount(1, $added);
        $this->assertSame('wp-edit-post', $added[0]['handle']);
        $this->assertStringContainsString('taxonomy-panel-post_tag', $added[0]['data']);
        $this->assertStringContainsString('featured-image', $added[0]['data']);

        $controlEmpty = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => []
        );
        $controlEmpty->handleBlockEditorAssets();

        $malformedPanels = ["\xB1\x31"];
        $control->handleBlockEditorAssets($malformedPanels);
    }

    public function testHandleAdminPrintFooterScriptsWithPanelsAndEmpty(): void
    {
        $echoed = '';
        $control = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => ['excerpt'],
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed .= $html;
            }
        );

        $control->handleAdminPrintFooterScripts();
        $this->assertStringContainsString('id="wp-admin-control-gutenberg-panels"', $echoed);
        $this->assertStringContainsString('post-excerpt', $echoed);

        $echoedEmpty = '';
        $controlEmpty = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => [],
            htmlEchoer: static function (string $html) use (&$echoedEmpty): void {
                $echoedEmpty .= $html;
            }
        );
        $controlEmpty->handleAdminPrintFooterScripts();
        $this->assertSame('', $echoedEmpty);

        $malformedPanels = ["\xB1\x31"];
        $control->handleAdminPrintFooterScripts($malformedPanels);
    }

    public function testFilterBlockEditorSettingsAll(): void
    {
        $control = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => ['template']
        );

        $settings = ['supportsTemplateMode' => true, 'title' => 'Sample'];
        $filtered = $control->filterBlockEditorSettingsAll($settings);
        $this->assertFalse($filtered['supportsTemplateMode']);

        $controlEmpty = new GutenbergControl(
            hiddenTargetsGetter: static fn(string $envKey): array => []
        );
        $this->assertSame($settings, $controlEmpty->filterBlockEditorSettingsAll($settings));

        $otherTargets = ['tags', 'featured_image'];
        $unaffected = $controlEmpty->filterBlockEditorSettingsAll($settings, null, $otherTargets);
        $this->assertTrue($unaffected['supportsTemplateMode']);

        $templateAliases = [
            ['page_attributes'],
            ['page-attributes'],
            ['pageparentdiv'],
        ];
        foreach ($templateAliases as $aliasList) {
            $aliasFiltered = $controlEmpty->filterBlockEditorSettingsAll($settings, null, $aliasList);
            $this->assertFalse($aliasFiltered['supportsTemplateMode']);
        }
    }

    public function testFallbackMethodsWhenDependenciesNotProvided(): void
    {
        $control = new GutenbergControl(
            envGetter: static fn(string $name): string => 'category:theme',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['category:theme'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static function (array $rule, ?object $user): bool {
                return $user === null;
            }
        );

        $this->assertNotEmpty($control->getBlocksToRemoveForUser());

        $defaultControl = new GutenbergControl();
        $this->assertSame([], $defaultControl->getBlocksToRemoveForUser());
        $this->assertSame([], $defaultControl->getPanelsToHide());
    }

    public function testDefaultFallbacksForRegistryScriptAndHtml(): void
    {
        $control = new GutenbergControl();

        $this->assertSame([], $control->getRegisteredBlockTypes());

        $control->handleBlockEditorAssets(['featured-image']);

        \ob_start();
        $control->handleAdminPrintFooterScripts(['post-excerpt']);
        $output = \ob_get_clean();
        $this->assertIsString($output);
        $this->assertStringContainsString('post-excerpt', $output);

        $clean = $control->filterAllowedBlockTypesAll(
            allowedBlockTypes: ['core/paragraph'],
            blockEditorContext: null,
            blocksToRemove: ['category:widgets']
        );
        $this->assertSame(['core/paragraph'], $clean);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRegistryAndInlineScriptIntegrationWithStubs(): void
    {
        if (!\function_exists('wp_add_inline_script')) {
            require_once __DIR__ . '/WP_Block_Type_Registry_Stub.php';
        }

        $control = new GutenbergControl();

        $registered = $control->getRegisteredBlockTypes();
        $this->assertSame(['core/paragraph', 'core/heading'], $registered);

        $filtered = $control->filterAllowedBlockTypesAll(
            allowedBlockTypes: ['core/paragraph', 'core/heading'],
            blockEditorContext: null,
            blocksToRemove: ['category:text']
        );
        $this->assertSame(['core/paragraph'], $filtered);

        $control->handleBlockEditorAssets(['featured-image']);
    }
}
