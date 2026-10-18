<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use stdClass;
use WordPressDocker\AdminControl\Config\SelectorsMap;
use WordPressDocker\Tests\WpCoreCleanupTest;
use WordPressDocker\AdminControl\Dom\ProfileDomCleaner;
use WordPressDocker\AdminControl\Modules\ContentAccessControl;
use WordPressDocker\AdminControl\Modules\DashboardControl;
use WordPressDocker\AdminControl\Modules\GutenbergControl;
use WordPressDocker\AdminControl\Modules\MenuControl;
use WordPressDocker\AdminControl\Modules\SidebarWidgetsControl;
use WordPressDocker\AdminControl\Modules\TinyMceControl;
use WordPressDocker\AdminControl\Modules\TopbarControl;
use WordPressDocker\AdminControl\Modules\UiSanitizer;
use WordPressDocker\AdminControl\Plugin;
use WordPressDocker\AdminControl\Security\ProfilePostGuard;
use WordPressDocker\AdminControl\Security\RuleMatcher;
use WordPressDocker\AdminControl\Security\SuperAdminCloaking;
use WP_Error;

final class PluginTest extends TestCase
{
    public function testConstantsExposedMatchSources(): void
    {
        $this->assertSame(
            RuleMatcher::DEFAULT_SUPER_ADMIN_SECRETS_PATH,
            Plugin::DEFAULT_SUPER_ADMIN_SECRETS_PATH
        );
        $this->assertSame(
            RuleMatcher::DEFAULT_SALT_SECRETS_PATH,
            Plugin::DEFAULT_SALT_SECRETS_PATH
        );
        $this->assertSame(
            SelectorsMap::PROFILE_FIELD_SELECTORS_MAP,
            Plugin::PROFILE_FIELD_SELECTORS_MAP
        );
        $this->assertSame(
            SidebarWidgetsControl::STANDARD_WIDGETS_MAP,
            Plugin::STANDARD_WIDGETS_MAP
        );
        $this->assertSame(
            GutenbergControl::STANDARD_BLOCK_ALIASES,
            Plugin::STANDARD_GUTENBERG_BLOCK_ALIASES
        );
        $this->assertSame(
            GutenbergControl::STANDARD_CATEGORIES,
            Plugin::STANDARD_GUTENBERG_CATEGORIES
        );
    }

    public function testDefaultInstantiationWithoutMocks(): void
    {
        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertFalse($plugin->isSuperAdmin(null));
        $this->assertSame([], $plugin->resolveSuperAdminIds());
        $this->assertSame([], $plugin->parseRules(''));
        $this->assertSame([], $plugin->getRegisteredBlockTypes());
    }

    public function testRegisterHooksRegistersExpectedActionsAndFilters(): void
    {
        /** @var list<array{tag: string, priority: int, args: int}> $registeredActions */
        $registeredActions = [];
        /** @var list<array{tag: string, priority: int, args: int}> $registeredFilters */
        $registeredFilters = [];

        $actionAdder = static function (string $tag, mixed $cb, int $prio, int $args) use (&$registeredActions): void {
            $registeredActions[] = ['tag' => $tag, 'priority' => $prio, 'args' => $args];
        };
        $filterAdder = static function (string $tag, mixed $cb, int $prio, int $args) use (&$registeredFilters): void {
            $registeredFilters[] = ['tag' => $tag, 'priority' => $prio, 'args' => $args];
        };

        $plugin = new Plugin(
            actionAdder: $actionAdder,
            filterAdder: $filterAdder,
        );
        $plugin->registerHooks();

        $actionTags = array_column($registeredActions, 'tag');
        $filterTags = array_column($registeredFilters, 'tag');

        $this->assertContains('admin_init', $actionTags);
        $this->assertContains('admin_menu', $actionTags);
        $this->assertContains('admin_bar_menu', $actionTags);
        $this->assertContains('wp_before_admin_bar_render', $actionTags);
        $this->assertContains('wp_dashboard_setup', $actionTags);
        $this->assertContains('admin_head', $actionTags);
        $this->assertContains('current_screen', $actionTags);
        $this->assertContains('add_meta_boxes', $actionTags);
        $this->assertContains('enqueue_block_editor_assets', $actionTags);
        $this->assertContains('rest_api_init', $actionTags);
        $this->assertContains('template_redirect', $actionTags);
        $this->assertContains('widgets_init', $actionTags);

        $this->assertContains('pre_get_users', $filterTags);
        $this->assertContains('views_users', $filterTags);
        $this->assertContains('user_row_actions', $filterTags);
        $this->assertContains('bulk_actions-users', $filterTags);
        $this->assertContains('manage_users_custom_column', $filterTags);
        $this->assertContains('rest_user_query', $filterTags);
        $this->assertContains('rest_pre_dispatch', $filterTags);
        $this->assertContains('rest_post_query', $filterTags);
        $this->assertContains('rest_page_query', $filterTags);
        $this->assertContains('rest_prepare_post', $filterTags);
        $this->assertContains('rest_comment_query', $filterTags);
        $this->assertContains('rest_prepare_comment', $filterTags);
        $this->assertContains('custom_menu_order', $filterTags);
        $this->assertContains('menu_order', $filterTags);
        $this->assertContains('screen_options_show_screen', $filterTags);
        $this->assertContains('hidden_meta_boxes', $filterTags);
        $this->assertContains('hidden_columns', $filterTags);
        $this->assertContains('default_hidden_columns', $filterTags);
        $this->assertContains('manage_posts_columns', $filterTags);
        $this->assertContains('manage_pages_columns', $filterTags);
        $this->assertContains('manage_users_columns', $filterTags);
        $this->assertContains('map_meta_cap', $filterTags);
        $this->assertContains('pre_get_posts', $filterTags);
        $this->assertContains('wp_count_posts', $filterTags);
        $this->assertContains('views_edit-post', $filterTags);
        $this->assertContains('views_edit-page', $filterTags);
        $this->assertContains('author_link', $filterTags);
        $this->assertContains('wp_die_handler', $filterTags);
        $this->assertContains('admin_footer_text', $filterTags);
        $this->assertContains('update_footer', $filterTags);
        $this->assertContains('block_editor_settings_all', $filterTags);
        $this->assertContains('wp_insert_post_data', $filterTags);
        $this->assertContains('allowed_block_types_all', $filterTags);
        $this->assertContains('user_contactmethods', $filterTags);
        $this->assertContains('show_admin_bar', $filterTags);
        $this->assertContains('mce_buttons', $filterTags);
        $this->assertContains('rest_pre_insert_user', $filterTags);
    }

