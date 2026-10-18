<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Modules\MenuControl;
use WordPressDocker\Tests\WpCoreCleanupTest;

/**
 * Validates sidebar menu control, menu removal, separators, custom ordering,
 * direct route access enforcement, and sidebar CSS rendering.
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
final class MenuControlTest extends TestCase
{
    public function testGetSidebarMenuControlForCurrentUserNullUserOrEmptyRules(): void
    {
        $controlNullUser = new MenuControl(
            currentUserGetter: static fn(): null => null
        );
        $this->assertSame(['hide' => [], 'block' => []], $controlNullUser->getSidebarMenuControlForCurrentUser());

        $user = (object) ['ID' => 2, 'user_login' => 'editor_user'];
        $controlEmptyEnv = new MenuControl(
            envGetter: static fn(string $name): false => false,
            currentUserGetter: static fn(): object => $user
        );
        $this->assertSame(['hide' => [], 'block' => []], $controlEmptyEnv->getSidebarMenuControlForCurrentUser());
    }

    public function testGetSidebarMenuControlForCurrentUserEvaluationAndCaching(): void
    {
        $user = (object) ['ID' => 3, 'user_login' => 'author_user'];
        $parseCount = 0;

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => ['tools.php', 'options-general.php'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['author'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'block',
            ],
            [
                'targets' => ['plugins.php'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'block',
            ],
            [
                'targets' => ['edit-comments.php', 'tools.php'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['author'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ],
            [
                'targets' => ['themes.php'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ],
        ];

        $control = new MenuControl(
            envGetter: static fn(string $name): string => 'tools.php|block:author; edit-comments.php|hide:author',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw) use (&$parseCount, $rules): array {
                $parseCount++;
                return $rules;
            },
            userMatcher: static fn(array $rule, ?object $u): bool => \in_array('author', $rule['roles'], true),
            userRestrictedChecker: static fn(
                array $rule,
                ?object $u
            ): bool => \in_array('author', $rule['roles'], true),
        );

        $result1 = $control->getSidebarMenuControlForCurrentUser();
        $result2 = $control->getSidebarMenuControlForCurrentUser();

        $this->assertSame(1, $parseCount);
        $this->assertSame(['tools.php', 'options-general.php', 'edit-comments.php'], $result1['hide']);
        $this->assertSame(['tools.php', 'options-general.php'], $result1['block']);
        $this->assertSame($result1, $result2);
    }

    public function testGetSidebarMenuControlWithChunkedEnvVariables(): void
    {
        $user = (object) ['ID' => 5, 'user_login' => 'test_user'];
        $env = [
            'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'tools.php|block:all',
            'WP_ADMIN_SIDEBAR_MENU_CONTROL_1' => 'plugins.php|hide:all',
            'WP_ADMIN_SIDEBAR_MENU_CONTROL_2' => 'themes.php|block:all',
        ];

        $capturedRaw = '';
        $control = new MenuControl(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw) use (&$capturedRaw): array {
                $capturedRaw = $raw;
                return [
                    [
                        'targets' => ['tools.php', 'themes.php'],
                        'isAll' => true,
                        'superadmins' => false,
                        'invertedSuperadmins' => false,
                        'roles' => [],
                        'invertedRoles' => [],
                        'users' => [],
                        'invertedUsers' => [],
                        'action' => 'block',
                    ],
                    [
                        'targets' => ['plugins.php'],
                        'isAll' => true,
                        'superadmins' => false,
                        'invertedSuperadmins' => false,
                        'roles' => [],
                        'invertedRoles' => [],
                        'users' => [],
                        'invertedUsers' => [],
                        'action' => 'hide',
                    ],
                ];
            },
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            allEnvGetter: static fn(): array => $env
        );

        $result = $control->getSidebarMenuControlForCurrentUser();

        $this->assertStringContainsString('tools.php|block:all', $capturedRaw);
        $this->assertStringContainsString('plugins.php|hide:all', $capturedRaw);
        $this->assertStringContainsString('themes.php|block:all', $capturedRaw);
        $this->assertSame(['tools.php', 'themes.php', 'plugins.php'], $result['hide']);
        $this->assertSame(['tools.php', 'themes.php'], $result['block']);
    }

    public function testGetSidebarMenuControlWithNamedTopicSuffixes(): void
    {
        $user = (object) ['ID' => 6, 'user_login' => 'editor_user'];
        $env = [
            'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'index.php|hide:all',
            'WP_ADMIN_SIDEBAR_MENU_CONTROL_SECURITY' => 'wordfence|block:!superadmins',
            'WP_ADMIN_SIDEBAR_MENU_CONTROL_SEO' => 'wpseo_dashboard|block:!superadmins',
            'WP_ADMIN_SIDEBAR_MENU_CONTROL_LEPOPUP' => 'lepopup|block:!superadmins',
        ];

        $capturedRaw = '';
        $control = new MenuControl(
            envGetter: static fn(string $name): string|false => $env[$name] ?? false,
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw) use (&$capturedRaw): array {
                $capturedRaw = $raw;
                return [
                    [
                        'targets' => ['wordfence', 'wpseo_dashboard', 'lepopup'],
                        'isAll' => true,
                        'superadmins' => false,
                        'invertedSuperadmins' => false,
                        'roles' => [],
                        'invertedRoles' => [],
                        'users' => [],
                        'invertedUsers' => [],
                        'action' => 'block',
                    ],
                ];
            },
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            allEnvGetter: static fn(): array => $env
        );

        $result = $control->getSidebarMenuControlForCurrentUser();

        $this->assertStringContainsString('index.php|hide:all', $capturedRaw);
        $this->assertStringContainsString('wordfence|block:!superadmins', $capturedRaw);
        $this->assertStringContainsString('wpseo_dashboard|block:!superadmins', $capturedRaw);
        $this->assertStringContainsString('lepopup|block:!superadmins', $capturedRaw);
        $this->assertSame(['wordfence', 'wpseo_dashboard', 'lepopup'], $result['block']);
    }

    public function testGetSidebarMenuControlRawPartsFallbackAndPrecedence(): void
    {
        $user = (object) ['ID' => 7, 'user_login' => 'test_author'];

        $capturedRaw1 = '';
        $controlPrecedence = new MenuControl(
            envGetter: static fn(string $name): string|false => match ($name) {
                'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'tools.php|block:all',
                default => false,
            },
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw) use (&$capturedRaw1): array {
                $capturedRaw1 = $raw;
                return [];
            },
            allEnvGetter: static fn(): array => [
                'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'ignored.php|block:all',
                'WP_ADMIN_SIDEBAR_MENU_CONTROL_SHARD' => 'options.php|hide:all',
            ],
        );
        $controlPrecedence->getSidebarMenuControlForCurrentUser();
        $this->assertStringContainsString('tools.php|block:all', $capturedRaw1);
        $this->assertStringContainsString('options.php|hide:all', $capturedRaw1);
        $this->assertStringNotContainsString('ignored.php', $capturedRaw1);

        $capturedRaw2 = '';
        $controlFallback = new MenuControl(
            envGetter: static fn(string $name): string|false => false,
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw) use (&$capturedRaw2): array {
                $capturedRaw2 = $raw;
                return [];
            },
            allEnvGetter: static fn(): array => [
                'WP_ADMIN_SIDEBAR_MENU_CONTROL' => 'fallback.php|block:all',
                'WP_ADMIN_SIDEBAR_MENU_CONTROL_A' => 'alpha.php|hide:all',
            ],
        );
        $controlFallback->getSidebarMenuControlForCurrentUser();
        $this->assertStringContainsString('fallback.php|block:all', $capturedRaw2);
        $this->assertStringContainsString('alpha.php|hide:all', $capturedRaw2);

        $capturedRaw3 = '';
        $controlWhitespaceAndEmpty = new MenuControl(
            envGetter: static fn(string $name): string|false => '   ',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw) use (&$capturedRaw3): array {
                $capturedRaw3 = $raw;
                return [];
            },
            allEnvGetter: static fn(): array => [
                'WP_ADMIN_SIDEBAR_MENU_CONTROL_EMPTY' => '   ',
                'WP_ADMIN_SIDEBAR_MENU_CONTROL_VALID' => 'users.php|block:all',
            ],
        );
        $controlWhitespaceAndEmpty->getSidebarMenuControlForCurrentUser();
        $this->assertSame('users.php|block:all', $capturedRaw3);

        $capturedRaw4 = '';
        $controlEmptyEnv = new MenuControl(
            envGetter: static fn(string $name): string|false => 'edit.php|hide:all',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw) use (&$capturedRaw4): array {
                $capturedRaw4 = $raw;
                return [];
            },
            allEnvGetter: static fn(): array => [],
        );
        $controlEmptyEnv->getSidebarMenuControlForCurrentUser();
        $this->assertSame('edit.php|hide:all', $capturedRaw4);
    }

    public function testIsCurrentPluginPageRequestMatchingScenarios(): void
    {
        $control = new MenuControl();

        unset($_GET['page'], $_POST['page']);
        $this->assertFalse($control->isCurrentPluginPageRequest('tools.php:custom-tool'));

        $_GET['page'] = ' ';
        $this->assertFalse($control->isCurrentPluginPageRequest('tools.php:custom-tool'));

        $_GET['page'] = 'custom-tool';
        $this->assertTrue($control->isCurrentPluginPageRequest('tools.php:custom-tool'));
        $this->assertTrue($control->isCurrentPluginPageRequest('admin.php?page=custom-tool'));
        $this->assertTrue($control->isCurrentPluginPageRequest('custom-tool?tab=settings'));
        $this->assertFalse($control->isCurrentPluginPageRequest('other-tool'));
        $this->assertFalse($control->isCurrentPluginPageRequest(''));

        unset($_GET['page']);
        $_POST['page'] = 'custom-tool';
        $this->assertTrue($control->isCurrentPluginPageRequest('tools.php:custom-tool'));
        unset($_POST['page']);
    }

    public function testHandleAdminMenuRemovalAndPreservation(): void
    {
        $user = (object) ['ID' => 4, 'user_login' => 'test_user'];
        $removedSubmenuPages = [];
        $removedMenuPages = [];

        $_GET['page'] = 'allowed-plugin-page';

        $GLOBALS['submenu'] = [
            'tools.php' => [
                [0 => 'Tools', 1 => 'read', 2 => 'tools.php'],
                [0 => 'Custom Sub', 1 => 'read', 2 => 'custom_sub'],
                [0 => 'Page Sub', 1 => 'read', 2 => 'admin.php?page=custom_page_slug'],
                [0 => 'Explicit Page', 1 => 'read', 2 => 'admin.php?page=explicit_page'],
                [0 => 'Sub Addon', 1 => 'read', 2 => 'admin.php?page=sub_addon'],
                [0 => 'Book Sub', 1 => 'read', 2 => 'edit.php?post_type=cpt_book'],
            ],
            'options-general.php' => [
                [0 => 'General', 1 => 'manage_options', 2 => 'options-general.php'],
            ],
            'plugins.php' => [
                [0 => 'Installed', 1 => 'activate_plugins', 2 => 'plugins.php'],
                [0 => 'Active Plugin', 1 => 'read', 2 => 'allowed-plugin-page'],
            ],
            'preserved-parent.php' => [
                [0 => 'Sub 1', 1 => 'read', 2 => 'sub-1'],
                [0 => 'Remaining Sub', 1 => 'read', 2 => 'sub-remaining'],
            ],
        ];

        $GLOBALS['menu'] = [
            2 => [0 => 'Dashboard', 1 => 'read', 2 => 'index.php', 3 => '', 4 => 'menu-top', 5 => 'menu-dashboard'],
            10 => [0 => 'Tools', 1 => 'read', 2 => 'tools.php', 3 => '', 4 => 'menu-top', 5 => 'menu-tools'],
            20 => [
                0 => 'Preserved',
                1 => 'read',
                2 => 'preserved-parent.php',
                3 => '',
                4 => 'menu-top',
                5 => 'menu-preserved',
            ],
            30 => [
                0 => 'Custom Page',
                1 => 'read',
                2 => 'custom_page_slug',
                3 => '',
                4 => 'menu-top',
                5 => 'menu-custom',
            ],
            40 => [
                0 => 'Top Addon',
                1 => 'read',
                2 => 'admin.php?page=top_addon',
                3 => '',
                4 => 'menu-top',
                5 => 'menu-addon',
            ],
            50 => [
                0 => 'Books',
                1 => 'read',
                2 => 'edit.php?post_type=cpt_book',
                3 => '',
                4 => 'menu-top',
                5 => 'menu-books',
            ],
            60 => [
                0 => 'Genres',
                1 => 'read',
                2 => 'edit-tags.php?taxonomy=tax_genre',
                3 => '',
                4 => 'menu-top',
                5 => 'menu-genres',
            ],
        ];

        $control = new MenuControl(
            envGetter: static fn(string $name): string => 'dummy',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [
                [
                    'targets' => [
                        'tools.php:custom_sub',
                        'custom_page_slug',
                        'tools.php:explicit_page',
                        'allowed-plugin-page',
                        'preserved-parent.php:sub-1',
                        'preserved-parent.php',
                        'tools.php:admin.php?page=sub_addon',
                        'admin.php?page=top_addon',
                        'cpt_book',
                        'tax_genre',
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
            ],
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            menuRemover: static function (string $slug) use (&$removedMenuPages): void {
                $removedMenuPages[] = $slug;
            },
            submenuRemover: static function (string $m, string $s) use (&$removedSubmenuPages): void {
                $removedSubmenuPages[] = [$m, $s];
            }
        );

        $control->handleAdminMenu();

        $this->assertContains(['tools.php', 'custom_sub'], $removedSubmenuPages);
        $this->assertContains(['tools.php', 'admin.php?page=custom_page_slug'], $removedSubmenuPages);
        $this->assertContains(['tools.php', 'admin.php?page=explicit_page'], $removedSubmenuPages);
        $this->assertContains(['tools.php', 'admin.php?page=sub_addon'], $removedSubmenuPages);
        $this->assertContains(['tools.php', 'edit.php?post_type=cpt_book'], $removedSubmenuPages);
        $this->assertContains(['preserved-parent.php', 'sub-1'], $removedSubmenuPages);
        $this->assertNotContains(['plugins.php', 'allowed-plugin-page'], $removedSubmenuPages);

        $this->assertNotContains('preserved-parent.php', $removedMenuPages);
        $this->assertContains('custom_page_slug', $removedMenuPages);
        $this->assertContains('admin.php?page=top_addon', $removedMenuPages);
        $this->assertContains('edit.php?post_type=cpt_book', $removedMenuPages);
        $this->assertContains('edit-tags.php?taxonomy=tax_genre', $removedMenuPages);
    }

    public function testHandleSidebarSeparatorsAndFilterMenuOrder(): void
    {
        $GLOBALS['menu'] = [
            5 => ['', 'read', 'separator1', '', 'wp-menu-separator'],
            10 => ['Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts'],
            20 => ['Pages', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top', 'menu-pages'],
        ];

        $control = new MenuControl(
            envGetter: static function (string $name): string|false {
                if ($name === 'WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER') {
                    return 'edit.php, non_existent,   ';
                }
                return false;
            }
        );

        $this->assertTrue($control->filterCustomMenuOrder(false));

        $control->handleSidebarSeparators();

        $sepMap = $control->getSeparatorAfterMap();
        $this->assertArrayHasKey('edit.php', $sepMap);
        $sepSlug = $sepMap['edit.php'];
        $this->assertSame('separator-custom-1', $sepSlug);

        $initialOrder = ['index.php', 'edit.php', 'separator-custom-1', 'edit.php?post_type=page'];
        $reordered = $control->filterMenuOrder($initialOrder);
        $this->assertSame(['index.php', 'edit.php', 'separator-custom-1', 'edit.php?post_type=page'], $reordered);

        $misplacedOrder = ['index.php', 'separator-custom-1', 'edit.php', 'edit.php?post_type=page'];
        $fixedOrder = $control->filterMenuOrder($misplacedOrder);
        $this->assertSame(['index.php', 'edit.php', 'separator-custom-1', 'edit.php?post_type=page'], $fixedOrder);

        $missingSepOrder = ['index.php', 'edit.php', 'edit.php?post_type=page'];
        $insertedOrder = $control->filterMenuOrder($missingSepOrder);
        $this->assertSame(['index.php', 'edit.php', 'separator-custom-1', 'edit.php?post_type=page'], $insertedOrder);

        $unrelatedOrder = ['dashboard', 'users.php'];
        $this->assertSame($unrelatedOrder, $control->filterMenuOrder($unrelatedOrder));

        $controlNoSeps = new MenuControl(
            envGetter: static fn(string $name): false => false
        );
        $this->assertFalse($controlNoSeps->filterCustomMenuOrder(false));
        $this->assertSame(['test.php'], $controlNoSeps->filterMenuOrder(['test.php']));
    }

    public function testHandleSidebarMenuOrderSparseRepositioning(): void
    {
        $GLOBALS['menu'] = [
            10 => ['Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard'],
            20 => ['Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts'],
            30 => ['Media', 'upload_files', 'upload.php', '', 'menu-top', 'menu-media'],
            40 => ['Pages', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top', 'menu-pages'],
        ];

        $control = new MenuControl(
            envGetter: static function (string $name): string|false {
                if ($name === 'WP_ADMIN_SIDEBAR_MENU_ORDER') {
                    return 'edit.php:10, upload.php:10, invalid_item, :5, valid_hook:40';
                }
                return false;
            }
        );

        $control->handleSidebarMenuOrder();

        $menu = $GLOBALS['menu'];
        \assert(\is_array($menu));
        $firstItem = \reset($menu);
        $this->assertIsArray($firstItem);
        $this->assertSame('edit.php', $firstItem[2]);
    }

    public function testEnforceRouteRestrictionsBypassesSuperAdmin(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin'];
        $died = false;

        $control = new MenuControl(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            dieForbiddenHandler: static function (string $msg) use (&$died): void {
                $died = true;
            }
        );

        $control->enforceRouteRestrictions('options.php', $superAdmin);
        $this->assertFalse($died);
    }

    public function testEnforceRouteRestrictionsBlocksRestrictedRoutes(): void
    {
        $user = (object) ['ID' => 5, 'user_login' => 'editor_user'];
        $diedMessages = [];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => [
                    'options-discussion.php',
                    'options.php',
                    'tools.php:custom-tool',
                    'edit.php?post_type=page',
                    'plugins.php',
                ],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'block',
            ],
        ];

        $control = new MenuControl(
            envGetter: static fn(string $name): string => 'rule_data',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            superAdminChecker: static fn(?object $u): bool => false,
            dieForbiddenHandler: static function (string $msg) use (&$diedMessages): void {
                $diedMessages[] = $msg;
            }
        );

        $_POST['option_page'] = 'discussion';
        $control->enforceRouteRestrictions('options.php', $user);
        $this->assertCount(1, $diedMessages);

        unset($_POST['option_page'], $_GET['option_page']);
        $control->enforceRouteRestrictions('options.php', $user);
        $this->assertCount(2, $diedMessages);

        $control->enforceRouteRestrictions('plugins.php', $user);
        $this->assertCount(3, $diedMessages);

        $_GET['page'] = 'custom-tool';
        $control->enforceRouteRestrictions('admin.php', $user);
        $this->assertCount(4, $diedMessages);

        unset($_GET['page']);
        $_SERVER['QUERY_STRING'] = 'post_type=page';
        $control->enforceRouteRestrictions('edit.php', $user);
        $this->assertCount(5, $diedMessages);

        $_SERVER['QUERY_STRING'] = 'post_type=post';
        $control->enforceRouteRestrictions('edit.php', $user);
        $this->assertCount(5, $diedMessages);

        $control->enforceRouteRestrictions('profile.php', $user);
        $this->assertCount(5, $diedMessages);
    }

    public function testRenderSidebarMenuStylesRendersCss(): void
    {
        $user = (object) ['ID' => 6, 'user_login' => 'author_user'];
        $echoed = [];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => [
                    'admin.php?page=custom-plugin',
                    'edit.php?post_type=book',
                    'options-general.php',
                    'tools',
                    'shop_order',
                    'invalid#target!',
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
        ];

        $control = new MenuControl(
            envGetter: static fn(string $name): string => 'rule_data',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed[] = $html;
            }
        );

        $control->renderSidebarMenuStyles($user);

        $this->assertCount(1, $echoed);
        $this->assertStringContainsString('<style id="wp-admin-control-sidebar-menu">', $echoed[0]);
        $this->assertStringContainsString('page=custom-plugin', $echoed[0]);
        $this->assertStringContainsString('edit.php?post_type=book', $echoed[0]);
        $this->assertStringContainsString('options-general.php', $echoed[0]);
        $this->assertStringContainsString('tools', $echoed[0]);
        $this->assertStringContainsString('post_type=shop_order', $echoed[0]);
        $this->assertStringContainsString('taxonomy=shop_order', $echoed[0]);
    }

    public function testEnforceRouteRestrictionsOptionsPhpHppAndFallback(): void
    {
        $user = (object) ['ID' => 15, 'user_login' => 'editor_hpp'];
        $diedMessages = [];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => [
                    'options-discussion.php',
                    'options-general.php',
                    'options-reading.php',
                ],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'block',
            ],
        ];

        $control = new MenuControl(
            envGetter: static fn(string $name): string => 'rule_data',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            superAdminChecker: static fn(?object $u): bool => false,
            dieForbiddenHandler: static function (string $msg) use (&$diedMessages): void {
                $diedMessages[] = $msg;
            }
        );

        $backupPost = $_POST;
        $backupGet = $_GET;

        try {
            $directRules = [
                [
                    'targets' => ['options.php'],
                    'isAll' => true,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => [],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'block',
                ],
            ];
            $directControl = new MenuControl(
                envGetter: static fn(string $name): string => 'rule_data',
                currentUserGetter: static fn(): object => $user,
                ruleParser: static fn(string $raw): array => $directRules,
                userMatcher: static fn(array $r, ?object $u): bool => true,
                userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
                superAdminChecker: static fn(?object $u): bool => false,
                dieForbiddenHandler: static function (string $msg) use (&$diedMessages): void {
                    $diedMessages[] = $msg;
                }
            );
            $directControl->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(1, $diedMessages);

            $_POST = ['option_page' => '0'];
            $_GET = ['option_page' => 'general'];
            $control->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(2, $diedMessages);

            $_POST = ['option_page' => 'reading'];
            $_GET = ['option_page' => 'writing'];
            $control->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(3, $diedMessages);

            $_POST = ['option_page' => 'options'];
            $_GET = [];
            $control->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(4, $diedMessages);

            $noOptionsRules = [
                [
                    'targets' => ['plugins.php'],
                    'isAll' => true,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => [],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'block',
                ],
            ];
            $noOptionsControl = new MenuControl(
                envGetter: static fn(string $name): string => 'rule_data',
                currentUserGetter: static fn(): object => $user,
                ruleParser: static fn(string $raw): array => $noOptionsRules,
                userMatcher: static fn(array $r, ?object $u): bool => true,
                userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
                superAdminChecker: static fn(?object $u): bool => false,
                dieForbiddenHandler: static function (string $msg) use (&$diedMessages): void {
                    $diedMessages[] = $msg;
                }
            );
            $_POST = ['option_page' => 'options'];
            $noOptionsControl->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(4, $diedMessages);

            $_POST = ['option_page' => 'discussion'];
            $_GET = [];
            $control->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(5, $diedMessages);

            $_POST = [];
            $_GET = [];
            $control->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(6, $diedMessages);

            $_POST = [];
            $_GET = [];
            $noOptionsControl->enforceRouteRestrictions('options.php', $user);
            $this->assertCount(6, $diedMessages);
        } finally {
            $_POST = $backupPost;
            $_GET = $backupGet;
        }
    }

    public function testEnforceRouteRestrictionsPostParametersAndDefaultPostType(): void
    {
        $user = (object) ['ID' => 16, 'user_login' => 'editor_post_type'];
        $diedMessages = [];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => [
                    'edit.php?post_type=ticket',
                    'edit.php?post_type=post',
                    'post-new.php?post_type=post',
                    'admin.php?invalid_target?',
                    'edit.php?',
                    'edit.php?post_type[]=ticket',
                ],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'block',
            ],
        ];

        $control = new MenuControl(
            envGetter: static fn(string $name): string => 'rule_data',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            superAdminChecker: static fn(?object $u): bool => false,
            dieForbiddenHandler: static function (string $msg) use (&$diedMessages): void {
                $diedMessages[] = $msg;
            }
        );

        $backupServer = $_SERVER;
        $backupGet = $_GET;
        $backupPost = $_POST;

        try {
            $_SERVER['QUERY_STRING'] = '';
            $_GET = [];
            $_POST = ['post_type' => 'ticket'];
            $control->enforceRouteRestrictions('edit.php', $user);
            $this->assertCount(1, $diedMessages);

            $_SERVER['QUERY_STRING'] = 'post_type=ticket';
            $_GET = ['post_type' => 'ticket'];
            $_POST = [];
            $control->enforceRouteRestrictions('post-new.php', $user);
            $this->assertCount(1, $diedMessages);

            $_SERVER['QUERY_STRING'] = '';
            $_GET = [];
            $_POST = [];
            $control->enforceRouteRestrictions('edit.php', $user);
            $this->assertCount(2, $diedMessages);

            $control->enforceRouteRestrictions('post-new.php', $user);
            $this->assertCount(3, $diedMessages);

            $_SERVER['QUERY_STRING'] = 'invalid_target=1';
            $_GET = ['invalid_target' => '1'];
            $_POST = [];
            $control->enforceRouteRestrictions('admin.php', $user);
            $this->assertCount(3, $diedMessages);

            $_SERVER['QUERY_STRING'] = 'post_type=other';
            $_GET = ['post_type' => 'other'];
            $_POST = [];
            $control->enforceRouteRestrictions('edit.php', $user);
            $this->assertCount(3, $diedMessages);
        } finally {
            $_SERVER = $backupServer;
            $_GET = $backupGet;
            $_POST = $backupPost;
        }
    }

    public function testEnforceRouteRestrictionsBroadBareSlugsAndPluginDispatchers(): void
    {
        $user = (object) ['ID' => 17, 'user_login' => 'editor_bare'];
        $diedMessages = [];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => [
                    'admin.php?page=my-plugin',
                    'tools.php:admin.php?page=sub-plugin',
                    'shop_order',
                    'product_cat',
                    'themes',
                ],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'block',
            ],
        ];

        $control = new MenuControl(
            envGetter: static fn(string $name): string => 'rule_data',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static fn(array $r, ?object $u): bool => true,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            superAdminChecker: static fn(?object $u): bool => false,
            dieForbiddenHandler: static function (string $msg) use (&$diedMessages): void {
                $diedMessages[] = $msg;
            }
        );

        $backupServer = $_SERVER;
        $backupGet = $_GET;
        $backupPost = $_POST;

        try {
            $_SERVER['QUERY_STRING'] = 'page=my-plugin';
            $_GET = ['page' => 'my-plugin'];
            $_POST = [];
            $control->enforceRouteRestrictions('index.php', $user);
            $this->assertCount(1, $diedMessages);

            $_SERVER['QUERY_STRING'] = '';
            $_GET = [];
            $_POST = ['page' => 'sub-plugin'];
            $control->enforceRouteRestrictions('tools.php', $user);
            $this->assertCount(2, $diedMessages);

            $_SERVER['QUERY_STRING'] = 'post_type=shop_order';
            $_GET = ['post_type' => 'shop_order'];
            $_POST = [];
            $control->enforceRouteRestrictions('edit.php', $user);
            $this->assertCount(3, $diedMessages);

            $control->enforceRouteRestrictions('post-new.php', $user);
            $this->assertCount(4, $diedMessages);

            $_SERVER['QUERY_STRING'] = 'taxonomy=product_cat';
            $_GET = ['taxonomy' => 'product_cat'];
            $_POST = [];
            $control->enforceRouteRestrictions('edit-tags.php', $user);
            $this->assertCount(5, $diedMessages);

            $_SERVER['QUERY_STRING'] = '';
            $_GET = [];
            $_POST = [];
            $control->enforceRouteRestrictions('themes.php', $user);
            $this->assertCount(6, $diedMessages);
        } finally {
            $_SERVER = $backupServer;
            $_GET = $backupGet;
            $_POST = $backupPost;
        }
    }

    public function testRemoveMenuPageAndSubmenuPageDelegations(): void
    {
        $removedMenu = [];
        $removedSubmenu = [];

        $control = new MenuControl(
            menuRemover: static function (string $slug) use (&$removedMenu): void {
                $removedMenu[] = $slug;
            },
            submenuRemover: static function (string $m, string $s) use (&$removedSubmenu): void {
                $removedSubmenu[] = [$m, $s];
            }
        );

        $control->removeMenuPage('options-general.php');
        $this->assertSame(['options-general.php'], $removedMenu);

        $control->removeMenuPage('tools.php:custom_tool');
        $this->assertSame([['tools.php', 'custom_tool']], $removedSubmenu);

        $control->removeSubmenuPage('themes.php', 'widgets.php');
        $this->assertSame([['tools.php', 'custom_tool'], ['themes.php', 'widgets.php']], $removedSubmenu);
    }

    public function testDefaultFallbacksWithoutInjectedCallbacks(): void
    {
        \putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER=tools.php');
        \putenv('WP_ADMIN_SIDEBAR_MENU_ORDER=tools.php:15');

        try {
            $control = new MenuControl();
            $this->assertTrue($control->filterCustomMenuOrder(false));

            $GLOBALS['menu'] = [
                10 => ['Tools', 'manage_options', 'tools.php', '', 'menu-top', 'menu-tools'],
            ];

            $control->handleSidebarSeparators();
            $control->handleSidebarMenuOrder();

            $this->assertNotEmpty($control->getSeparatorAfterMap());
        } finally {
            \putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER');
            \putenv('WP_ADMIN_SIDEBAR_MENU_ORDER');
        }
    }

    public function testHandleSidebarMenuOrderEdgeCases(): void
    {
        $backupMenu = $GLOBALS['menu'] ?? null;
        try {
            $control = new MenuControl(
                envGetter: static fn(string $name): string => 'tools.php:15,options-general.php:20'
            );

            unset($GLOBALS['menu']);
            $control->handleSidebarMenuOrder();
            $this->assertFalse(isset($GLOBALS['menu']));

            $GLOBALS['menu'] = [
                5 => 'invalid_item',
                10 => ['Tools', 'manage_options', 'tools.php', '', 'menu-top', 'menu-tools'],
            ];
            $control->handleSidebarMenuOrder();
            $this->assertArrayHasKey(15, $GLOBALS['menu']);

            $controlNoMatch = new MenuControl(
                envGetter: static fn(string $name): string => 'nonexistent.php:25'
            );
            $GLOBALS['menu'] = [
                10 => ['Tools', 'manage_options', 'tools.php', '', 'menu-top', 'menu-tools'],
            ];
            $controlNoMatch->handleSidebarMenuOrder();
            $this->assertArrayHasKey(10, $GLOBALS['menu']);
        } finally {
            $GLOBALS['menu'] = $backupMenu;
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultFallbacksForCurrentUserSuperAdminDieForbiddenAndEchoHtml(): void
    {
        \ensure_duplicator_stubs();
        $control = new MenuControl();
        $this->assertSame(['hide' => [], 'block' => []], $control->getSidebarMenuControlForCurrentUser());

        $backupDieCalls = $GLOBALS['test_wp_die_calls'] ?? [];
        $backupScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $backupGet = $_GET;

        try {
            $GLOBALS['test_wp_die_calls'] = [];
            $_SERVER['SCRIPT_NAME'] = '/wp-admin/options-general.php';
            $_GET = [];

            $user = (object) ['ID' => 21, 'roles' => ['editor']];
            $dieControl = new MenuControl(
                envGetter: static fn(string $name): string => 'options-general.php|block:editor',
                currentUserGetter: static fn(): object => $user,
                ruleParser: static fn(string $raw): array => [[
                    'targets' => ['options-general.php'],
                    'isAll' => false,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => ['editor'],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'block',
                ]],
                userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
                superAdminChecker: null,
                dieForbiddenHandler: null
            );

            $dieControl->enforceRouteRestrictions('options-general.php', $user);
            $this->assertNotEmpty($GLOBALS['test_wp_die_calls']);
            $this->assertStringContainsString('Forbidden', (string) $GLOBALS['test_wp_die_calls'][0]['title']);

            $styleControl = new MenuControl(
                envGetter: static fn(string $name): string => 'options-general.php',
                currentUserGetter: static fn(): object => $user,
                ruleParser: static fn(string $raw): array => [[
                    'targets' => ['options-general.php'],
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
                userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
                htmlEchoer: null
            );

            \ob_start();
            $styleControl->renderSidebarMenuStyles($user);
            $output = \ob_get_clean();

            $this->assertIsString($output);
            $this->assertStringContainsString('<style id="wp-admin-control-sidebar-menu">', $output);
        } finally {
            $GLOBALS['test_wp_die_calls'] = $backupDieCalls;
            if ($backupScriptName !== null) {
                $_SERVER['SCRIPT_NAME'] = $backupScriptName;
            } else {
                unset($_SERVER['SCRIPT_NAME']);
            }
            $_GET = $backupGet;
        }
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testMenuControlEdgeCasesAndFallbacks(): void
    {
        ensure_admin_control_stubs();

        $defaultControl = new MenuControl();
        $defaultControl->removeMenuPage('test-menu.php');
        $defaultControl->removeSubmenuPage('test-menu.php', 'test-sub.php');
        $this->assertContains('test-menu.php', $GLOBALS['test_removed_menu_pages'] ?? []);

        $defaultControl->handleSidebarSeparators();
        $defaultControl->handleSidebarMenuOrder();

        $backupMenu = $GLOBALS['menu'] ?? null;
        try {
            $GLOBALS['menu'] = 'not-an-array';
            $sepControlNoArray = new MenuControl(
                envGetter: static fn(string $name): string => 'edit.php'
            );
            $sepControlNoArray->handleSidebarSeparators();
            $sepControlNoArray->handleSidebarMenuOrder();

            $GLOBALS['menu'] = [
                10 => ['Posts', 'edit_posts', 'edit.php'],
                '10.1' => ['', 'read', 'separator-custom-0', '', 'wp-menu-separator'],
                20 => 'not-array-item',
            ];
            $sepControlCollision = new MenuControl(
                envGetter: static fn(string $name): string => match ($name) {
                    'WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER' => 'edit.php, , non-existent',
                    default => '',
                }
            );
            $sepControlCollision->handleSidebarSeparators();
            $this->assertArrayHasKey('10.11', $GLOBALS['menu']);

            $emptyTokensControl = new MenuControl(
                envGetter: static fn(string $name): string => '  ,   '
            );
            $emptyTokensControl->handleSidebarSeparators();

            $GLOBALS['menu'] = [
                5 => ['Invalid', 'read'],
            ];
            $adminMenuControl = new MenuControl(
                envGetter: static fn(string $name): string => 'edit.php|hide:all',
                currentUserGetter: static fn(): object => (object) ['ID' => 1],
                ruleParser: static fn(string $r): array => [[
                    'targets' => ['edit.php'],
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
            $adminMenuControl->handleAdminMenu();

            $emptyBlockedControl = new MenuControl(
                currentUserGetter: static fn(): null => null
            );
            $emptyBlockedControl->enforceRouteRestrictions('index.php');
            $emptyBlockedControl->renderSidebarMenuStyles();

            $backupServer = $_SERVER;
            $backupGet = $_GET;
            unset($_SERVER['QUERY_STRING']);
            $_GET = ['page' => 'plugin-settings'];

            $queryBlockControl = new MenuControl(
                currentUserGetter: static fn(): object => (object) ['ID' => 2],
                envGetter: static fn(string $n): string => (
                    'admin.php?|block:all; admin.php?page=plugin-settings|block:all'
                ),
                ruleParser: static fn(string $r): array => [
                    [
                        'targets' => ['admin.php?'],
                        'isAll' => true,
                        'superadmins' => false,
                        'invertedSuperadmins' => false,
                        'roles' => [],
                        'invertedRoles' => [],
                        'users' => [],
                        'invertedUsers' => [],
                        'action' => 'block',
                    ],
                    [
                        'targets' => ['admin.php?page=plugin-settings'],
                        'isAll' => true,
                        'superadmins' => false,
                        'invertedSuperadmins' => false,
                        'roles' => [],
                        'invertedRoles' => [],
                        'users' => [],
                        'invertedUsers' => [],
                        'action' => 'block',
                    ],
                ],
                userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
                dieForbiddenHandler: static function (string $msg): void {
                    throw new \RuntimeException($msg);
                }
            );

            try {
                $queryBlockControl->enforceRouteRestrictions('admin.php');
                $this->fail('Expected forbidden exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            } finally {
                $_SERVER = $backupServer;
                $_GET = $backupGet;
            }
        } finally {
            $GLOBALS['menu'] = $backupMenu;
        }
    }

    public function testElementorAndYoastMenuRemovalAndUrlNormalization(): void
    {
        $backupMenu = $GLOBALS['menu'] ?? null;
        $backupSubmenu = $GLOBALS['submenu'] ?? null;
        $backupCaps = $GLOBALS['test_user_caps'] ?? null;

        try {
            $GLOBALS['test_user_caps'] = ['read' => true];

            $GLOBALS['menu'] = [
                2 => ['Dashboard', 'read', 'index.php', 'Dashboard', 'menu-top', 'menu-dashboard'],
                60 => ['Appearance', 'edit_theme_options', 'themes.php', 'Appearance', 'menu-top', 'menu-appearance'],
                100 => ['Elementor', 'read', 'elementor-home', 'Elementor', 'menu-top', 'toplevel_page_elementor-home'],
                101 => ['Elementor', 'read', 'elementor', 'Elementor', 'menu-top', 'toplevel_page_elementor'],
                102 => [
                    'Yoast SEO',
                    'read',
                    'wpseo_dashboard',
                    'Yoast SEO',
                    'menu-top',
                    'toplevel_page_wpseo_dashboard',
                ],
                103 => [
                    'Templates',
                    'edit_posts',
                    'edit.php?post_type=elementor_library',
                    'Templates',
                    'menu-top',
                    'menu-templates',
                ],
            ];

            $themeBuilderUrl = 'https://example.com/wp-admin/admin.php?page=elementor-app&amp;ver=4.3.5';
            $cptTaxUrl = 'edit-tags.php?taxonomy=elementor_library_category&amp;post_type=elementor_library';

            $GLOBALS['submenu'] = [
                'themes.php' => [
                    [0 => 'Customize', 1 => 'edit_theme_options', 2 => 'customize.php?return=%2Fwp-admin%2F'],
                ],
                'elementor-home' => [
                    [0 => 'Theme Builder', 1 => 'read', 2 => $themeBuilderUrl],
                ],
                'elementor' => [
                    [0 => 'Renew Now', 1 => 'read', 2 => 'https://go.elementor.com/wp-menu-renew'],
                    [
                        0 => 'Unlock More Features',
                        1 => 'read',
                        2 => 'https://go.elementor.com/go-pro-advanced-elementor-menu',
                    ],
                ],
                'wpseo_dashboard' => [
                    [0 => 'Settings', 1 => 'manage_options', 2 => 'wpseo_page_settings'],
                ],
                'edit.php?post_type=elementor_library' => [
                    [0 => 'Categories', 1 => 'manage_categories', 2 => $cptTaxUrl],
                ],
            ];

            $user = (object) ['ID' => 10, 'user_login' => 'editor_user'];
            /** @var list<AdminRule> $rules */
            $rules = [
                [
                    'targets' => [
                        'elementor-home',
                        'elementor-app',
                        'elementor',
                        'https://go.elementor.com/wp-menu-renew',
                        'https://go.elementor.com/go-pro-advanced-elementor-menu',
                        'themes.php',
                        'customize.php',
                        'wpseo_dashboard',
                        'elementor_library',
                        'edit-tags.php?taxonomy=elementor_library_category',
                    ],
                    'isAll' => true,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => [],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'block',
                ],
            ];

            $control = new MenuControl(
                envGetter: static fn(string $name): string => 'dummy',
                currentUserGetter: static fn(): object => $user,
                ruleParser: static fn(string $raw): array => $rules,
                userMatcher: static fn(array $r, ?object $u): bool => true,
                userRestrictedChecker: static fn(array $r, ?object $u): bool => true
            );

            $control->handleAdminMenu();

            $remainingMenuSlugs = [];
            foreach ($GLOBALS['menu'] as $item) {
                if (\is_array($item) && isset($item[2])) {
                    $remainingMenuSlugs[] = $item[2];
                }
            }

            $this->assertContains('index.php', $remainingMenuSlugs);
            $this->assertNotContains('themes.php', $remainingMenuSlugs);
            $this->assertNotContains('elementor-home', $remainingMenuSlugs);
            $this->assertNotContains('elementor', $remainingMenuSlugs);
            $this->assertNotContains('wpseo_dashboard', $remainingMenuSlugs);
            $this->assertNotContains('edit.php?post_type=elementor_library', $remainingMenuSlugs);

            $this->assertArrayNotHasKey('themes.php', $GLOBALS['submenu']);
            $this->assertArrayNotHasKey('elementor-home', $GLOBALS['submenu']);
            $this->assertArrayNotHasKey('elementor', $GLOBALS['submenu']);
            $this->assertArrayNotHasKey('wpseo_dashboard', $GLOBALS['submenu']);
            $this->assertArrayNotHasKey('edit.php?post_type=elementor_library', $GLOBALS['submenu']);
        } finally {
            $GLOBALS['menu'] = $backupMenu;
            $GLOBALS['submenu'] = $backupSubmenu;
            $GLOBALS['test_user_caps'] = $backupCaps;
        }
    }

    public function testCleanupSidebarSeparatorsDetailed(): void
    {
        $backupMenu = $GLOBALS['menu'] ?? null;
        try {
            $GLOBALS['menu'] = [
                0 => ['', 'read', 'separator0', '', 'wp-menu-separator'],
                2 => ['Dashboard', 'read', 'index.php', 'Dashboard', 'menu-top', 'menu-dashboard'],
                4 => ['', 'read', 'separator1', '', 'wp-menu-separator'],
                5 => ['', 'read', 'separator2', '', 'wp-menu-separator'],
                6 => ['', 'read', 'separator3', '', 'wp-menu-separator'],
                10 => ['Posts', 'edit_posts', 'edit.php', 'Posts', 'menu-top', 'menu-posts'],
                99 => ['', 'read', 'separator99', '', 'wp-menu-separator'],
            ];

            $control = new MenuControl();
            $control->cleanupSidebarSeparators();

            $this->assertArrayNotHasKey(0, $GLOBALS['menu']);
            $this->assertArrayHasKey(2, $GLOBALS['menu']);
            $this->assertArrayHasKey(4, $GLOBALS['menu']);
            $this->assertArrayNotHasKey(5, $GLOBALS['menu']);
            $this->assertArrayNotHasKey(6, $GLOBALS['menu']);
            $this->assertArrayHasKey(10, $GLOBALS['menu']);
            $this->assertArrayNotHasKey(99, $GLOBALS['menu']);

            $GLOBALS['menu'] = [
                1 => ['', 'read', 'separator1', '', 'wp-menu-separator'],
                2 => ['', 'read', 'separator2', '', 'wp-menu-separator'],
            ];
            $control->cleanupSidebarSeparators();
            $this->assertEmpty($GLOBALS['menu']);

            unset($GLOBALS['menu']);
            $control->cleanupSidebarSeparators();
        } finally {
            $GLOBALS['menu'] = $backupMenu;
        }
    }

    public function testHandleAdminHeadExecutesPruningAndSeparatorsAndStyles(): void
    {
        $backupMenu = $GLOBALS['menu'] ?? null;
        $backupSubmenu = $GLOBALS['submenu'] ?? null;
        try {
            $GLOBALS['menu'] = [
                0 => ['', 'read', 'separator0', '', 'wp-menu-separator'],
                2 => ['Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard'],
                50 => ['Yoast', 'read', 'wpseo_dashboard', '', 'menu-top', 'toplevel_page_wpseo_dashboard'],
            ];
            $GLOBALS['submenu'] = [
                'wpseo_dashboard' => [
                    [0 => 'Tools', 1 => 'read', 2 => 'wpseo_tools'],
                ],
            ];

            $echoedHtml = [];
            $control = new MenuControl(
                envGetter: static fn(string $n): string => 'wpseo_dashboard|hide:all',
                currentUserGetter: static fn(): object => (object) ['ID' => 2],
                ruleParser: static fn(string $r): array => [
                    [
                        'targets' => ['wpseo_dashboard'],
                        'isAll' => true,
                        'superadmins' => false,
                        'invertedSuperadmins' => false,
                        'roles' => [],
                        'invertedRoles' => [],
                        'users' => [],
                        'invertedUsers' => [],
                        'action' => 'hide',
                    ],
                ],
                userMatcher: static fn(array $r, ?object $u): bool => true,
                htmlEchoer: static function (string $html) use (&$echoedHtml): void {
                    $echoedHtml[] = $html;
                }
            );

            $control->handleAdminHead();

            $this->assertArrayNotHasKey(0, $GLOBALS['menu']);
            $this->assertArrayHasKey(2, $GLOBALS['menu']);
            $this->assertArrayNotHasKey(50, $GLOBALS['menu']);
            $this->assertArrayNotHasKey('wpseo_dashboard', $GLOBALS['submenu']);
            $this->assertNotEmpty($echoedHtml);
            $this->assertStringContainsString('wp-admin-control-sidebar-menu', $echoedHtml[0]);
        } finally {
            $GLOBALS['menu'] = $backupMenu;
            $GLOBALS['submenu'] = $backupSubmenu;
        }
    }

    public function testHandleAdminMenuEdgeCasesAndBranchCoverage(): void
    {
        $backupMenu = $GLOBALS['menu'] ?? null;
        $backupSubmenu = $GLOBALS['submenu'] ?? null;
        $backupCaps = $GLOBALS['test_user_caps'] ?? null;
        try {
            $GLOBALS['test_user_caps'] = ['manage_options' => true];
            $GLOBALS['menu'] = [
                3 => 'not_an_array',
                10 => ['Settings', 'manage_options', 'options-general.php', '', 'menu-top'],
                25 => ['Custom Tool', 'read', 'custom-tool.php?view=all', '', 'menu-top'],
                35 => ['Hook Menu', 'read', 'admin.php?other=1', '', 'menu-top', 'toplevel_page_hook_targeted'],
            ];

            $GLOBALS['submenu'] = [
                'options-general.php' => [
                    'invalid_string',
                    [0 => 'MissingSlug'],
                    [0 => 'Reading', 1 => 'manage_options', 2 => 'options-reading.php'],
                ],
                'custom-parent' => [
                    'not_array',
                    [0 => 'missing_slug'],
                ],
                'tools.php' => [
                    [0 => 'Customize', 1 => 'read', 2 => 'customize.php?custom=1'],
                    [0 => 'Clean', 1 => 'read', 2 => 'tools.php?action=clean'],
                    [0 => 'Custom Page', 1 => 'read', 2 => 'admin.php?page=explicit_only_sub&tab=advanced'],
                ],
                'empty_key' => [],
                'non_array_key' => 'string',
            ];

            /** @var list<AdminRule> $rules */
            $rules = [
                [
                    'targets' => [
                        'options-general.php',
                        'tools.php:customize.php',
                        'tools.php?action=clean&type=cache',
                        'tools.php:explicit_only_sub',
                        'custom-tool.php',
                        'hook_targeted',
                    ],
                    'isAll' => true,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => [],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'block',
                ],
            ];

            $control = new MenuControl(
                envGetter: static fn(string $n): string => 'dummy',
                currentUserGetter: static fn(): object => (object) ['ID' => 10],
                ruleParser: static fn(string $r): array => $rules,
                userMatcher: static fn(array $r, ?object $u): bool => true,
                userRestrictedChecker: static fn(array $r, ?object $u): bool => true
            );

            $control->handleAdminMenu();

            $this->assertArrayHasKey(10, $GLOBALS['menu']);
            $this->assertArrayNotHasKey(25, $GLOBALS['menu']);
            $this->assertArrayNotHasKey(35, $GLOBALS['menu']);
            $this->assertArrayNotHasKey('empty_key', $GLOBALS['submenu']);
            $this->assertArrayNotHasKey('non_array_key', $GLOBALS['submenu']);
            $this->assertArrayNotHasKey('hook_targeted', $GLOBALS['submenu']);
        } finally {
            $GLOBALS['menu'] = $backupMenu;
            $GLOBALS['submenu'] = $backupSubmenu;
            $GLOBALS['test_user_caps'] = $backupCaps;
        }
    }
}