    public function testRegisterHooksIncludesGoogleFontsWhenDisabled(): void
    {
        /** @var list<string> $registeredActions */
        $registeredActions = [];
        /** @var list<string> $registeredFilters */
        $registeredFilters = [];

        $env = ['WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS' => 'true'];

        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            actionAdder: static function (string $tag) use (&$registeredActions): void {
                $registeredActions[] = $tag;
            },
            filterAdder: static function (string $tag) use (&$registeredFilters): void {
                $registeredFilters[] = $tag;
            },
        );
        $plugin->registerHooks();

        $this->assertContains('admin_print_styles', $registeredActions);
        $this->assertContains('wp_resource_hints', $registeredFilters);
    }

    public function testDelegatesMenuAndTopbarOperations(): void
    {
        /** @var list<string> $removedMenus */
        $removedMenus = [];
        /** @var list<array{0: string, 1: string}> $removedSubmenus */
        $removedSubmenus = [];

        $plugin = new Plugin(
            menuRemover: static function (string $s) use (&$removedMenus): bool {
                $removedMenus[] = $s;
                return true;
            },
            submenuRemover: static function (string $m, string $s) use (&$removedSubmenus): array {
                $removedSubmenus[] = [$m, $s];
                return [];
            },
        );

        $plugin->removeMenuPage('options-general.php');
        $this->assertSame(['options-general.php'], $removedMenus);

        $plugin->removeSubmenuPage('users.php', 'user-new.php');
        $this->assertSame([['users.php', 'user-new.php']], $removedSubmenus);

        $this->assertFalse($plugin->filterCustomMenuOrder(false));
        $this->assertSame(['index.php'], $plugin->filterMenuOrder(['index.php']));
    }

    public function testDelegatesGutenbergAndTinyMce(): void
    {
        $plugin = new Plugin(
            registeredBlockTypesGetter: static fn(): array => ['core/paragraph', 'core/image'],
        );

        $this->assertSame(['core/paragraph', 'core/image'], $plugin->getRegisteredBlockTypes());
        $this->assertSame(['bold', 'italic'], $plugin->filterMceButtons(['bold', 'italic']));
        $this->assertSame(['core/paragraph'], $plugin->filterAllowedBlockTypesAll(['core/paragraph']));
        $this->assertSame(['title' => 'Page'], $plugin->filterBlockEditorSettingsAll(['title' => 'Page']));
    }

    public function testDelegatesSuperAdminCloakingAndUserRows(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superboss'];
        $editor = (object) ['ID' => 2, 'user_login' => 'editor1'];

        $salt = 'test_salt_12345';
        $hash = hash_hmac('sha512', '1:superboss', $salt);

        $plugin = new Plugin(
            fileReader: static fn(string $path): string|false => match ($path) {
                RuleMatcher::DEFAULT_SALT_SECRETS_PATH => $salt,
                RuleMatcher::DEFAULT_SUPER_ADMIN_SECRETS_PATH => $hash,
                default => false,
            },
            currentUserGetter: static fn(): object => $editor,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : $editor,
            usersGetter: static fn(array $args): array => [$superAdmin],
        );

        $this->assertTrue($plugin->isSuperAdmin($superAdmin));
        $this->assertFalse($plugin->isSuperAdmin($editor));
        $this->assertSame([1], $plugin->resolveSuperAdminIds());

        $views = ['all' => '<span class="count">(10)</span>', 'administrator' => '<span class="count">(2)</span>'];
        $adjusted = $plugin->filterViewsUsers($views);
        $this->assertStringContainsString('(9)', (string) ($adjusted['all'] ?? ''));
        $this->assertStringContainsString('(1)', (string) ($adjusted['administrator'] ?? ''));

        $this->assertSame([], $plugin->filterUserRowActions(['edit' => 'Edit'], $superAdmin));
        $this->assertSame(['edit' => 'Edit'], $plugin->filterUserRowActions(['edit' => 'Edit'], $editor));
    }

    public function testDelegatesContentAccessAndAuthorLinks(): void
    {
        $env = ['WP_ADMIN_CONTENT_ACCESS_RULES' => '10,20|!superadmins'];
        $editor = (object) ['ID' => 2, 'user_login' => 'editor1'];

        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $editor,
        );

        $this->assertTrue($plugin->isPostRestrictedForCurrentUser(10));
        $this->assertFalse($plugin->isPostRestrictedForCurrentUser(30));

        $authorLink = $plugin->filterAuthorLink('https://example.com/author/superboss', 1);
        $this->assertSame('https://example.com/author/superboss', $authorLink);
    }

    public function testDelegatesUiSanitizerColumnsAndFooters(): void
    {
        $env = [
            'WP_ADMIN_HIDDEN_COLUMNS' => 'author,tags|all',
            'WP_ADMIN_FOOTER_LEFT' => 'Custom Left Footer|all',
            'WP_ADMIN_FOOTER_RIGHT' => 'v9.9.9|all',
        ];

        $user = (object) ['ID' => 2, 'user_login' => 'editor1'];

        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $user,
        );

        $columns = ['cb' => '', 'title' => 'Title', 'author' => 'Author', 'tags' => 'Tags'];
        $filteredColumns = $plugin->filterScreenColumns($columns);
        $this->assertArrayNotHasKey('author', $filteredColumns);
        $this->assertArrayNotHasKey('tags', $filteredColumns);
        $this->assertArrayHasKey('title', $filteredColumns);

        $this->assertSame('Custom Left Footer', $plugin->filterAdminFooterText('Default'));
        $this->assertSame('v9.9.9', $plugin->filterUpdateFooter('Default'));
    }

    public function testRestPreDispatchBlocksSuperAdminRequests(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superboss'];
        $editor = (object) ['ID' => 2, 'user_login' => 'editor1'];

        $salt = 'salt_abc';
        $hash = hash_hmac('sha512', '1:superboss', $salt);

        $plugin = new Plugin(
            fileReader: static fn(string $path): string|false => match ($path) {
                RuleMatcher::DEFAULT_SALT_SECRETS_PATH => $salt,
                RuleMatcher::DEFAULT_SUPER_ADMIN_SECRETS_PATH => $hash,
                default => false,
            },
            currentUserGetter: static fn(): object => $editor,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : null,
        );

        $request = new class {
            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_route') {
                    return '/wp/v2/users/1';
                }

                return null;
            }
        };

        $result = $plugin->filterRestPreDispatch(null, new stdClass(), $request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_user_invalid_id', $result->get_error_code());
    }

    public function testHandleCurrentScreenRegistersViewsFilterForCustomPostType(): void
    {
        /** @var list<string> $registeredFilters */
        $registeredFilters = [];

        $filterAdder = static function (string $tag) use (&$registeredFilters): void {
            $registeredFilters[] = $tag;
        };

        $plugin = new Plugin(
            filterAdder: $filterAdder,
        );

        $plugin->handleCurrentScreen((object) ['id' => 'edit-book']);
        $plugin->handleCurrentScreen((object) ['id' => 'edit-movie', 'taxonomy' => '']);
        $plugin->handleCurrentScreen((object) ['id' => 'edit-post']);
        $plugin->handleCurrentScreen((object) ['id' => 'edit-page']);
        $plugin->handleCurrentScreen((object) ['id' => 'edit-category', 'taxonomy' => 'category']);
        $plugin->handleCurrentScreen((object) ['id' => 'tools']);
        $plugin->handleCurrentScreen((object) []);

        $this->assertContains('views_edit-book', $registeredFilters);
        $this->assertContains('views_edit-movie', $registeredFilters);
        $this->assertNotContains('views_edit-post', $registeredFilters);
        $this->assertNotContains('views_edit-page', $registeredFilters);
        $this->assertNotContains('views_edit-category', $registeredFilters);
    }

    public function testModuleGettersAndProxyMethods(): void
    {
        $plugin = new Plugin();

        $this->assertInstanceOf(RuleMatcher::class, $plugin->getRuleMatcher());
        $this->assertInstanceOf(ProfileDomCleaner::class, $plugin->getProfileDomCleaner());
        $this->assertInstanceOf(ProfilePostGuard::class, $plugin->getProfilePostGuard());
        $this->assertInstanceOf(GutenbergControl::class, $plugin->getGutenbergControl());
        $this->assertInstanceOf(TinyMceControl::class, $plugin->getTinyMceControl());
        $this->assertInstanceOf(SidebarWidgetsControl::class, $plugin->getSidebarWidgetsControl());
        $this->assertInstanceOf(DashboardControl::class, $plugin->getDashboardControl());
        $this->assertInstanceOf(MenuControl::class, $plugin->getMenuControl());
        $this->assertInstanceOf(TopbarControl::class, $plugin->getTopbarControl());
        $this->assertInstanceOf(SuperAdminCloaking::class, $plugin->getSuperAdminCloaking());
        $this->assertInstanceOf(ContentAccessControl::class, $plugin->getContentAccessControl());
        $this->assertInstanceOf(UiSanitizer::class, $plugin->getUiSanitizer());

        $plugin->handleRestApiInit();
        $this->assertSame([], $plugin->getProfileFieldsToRemoveForUser());
        $this->assertFalse($plugin->isGoogleFontsDisabled());

        $plugin->handleCurrentScreen(null);
        $plugin->handleCurrentScreen('not-object');
    }

    public function testFilterMapMetaCap(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superboss'];
        $editor = (object) ['ID' => 2, 'user_login' => 'editor1'];
        $salt = 'salt_metacap';
        $hash = hash_hmac('sha512', '1:superboss', $salt);

        $plugin = new Plugin(
            fileReader: static fn(string $path): string|false => match ($path) {
                RuleMatcher::DEFAULT_SALT_SECRETS_PATH => $salt,
                RuleMatcher::DEFAULT_SUPER_ADMIN_SECRETS_PATH => $hash,
                default => false,
            },
            currentUserGetter: static fn(): object => $editor,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : $editor,
        );

        $blocked = $plugin->filterMapMetaCap(['edit_users'], 'edit_user', 2, [1]);
        $this->assertContains('do_not_allow', $blocked);

        $allowed = $plugin->filterMapMetaCap(['read'], 'read', 2, []);
        $this->assertSame(['read'], $allowed);
    }

    public function testHandleAdminInitEarlyReturns(): void
    {
        $loggedOut = new Plugin(
            userLoggedInChecker: static fn(): bool => false,
        );
        $loggedOut->handleAdminInit();

        $ajaxPlugin = new Plugin(
            userLoggedInChecker: static fn(): bool => true,
            ajaxChecker: static fn(): bool => true,
        );
        $ajaxPlugin->handleAdminInit();

        $cronPlugin = new Plugin(
            userLoggedInChecker: static fn(): bool => true,
            ajaxChecker: static fn(): bool => false,
            cronChecker: static fn(): bool => true,
        );
        $cronPlugin->handleAdminInit();

        $nonAdminPlugin = new Plugin(
            userLoggedInChecker: static fn(): bool => true,
            ajaxChecker: static fn(): bool => false,
            cronChecker: static fn(): bool => false,
            adminChecker: static fn(): bool => false,
        );
        $nonAdminPlugin->handleAdminInit();

        $this->assertFalse($nonAdminPlugin->isSuperAdmin(null));
    }

    public function testHandleAdminInitExecutionOnUsersAndProfilePages(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor1', 'roles' => ['editor']];
        $env = [
            'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'user-new.php|block:all',
            'WP_ADMIN_PROFILE_FIELDS_REMOVE' => 'syntax_highlighting,url|all',
            'WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP' => 'true',
        ];

        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $editor,
            adminChecker: static fn(): bool => true,
            userLoggedInChecker: static fn(): bool => true,
            ajaxChecker: static fn(): bool => false,
            cronChecker: static fn(): bool => false,
        );

        $GLOBALS['pagenow'] = 'users.php';
        $plugin->handleAdminInit();
        $this->assertTrue($plugin->getSuperAdminCloaking()->isBufferingStarted());

        $GLOBALS['pagenow'] = 'profile.php';
        $plugin->handleAdminInit();
        $this->assertTrue($plugin->getProfileDomCleaner()->isBufferingStarted());

        $GLOBALS['pagenow'] = 'index.php';
        $plugin->handleAdminInit();
        $this->assertTrue($plugin->getDashboardControl()->isBufferingStarted());

        while (\ob_get_level() > 1) {
            \ob_end_clean();
        }
    }

    public function testHandleAdminHeadRendersStylesAndEchoes(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superboss'];
        $editor = (object) ['ID' => 2, 'user_login' => 'editor1', 'roles' => ['editor']];
        $salt = 'salt_head';
        $hash = hash_hmac('sha512', '1:superboss', $salt);

        $env = [
            'WP_ADMIN_DASHBOARD_WIDGETS_REMOVE' => 'welcome-panel|all',
            'WP_ADMIN_PROFILE_FIELDS_REMOVE' => 'syntax_highlighting|all',
            'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'options-general.php|hide|all; user-new.php|block:all',
            'WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE' => 'all',
            'WP_ADMIN_TOPBAR_MENU_REMOVE' => 'wp-logo|all',
            'WP_ADMIN_HIDDEN_META_BOXES' => 'submitdiv|all',
        ];

        /** @var list<string> $echoedHtml */
        $echoedHtml = [];
        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            fileReader: static fn(string $path): string|false => match ($path) {
                RuleMatcher::DEFAULT_SALT_SECRETS_PATH => $salt,
                RuleMatcher::DEFAULT_SUPER_ADMIN_SECRETS_PATH => $hash,
                default => false,
            },
            currentUserGetter: static fn(): object => $editor,
            usersGetter: static fn(array $args): array => [$superAdmin],
            headerEchoer: static function (string $html) use (&$echoedHtml): void {
                $echoedHtml[] = $html;
            },
        );

        $GLOBALS['pagenow'] = 'profile.php';
        $plugin->handleAdminHead();

        $GLOBALS['pagenow'] = 'users.php';
        $plugin->handleAdminHead();

        $this->assertNotEmpty($echoedHtml);
        $combinedEchoed = \implode("\n", $echoedHtml);
        $this->assertStringContainsString('wp-admin-control-lock-profile', $combinedEchoed);
        $this->assertStringContainsString('wp-admin-control-topbar-custom-icon', $combinedEchoed);
        $this->assertStringContainsString('wp-admin-control-hide-topbar-nodes', $combinedEchoed);

        $plugin->getGutenbergControl()->handleAdminPrintFooterScripts(['taxonomy-panel-category']);
        $this->assertNotEmpty($echoedHtml);

        $defaultEchoPlugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $editor,
        );
        ob_start();
        $defaultEchoPlugin->getGutenbergControl()->handleAdminPrintFooterScripts(['taxonomy-panel-category']);
        $captured = ob_get_clean();
        $this->assertIsString($captured);
        $this->assertNotEmpty($captured);
    }

    public function testDieForbiddenAndDieInvalidUser(): void
    {
        /** @var list<array{message: string, code: int}> $died */
        $died = [];
        $dieHandler = static function (string $msg, int $code) use (&$died): void {
            $died[] = ['message' => $msg, 'code' => $code];
        };

        $env = [
            'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'options-general.php|block:all',
        ];
        $editor = (object) ['ID' => 2, 'user_login' => 'editor1', 'roles' => ['editor']];

        $pluginWithHandler = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $editor,
            dieHandler: $dieHandler,
        );

        $pluginWithHandler->getMenuControl()->enforceRouteRestrictions('options-general.php', $editor);
        $pluginWithHandler->getContentAccessControl()->dieForbidden('Content denied');
        $pluginWithHandler->getSuperAdminCloaking()->dieInvalidUser('Invalid user');

        $this->assertCount(3, $died);
        $this->assertSame(403, $died[0]['code']);
        $this->assertSame(403, $died[1]['code']);
        $this->assertSame(500, $died[2]['code']);

        $refForbidden = new \ReflectionMethod(Plugin::class, 'dieForbidden');
        $refInvalid = new \ReflectionMethod(Plugin::class, 'dieInvalidUser');

        $pluginDefault = new Plugin();
        $refForbidden->invoke($pluginDefault, 'Menu denied default');
        $refInvalid->invoke($pluginDefault, 'Invalid user default');
        $this->assertCount(3, $died);
    }

    public function testInjectedSubmoduleCallbacks(): void
    {
        /** @var list<array{0: string, 1: string, 2: string}> $inlineScripts */
        $inlineScripts = [];
        $plugin = new Plugin(
            inlineScriptAdder: static function (string $h, string $d, string $p) use (&$inlineScripts): bool {
                $inlineScripts[] = [$h, $d, $p];
                return true;
            },
            queriedObjectIdGetter: static fn(): int => 55,
            userMetaGetter: static fn(int $id, string $k, bool $s): mixed => 77,
            userByGetter: static fn(string $f, string|int $v): ?object => (object) ['ID' => 2],
            currentScreenGetter: static fn(): object => (object) ['id' => 'post', 'post_type' => 'post'],
            userPostsCountGetter: static fn(int $u, string $p): int => 12,
        );

        $plugin->getGutenbergControl()->handleBlockEditorAssets(['taxonomy-panel-category']);
        $this->assertCount(1, $inlineScripts);

        $plugin->getTopbarControl()->handleBeforeAdminBarRender();
        $GLOBALS['test_is_author'] = true;
        $plugin->getSuperAdminCloaking()->handleTemplateRedirect();

        $col = $plugin->getSuperAdminCloaking()->filterManageUsersCustomColumn('0', 'posts', 1);
        $this->assertIsString($col);

        $postData = $plugin->getContentAccessControl()->filterInsertPostData(
            ['post_author' => 2],
            ['ID' => 10, 'post_type' => 'post'],
            [],
            false
        );
        $this->assertIsArray($postData);

        $plugin->getUiSanitizer()->handleCurrentScreen((object) ['id' => 'edit-post']);

        $views = $plugin->getContentAccessControl()->filterViewsEditPosts(['mine' => '<span>(1)</span>']);
        $this->assertArrayHasKey('mine', $views);
    }

    public function testDefaultHelpersAndFallbacks(): void
    {
        $plugin = new Plugin(
            currentUserGetter: static fn(): ?object => null,
            adminChecker: static fn(): bool => false,
            ajaxChecker: static fn(): bool => false,
            cronChecker: static fn(): bool => false,
            userLoggedInChecker: static fn(): bool => false,
        );
        $plugin->handleAdminInit();

        $allowedPlugin = new Plugin(
            adminChecker: static fn(): bool => true,
            ajaxChecker: static fn(): bool => false,
            cronChecker: static fn(): bool => false,
            userLoggedInChecker: static fn(): bool => true,
            currentUserGetter: static fn(): object => (object) ['ID' => 10],
        );
        $allowedPlugin->handleAdminInit();

        $defaultPlugin = new Plugin();
        $defaultPlugin->handleAdminInit();
        $defaultPlugin->registerHooks();
        $this->assertFalse($defaultPlugin->isSuperAdmin(null));
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testGlobalWordPressFunctionFallbacks(): void
    {
        ensure_admin_control_stubs();

        $backupCurrentUser = $GLOBALS['test_current_user'] ?? null;
        $backupIsAdmin = $GLOBALS['test_is_admin'] ?? null;
        $backupIsLoggedIn = $GLOBALS['test_is_user_logged_in'] ?? null;
        $backupAjax = $GLOBALS['test_wp_doing_ajax'] ?? null;
        $backupCron = $GLOBALS['test_wp_doing_cron'] ?? null;
        $backupPagenow = $GLOBALS['pagenow'] ?? null;
        $backupDieCalls = $GLOBALS['test_wp_die_calls'] ?? [];
        $backupRegisteredActions = $GLOBALS['test_registered_actions'] ?? [];
        $backupRegisteredFilters = $GLOBALS['test_registered_filters'] ?? [];

        try {
            $GLOBALS['test_registered_actions'] = [];
            $GLOBALS['test_registered_filters'] = [];
            $GLOBALS['test_wp_die_calls'] = [];
            $GLOBALS['test_is_user_logged_in'] = true;
            $GLOBALS['test_wp_doing_ajax'] = false;
            $GLOBALS['test_wp_doing_cron'] = false;
            $GLOBALS['test_is_admin'] = true;
            $GLOBALS['test_current_user'] = (object) ['ID' => 1, 'roles' => ['administrator']];
            $GLOBALS['pagenow'] = 'index.php';

            $plugin = new Plugin();

            $plugin->registerHooks();
            $this->assertNotEmpty($GLOBALS['test_registered_actions']);
            $this->assertNotEmpty($GLOBALS['test_registered_filters']);

            $plugin->handleAdminInit();

            $dieForbidden = new \ReflectionMethod($plugin, 'dieForbidden');
            $dieForbidden->invoke($plugin, 'Forbidden text');

            $dieInvalidUser = new \ReflectionMethod($plugin, 'dieInvalidUser');
            $dieInvalidUser->invoke($plugin, 'Invalid user text');

            $this->assertCount(2, $GLOBALS['test_wp_die_calls']);
            $this->assertSame('Forbidden text', $GLOBALS['test_wp_die_calls'][0]['message']);
            $this->assertSame('Invalid user text', $GLOBALS['test_wp_die_calls'][1]['message']);
        } finally {
            $GLOBALS['test_current_user'] = $backupCurrentUser;
            $GLOBALS['test_is_admin'] = $backupIsAdmin;
            $GLOBALS['test_is_user_logged_in'] = $backupIsLoggedIn;
            $GLOBALS['test_wp_doing_ajax'] = $backupAjax;
            $GLOBALS['test_wp_doing_cron'] = $backupCron;
            $GLOBALS['pagenow'] = $backupPagenow;
            $GLOBALS['test_wp_die_calls'] = $backupDieCalls;
            $GLOBALS['test_registered_actions'] = $backupRegisteredActions;
            $GLOBALS['test_registered_filters'] = $backupRegisteredFilters;
        }
    }

    public function testDieFallbacksToUiSanitizerWhenWpDieUnavailable(): void
    {
        $echoed = '';
        $plugin = new Plugin(
            currentUserGetter: static fn(): mixed => false,
            headerEchoer: static function (string $html) use (&$echoed): void {
                $echoed .= $html;
            },
            terminator: static fn(): null => null
        );

        $reflectionCurrentUser = new \ReflectionMethod($plugin, 'getCurrentUser');
        $this->assertNull($reflectionCurrentUser->invoke($plugin));
    }

    public function testFilterRestPreInsertUserDelegatesToProfilePostGuard(): void
    {
        $user = (object) [
            'ID' => 15,
            'user_email' => 'safe15@example.org',
            'roles' => ['administrator'],
        ];
        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => $name === 'WP_ADMIN_PROFILE_FIELDS_REMOVE'
                ? 'email'
                : false,
            currentUserGetter: static fn(): object => $user,
        );

        $preparedUser = (object) [
            'ID' => 15,
            'user_email' => 'hacked@example.org',
        ];

        $result = $plugin->filterRestPreInsertUser($preparedUser, null);
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame('safe15@example.org', $result->user_email);
    }

    public function testGetUserActionsControlForCurrentUser(): void
    {
        $plugin = new Plugin(
            currentUserGetter: static fn(): ?object => null,
        );
        $this->assertSame(['hide' => [], 'block' => []], $plugin->getUserActionsControlForCurrentUser(null));

        $editor = (object) [
            'ID' => 20,
            'user_login' => 'editor_actions',
            'roles' => ['editor'],
        ];

        $emptyPlugin = new Plugin(
            envGetter: static fn(string $name): string|false => false,
            currentUserGetter: static fn(): object => $editor,
        );
        $this->assertSame(['hide' => [], 'block' => []], $emptyPlugin->getUserActionsControlForCurrentUser());

        $env = [
            'WP_ADMIN_USER_ACTIONS_CONTROL' =>
                'view|hide:all; edit,delete|block:role:editor; custom|hide:role:administrator',
        ];
        $activePlugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $editor,
        );

        $resultEditor = $activePlugin->getUserActionsControlForCurrentUser();
        $this->assertSame(['view', 'edit', 'delete'], $resultEditor['hide']);
        $this->assertSame(['edit', 'delete'], $resultEditor['block']);

        $admin = (object) [
            'ID' => 21,
            'user_login' => 'admin_actions',
            'roles' => ['administrator'],
        ];
        $resultAdmin = $activePlugin->getUserActionsControlForCurrentUser($admin);
        $this->assertSame(['view', 'custom'], $resultAdmin['hide']);
        $this->assertSame([], $resultAdmin['block']);

        $spacesPlugin = new Plugin(
            envGetter: static fn(string $name): string|false => '   ',
            currentUserGetter: static fn(): object => $editor,
        );
        $this->assertSame(['hide' => [], 'block' => []], $spacesPlugin->getUserActionsControlForCurrentUser());

        $emptyRulesPlugin = new Plugin(
            envGetter: static fn(string $name): string|false => '; ;',
            currentUserGetter: static fn(): object => $editor,
        );
        $this->assertSame(['hide' => [], 'block' => []], $emptyRulesPlugin->getUserActionsControlForCurrentUser());
    }

    public function testGetUserActionsControlWithShardedEnvVariables(): void
    {
        $user = (object) [
            'ID' => 22,
            'user_login' => 'shard_user',
            'roles' => ['editor'],
        ];

        \putenv('WP_ADMIN_USER_ACTIONS_CONTROL=');
        \putenv('WP_ADMIN_USER_ACTIONS_CONTROL_2=stats|hide:all');
        \putenv('WP_ADMIN_USER_ACTIONS_CONTROL_1=wfls|block:all');
        \putenv('WP_ADMIN_USER_ACTIONS_CONTROL_EMPTY=   ');

        try {
            $plugin = new Plugin(
                currentUserGetter: static fn(): object => $user,
            );

            $result = $plugin->getUserActionsControlForCurrentUser();
            $this->assertContains('wfls', $result['hide']);
            $this->assertContains('stats', $result['hide']);
            $this->assertSame(['wfls'], $result['block']);

            $envGetterFallbackPlugin = new Plugin(
                envGetter: static fn(string $name): string|false => false,
                currentUserGetter: static fn(): object => $user,
            );
            \putenv('WP_ADMIN_USER_ACTIONS_CONTROL=view|hide:all');
            $fallbackResult = $envGetterFallbackPlugin->getUserActionsControlForCurrentUser();
            $this->assertContains('view', $fallbackResult['hide']);
        } finally {
            \putenv('WP_ADMIN_USER_ACTIONS_CONTROL');
            \putenv('WP_ADMIN_USER_ACTIONS_CONTROL_2');
            \putenv('WP_ADMIN_USER_ACTIONS_CONTROL_1');
            \putenv('WP_ADMIN_USER_ACTIONS_CONTROL_EMPTY');
        }
    }

    public function testFilterUserRowActionsIntegration(): void
    {
        $editor = (object) [
            'ID' => 23,
            'user_login' => 'editor_row',
            'roles' => ['editor'],
        ];
        $otherUser = (object) [
            'ID' => 24,
            'user_login' => 'other_row',
        ];

        $env = [
            'WP_ADMIN_USER_ACTIONS_CONTROL' => 'view|hide:all; edit|block:role:editor',
        ];
        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $editor,
        );

        $actions = [
            'view' => '<a href="https://example.com/author/other_row/">View</a>',
            'edit' => '<a href="user-edit.php?user_id=24">Edit</a>',
            'custom' => '<a href="custom">Custom</a>',
        ];

        $filtered = $plugin->filterUserRowActions($actions, $otherUser);
        $this->assertSame(['custom' => '<a href="custom">Custom</a>'], $filtered);
    }

    public function testFilterUserRowActionsSuperAdminCosmeticHide(): void
    {
        $superAdmin = (object) [
            'ID' => 1,
            'user_login' => 'super_row_admin',
            'roles' => ['administrator'],
        ];
        $targetUser = (object) [
            'ID' => 24,
            'user_login' => 'target_row_user',
            'roles' => ['editor'],
        ];

        $salt = 'salt_super_row';
        $hash = hash_hmac('sha512', '1:super_row_admin', $salt);

        $env = [
            'WP_ADMIN_USER_ACTIONS_CONTROL_VIEW' => 'view|hide:superadmins|block:!superadmins',
        ];
        $plugin = new Plugin(
            fileReader: static fn(string $path): string|false => match ($path) {
                RuleMatcher::DEFAULT_SALT_SECRETS_PATH => $salt,
                RuleMatcher::DEFAULT_SUPER_ADMIN_SECRETS_PATH => $hash,
                default => false,
            },
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $superAdmin,
            allEnvGetter: static fn(): array => $env,
        );

        $actions = [
            'view' => '<a href="https://example.org/author/target_row_user/">View</a>',
            'edit' => '<a href="user-edit.php?user_id=24">Edit</a>',
            'delete' => '<a href="users.php?action=delete&amp;user=24">Delete</a>',
            'custom' => '<a href="admin.php?page=custom">Custom</a>',
        ];

        $filtered = $plugin->filterUserRowActions($actions, $targetUser);
        $this->assertSame(
            [
                'edit' => '<a href="user-edit.php?user_id=24">Edit</a>',
                'delete' => '<a href="users.php?action=delete&amp;user=24">Delete</a>',
                'custom' => '<a href="admin.php?page=custom">Custom</a>',
            ],
            $filtered
        );
    }

    public function testHandleAdminInitEnforcesUserActionProtectionWithForbiddenHandler(): void
    {
        $editor = (object) [
            'ID' => 30,
            'user_login' => 'editor_forbidden',
            'roles' => ['editor'],
        ];

        $diedMessage = '';
        $diedCode = 0;
        $plugin = new Plugin(
            envGetter: static fn(string $name): string|false => match ($name) {
                'WP_ADMIN_USER_ACTIONS_CONTROL' => 'edit|block:role:editor',
                default => false,
            },
            currentUserGetter: static fn(): object => $editor,
            adminChecker: static fn(): bool => true,
            userLoggedInChecker: static fn(): bool => true,
            ajaxChecker: static fn(): bool => false,
            cronChecker: static fn(): bool => false,
            dieHandler: static function (string $msg, int $code) use (&$diedMessage, &$diedCode): void {
                $diedMessage = $msg;
                $diedCode = $code;
            },
        );

        $backupPagenow = $GLOBALS['pagenow'] ?? '';
        try {
            $GLOBALS['pagenow'] = 'user-edit.php';
            $plugin->handleAdminInit();
            $this->assertSame(403, $diedCode);
            $this->assertSame('Sorry, you are not allowed to access this page.', $diedMessage);
        } finally {
            $GLOBALS['pagenow'] = $backupPagenow;
        }
    }
}
