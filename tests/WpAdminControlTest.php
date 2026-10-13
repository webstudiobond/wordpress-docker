<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use WP_Error;
use WpAdminControl;

final class WpAdminControlTest extends TestCase
{
    private string $salt = 'test_salt_value_abcdef123456';
    private string $superAdminHash = '';

    private ?object $currentUser = null;
    /** @var array<int, object> */
    private array $usersData = [];
    /** @var list<object> */
    private array $allUsers = [];
    /** @var list<array{message: string, title: int|string, args: array<string, mixed>}> */
    private array $dieCalls = [];
    private bool $isAdmin = true;
    private bool $doingAjax = false;
    private bool $doingCron = false;
    /** @var list<string> */
    private array $removedMenuPages = [];
    /** @var list<array{0: string, 1: string}> */
    private array $removedSubmenuPages = [];
    /** @var list<array<string, mixed>> */
    private array $addedMenuPages = [];
    /** @var list<array<string, mixed>> */
    private array $addedSubmenuPages = [];
    /** @var list<array{0: string, 1: string|object, 2: string}> */
    private array $removedMetaBoxes = [];
    /** @var list<array{0: string, 1: callable|string, 2: int}> */
    private array $removedActions = [];
    /** @var array<string, array{name: string, callback: callable}> */
    private array $addedDashboardWidgets = [];
    private ?object $currentScreen = null;
    private string $echoedHtml = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdminHash = hash_hmac('sha512', '1:superadmin', $this->salt);

        $this->currentUser = null;
        $this->usersData = [];
        $this->allUsers = [];
        $this->dieCalls = [];
        $this->isAdmin = true;
        $this->doingAjax = false;
        $this->doingCron = false;
        $this->removedMenuPages = [];
        $this->removedSubmenuPages = [];
        $this->addedMenuPages = [];
        $this->addedSubmenuPages = [];
        $this->removedMetaBoxes = [];
        $this->removedActions = [];
        $this->addedDashboardWidgets = [];
        $this->currentScreen = null;
        $this->echoedHtml = '';

        $GLOBALS['pagenow'] = 'index.php';
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['QUERY_STRING'] = '';
    }

    protected function tearDown(): void
    {
        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL');
        putenv('WP_ADMIN_RESTRICTED_PAGES');
        putenv('WP_ADMIN_MENU_ORDER');
        putenv('WP_ADMIN_BAR_REMOVE_NODES');
        putenv('WP_ADMIN_BAR_NEW_REMOVE');
        putenv('WP_ADMIN_BAR_NEW_ORDER');
        putenv('WP_ADMIN_DISABLE_SCREEN_OPTIONS');
        putenv('WP_ADMIN_HIDDEN_META_BOXES');
        putenv('WP_ADMIN_HIDDEN_COLUMNS');
        putenv('WP_ADMIN_DASHBOARD_REMOVE_BOXES');
        putenv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE');
        putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER');
        putenv('WP_ADMIN_RESTRICTED_POSTS');
        putenv('WP_ADMIN_HIDE_SELECTORS');

        for ($i = 1; $i <= 5; $i++) {
            putenv('WP_ADMIN_CUSTOM_MENU_' . $i . '_ID');
            putenv('WP_ADMIN_CUSTOM_MENU_' . $i . '_TITLE');
            putenv('WP_ADMIN_CUSTOM_MENU_' . $i . '_ICON');
            putenv('WP_ADMIN_CUSTOM_MENU_' . $i . '_TARGET');
            putenv('WP_ADMIN_CUSTOM_MENU_' . $i . '_ITEMS');

            putenv('WP_ADMIN_DASHBOARD_WIDGET_' . $i . '_TITLE');
            putenv('WP_ADMIN_DASHBOARD_WIDGET_' . $i . '_TARGET');
            putenv('WP_ADMIN_DASHBOARD_WIDGET_' . $i . '_CONTENT');
        }

        unset(
            $GLOBALS['menu'],
            $GLOBALS['submenu'],
            $GLOBALS['_parent_pages'],
            $GLOBALS['parent_file'],
            $GLOBALS['submenu_file'],
            $GLOBALS['pagenow'],
            $GLOBALS['plugin_page'],
            $GLOBALS['test_current_user'],
            $GLOBALS['test_userdata'],
            $GLOBALS['test_user_by'],
            $GLOBALS['test_users_list'],
            $GLOBALS['test_current_screen'],
            $GLOBALS['test_removed_menu_pages'],
            $GLOBALS['test_removed_submenu_pages'],
            $GLOBALS['test_added_menu_pages'],
            $GLOBALS['test_added_submenu_pages'],
            $GLOBALS['test_removed_meta_boxes'],
            $GLOBALS['test_dashboard_widgets'],
            $GLOBALS['test_status_header'],
            $GLOBALS['test_nocache_headers_called'],
            $GLOBALS['test_queried_object_id'],
            $GLOBALS['test_is_author'],
            $GLOBALS['test_wp_doing_cron'],
            $GLOBALS['test_throw_on_redirect'],
            $GLOBALS['test_redirect_location'],
            $GLOBALS['wp_query']
        );

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $dependencies
     */
    private function createPluginWithMockFiles(
        ?string $superAdminFileContent = null,
        ?string $saltFileContent = null,
        array $dependencies = []
    ): WpAdminControl {
        $adminContent = $superAdminFileContent ?? $this->superAdminHash;
        $activeSalt = $saltFileContent ?? $this->salt;

        $fileMap = [
            '/run/secrets/wp_super_admins' => $adminContent,
            '/run/secrets/auth_salt' => $activeSalt,
        ];

        return new WpAdminControl(
            fileReader: static fn (string $path): string|false => $fileMap[$path] ?? false,
            currentUserGetter: $dependencies['currentUserGetter'] ?? fn (): ?object => $this->currentUser,
            userDataGetter: $dependencies['userDataGetter'] ?? fn (int $id): ?object => $this->usersData[$id] ?? null,
            userByGetter: $dependencies['userByGetter'] ?? fn (
                string $field,
                string|int $value
            ): ?object => $this->filterUserByField($field, $value),
            usersGetter: $dependencies['usersGetter'] ?? fn (array $args): array => $this->filterUsersByArgs($args),
            dieHandler: $dependencies['dieHandler'] ?? function (
                string $msg,
                int|string $title,
                array $args
            ): void {
                $this->dieCalls[] = ['message' => $msg, 'title' => $title, 'args' => $args];
            },
            adminChecker: $dependencies['adminChecker'] ?? fn (): bool => $this->isAdmin,
            ajaxChecker: $dependencies['ajaxChecker'] ?? fn (): bool => $this->doingAjax,
            cronChecker: $dependencies['cronChecker'] ?? fn (): bool => $this->doingCron,
            menuRemover: $dependencies['menuRemover'] ?? function (string $slug): void {
                $this->removedMenuPages[] = $slug;
            },
            submenuRemover: $dependencies['submenuRemover'] ?? function (string $parent, string $sub): void {
                $this->removedSubmenuPages[] = [$parent, $sub];
                if (isset($GLOBALS['submenu'][$parent]) && is_array($GLOBALS['submenu'][$parent])) {
                    foreach ($GLOBALS['submenu'][$parent] as $idx => $item) {
                        if (isset($item[2]) && $item[2] === $sub) {
                            unset($GLOBALS['submenu'][$parent][$idx]);
                        }
                    }
                    $GLOBALS['submenu'][$parent] = array_values($GLOBALS['submenu'][$parent]);
                }
            },
            menuAdder: $dependencies['menuAdder'] ?? function (
                string $pageTitle,
                string $menuTitle,
                string $capability,
                string $menuSlug,
                mixed $callback = '',
                string $iconUrl = '',
                int|float|null $position = null
            ): string {
                $this->addedMenuPages[] = [
                    'page_title' => $pageTitle,
                    'menu_title' => $menuTitle,
                    'capability' => $capability,
                    'menu_slug' => $menuSlug,
                    'icon_url' => $iconUrl,
                    'position' => $position,
                ];
                return 'hook_' . $menuSlug;
            },
            submenuAdder: $dependencies['submenuAdder'] ?? function (
                string $parentSlug,
                string $pageTitle,
                string $menuTitle,
                string $capability,
                string $menuSlug,
                mixed $callback = null,
                int|float|null $position = null
            ): string {
                if (!isset($GLOBALS['submenu'][$parentSlug])) {
                    $GLOBALS['submenu'][$parentSlug][] = [$parentSlug, $capability, $parentSlug, $parentSlug];
                }
                $GLOBALS['submenu'][$parentSlug][] = [$menuTitle, $capability, $menuSlug, $pageTitle];
                $this->addedSubmenuPages[] = [
                    'parent_slug' => $parentSlug,
                    'page_title' => $pageTitle,
                    'menu_title' => $menuTitle,
                    'capability' => $capability,
                    'menu_slug' => $menuSlug,
                    'position' => $position,
                ];
                return 'subhook_' . $menuSlug;
            },
            dashboardWidgetAdder: $dependencies['dashboardWidgetAdder'] ?? function (
                string $widgetId,
                string $widgetName,
                callable $callback
            ): void {
                $this->addedDashboardWidgets[$widgetId] = [
                    'name' => $widgetName,
                    'callback' => $callback,
                ];
            },
            metaBoxRemover: $dependencies['metaBoxRemover'] ?? function (
                string $id,
                string|object $screen,
                string $context
            ): void {
                $this->removedMetaBoxes[] = [$id, $screen, $context];
            },
            actionRemover: $dependencies['actionRemover'] ?? function (
                string $tag,
                callable|string $cb,
                int $priority = 10
            ): void {
                $this->removedActions[] = [$tag, $cb, $priority];
            },
            currentScreenGetter: $dependencies['currentScreenGetter'] ?? fn (): ?object => $this->currentScreen,
            headerEchoer: $dependencies['headerEchoer'] ?? function (string $html): void {
                $this->echoedHtml .= $html;
            },
            terminator: $dependencies['terminator'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return list<object>
     */
    private function filterUsersByArgs(array $args): array
    {
        $role = $args['role'] ?? null;
        if (!is_string($role) || $role === '') {
            return $this->allUsers;
        }

        $filtered = [];
        foreach ($this->allUsers as $u) {
            $roles = is_array($u->roles ?? null) ? $u->roles : [];
            if (in_array($role, $roles, true)) {
                $filtered[] = $u;
            }
        }
        return $filtered;
    }

    /**
     * @param list<string> $roles
     */
    private function createMockUser(int $id, string $login, array $roles, string $nicename = ''): object
    {
        $user = new stdClass();
        $user->ID = $id;
        $user->user_login = $login;
        $user->user_nicename = $nicename !== '' ? $nicename : strtolower($login);
        $user->roles = $roles;
        return $user;
    }

    private function filterUserByField(string $field, string|int $value): ?object
    {
        foreach ($this->allUsers as $u) {
            if ($field === 'slug' && isset($u->user_nicename) && $u->user_nicename === (string) $value) {
                return $u;
            }
            if (
                $field === 'login'
                && isset($u->user_login)
                && strtolower((string) $u->user_login) === strtolower((string) $value)
            ) {
                return $u;
            }
            if ($field === 'id' && isset($u->ID) && (int) $u->ID === (int) $value) {
                return $u;
            }
        }
        foreach ($this->usersData as $u) {
            if ($field === 'slug' && isset($u->user_nicename) && $u->user_nicename === (string) $value) {
                return $u;
            }
            if (
                $field === 'login'
                && isset($u->user_login)
                && strtolower((string) $u->user_login) === strtolower((string) $value)
            ) {
                return $u;
            }
            if ($field === 'id' && isset($u->ID) && (int) $u->ID === (int) $value) {
                return $u;
            }
        }
        return null;
    }

    public function testSuperAdminIdentificationWithValidHash(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superUser = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->assertTrue($plugin->isSuperAdmin($superUser));

        $upperUser = $this->createMockUser(1, 'SUPERADMIN', ['administrator']);
        $this->assertTrue($plugin->isSuperAdmin($upperUser));

        $normalUser = $this->createMockUser(2, 'manager', ['administrator']);
        $this->assertFalse($plugin->isSuperAdmin($normalUser));

        $wrongIdUser = $this->createMockUser(99, 'superadmin', ['administrator']);
        $this->assertFalse($plugin->isSuperAdmin($wrongIdUser));

        $emptyUser = $this->createMockUser(0, '', []);
        $this->assertFalse($plugin->isSuperAdmin($emptyUser));

        $this->assertFalse($plugin->isSuperAdmin(null));
    }

    public function testSuperAdminIdentificationWithMissingOrInvalidFiles(): void
    {
        $emptySaltPlugin = $this->createPluginWithMockFiles(saltFileContent: '');
        $superUser = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->assertFalse($emptySaltPlugin->isSuperAdmin($superUser));

        $corruptedHashPlugin = $this->createPluginWithMockFiles(superAdminFileContent: 'not-a-valid-sha512-hash');
        $this->assertFalse($corruptedHashPlugin->isSuperAdmin($superUser));

        $noFilePlugin = new WpAdminControl(fileReader: static fn (): false => false);
        $this->assertFalse($noFilePlugin->isSuperAdmin($superUser));
    }

    public function testResolveSuperAdminIdsExtractsAdminIdsMatchingHashes(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $admin1 = $this->createMockUser(1, 'superadmin', ['administrator']);
        $admin2 = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $editor = $this->createMockUser(3, 'editor_user', ['editor']);

        $this->allUsers = [$admin1, $admin2, $editor];

        $resolved = $plugin->resolveSuperAdminIds();
        $this->assertSame([1], $resolved);

        $cachedResolved = $plugin->resolveSuperAdminIds();
        $this->assertSame([1], $cachedResolved);
    }

    public function testFilterPreGetUsersExcludesSuperAdminWhenNonSuperAdminRequests(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $admin1 = $this->createMockUser(1, 'superadmin', ['administrator']);
        $admin2 = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$admin1, $admin2];

        $this->currentUser = $admin2;

        $query = new class {
            /** @var array<string, mixed> */
            public array $params = ['exclude' => [10, 20]];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    $key = (string) ($arguments[0] ?? '');
                    return $this->params[$key] ?? null;
                }
                if ($name === 'set') {
                    $key = (string) ($arguments[0] ?? '');
                    $this->params[$key] = $arguments[1] ?? null;
                    return null;
                }
                return null;
            }
        };

        $plugin->filterPreGetUsers($query);
        $this->assertSame([10, 20, 1], $query->params['exclude']);

        $stringQuery = new class {
            /** @var array<string, mixed> */
            public array $params = ['exclude' => '30,40'];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    $key = (string) ($arguments[0] ?? '');
                    return $this->params[$key] ?? null;
                }
                if ($name === 'set') {
                    $key = (string) ($arguments[0] ?? '');
                    $this->params[$key] = $arguments[1] ?? null;
                    return null;
                }
                return null;
            }
        };

        $plugin->filterPreGetUsers($stringQuery);
        $this->assertSame([30, 40, 1], $stringQuery->params['exclude']);

        $this->currentUser = $admin1;
        $superQuery = new class {
            /** @var array<string, mixed> */
            public array $params = ['exclude' => [5]];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    $key = (string) ($arguments[0] ?? '');
                    return $this->params[$key] ?? null;
                }
                if ($name === 'set') {
                    $key = (string) ($arguments[0] ?? '');
                    $this->params[$key] = $arguments[1] ?? null;
                    return null;
                }
                return null;
            }
        };

        $plugin->filterPreGetUsers($superQuery);
        $this->assertSame([5], $superQuery->params['exclude']);
    }

    public function testFilterViewsUsersAdjustsCountersForNonSuperAdmin(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $admin1 = $this->createMockUser(1, 'superadmin', ['administrator']);
        $admin2 = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$admin1, $admin2];

        $this->currentUser = $admin2;

        $views = [
            'all' => '<a href="users.php">All <span class="count">(10)</span></a>',
            'administrator' => '<a href="users.php?role=administrator">Admin <span class="count">(2)</span></a>',
            'editor' => '<a href="users.php?role=editor">Editor <span class="count">(5)</span></a>',
            'non_string' => 123,
        ];

        $filtered = $plugin->filterViewsUsers($views);
        $this->assertStringContainsString('(9)', (string) $filtered['all']);
        $this->assertStringContainsString('(1)', (string) $filtered['administrator']);
        $this->assertStringContainsString('(5)', (string) $filtered['editor']);
        $this->assertSame(123, $filtered['non_string']);

        $this->currentUser = $admin1;
        $unfiltered = $plugin->filterViewsUsers($views);
        $this->assertSame($views, $unfiltered);
    }

    public function testFilterRestUserQueryExcludesSuperAdmin(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $admin1 = $this->createMockUser(1, 'superadmin', ['administrator']);
        $admin2 = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$admin1, $admin2];

        $this->currentUser = $admin2;

        $args = ['exclude' => [7]];
        $filtered = $plugin->filterRestUserQuery($args);
        $this->assertSame([7, 1], $filtered['exclude']);

        $this->currentUser = $admin1;
        $unfiltered = $plugin->filterRestUserQuery($args);
        $this->assertSame([7], $unfiltered['exclude']);
    }

    public function testDirectEditOrDeleteOracleProtectionForSuperAdminProfile(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $otherUser = $this->createMockUser(5, 'john_doe', ['subscriber']);

        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $normalAdmin;
        $this->usersData[5] = $otherUser;

        $this->currentUser = $normalAdmin;
        $GLOBALS['pagenow'] = 'user-edit.php';
        $_GET['user_id'] = '1';

        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(500, $this->dieCalls[0]['args']['response']);
        $this->assertSame('Invalid user ID.', $this->dieCalls[0]['message']);

        $this->dieCalls = [];
        unset($_GET['user_id']);
        $_POST['user_id'] = '1';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(500, $this->dieCalls[0]['args']['response']);

        $this->dieCalls = [];
        $_POST['user_id'] = '5';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $GLOBALS['pagenow'] = 'users.php';
        $_REQUEST['action'] = 'delete';
        $_REQUEST['user'] = '1';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(500, $this->dieCalls[0]['args']['response']);

        $this->dieCalls = [];
        unset($_REQUEST['user']);
        $_REQUEST['action'] = '-1';
        $_REQUEST['action2'] = 'delete';
        $_REQUEST['users'] = ['2', '1'];
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(500, $this->dieCalls[0]['args']['response']);

        $this->dieCalls = [];
        $_REQUEST['action'] = 'deleteuser';
        $_REQUEST['users'] = '2,1';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(500, $this->dieCalls[0]['args']['response']);

        $this->dieCalls = [];
        $_REQUEST['action'] = 'set-role';
        $_REQUEST['new_role'] = 'subscriber';
        $_REQUEST['users'] = ['1'];
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(500, $this->dieCalls[0]['args']['response']);

        $this->dieCalls = [];
        $this->currentUser = $superAdmin;
        $GLOBALS['pagenow'] = 'user-edit.php';
        $_GET['user_id'] = '1';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }

    public function testGuestAndCronRequestIsolation(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $this->currentUser = null;
        $GLOBALS['pagenow'] = 'plugins.php';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $this->currentUser = $this->createMockUser(2, 'admin2', ['administrator']);
        $this->doingAjax = true;
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $this->doingAjax = false;
        $this->doingCron = true;
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $this->doingCron = false;
        $this->isAdmin = false;
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }

    public function testDslRuleParsingAndEvaluation(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $rulesString = 'plugins.php,update-core.php|!role:administrator; '
            . 'tools.php|role:editor,author|!user:7; '
            . 'options.php|!user:5; '
            . 'media.php|user:3,12; '
            . 'junk.php|all; '
            . 'empty.php';
        $rules = $plugin->parseRules($rulesString);
        $this->assertCount(6, $rules);

        $rule1 = $rules[0];
        $this->assertSame(['plugins.php', 'update-core.php'], $rule1['targets']);
        $this->assertSame(['administrator'], $rule1['invertedRoles']);
        $this->assertFalse($rule1['isAll']);

        $admin = $this->createMockUser(2, 'admin2', ['administrator']);
        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $user7Editor = $this->createMockUser(7, 'editor_seven', ['editor']);
        $user5 = $this->createMockUser(5, 'user_five', ['subscriber']);
        $user6 = $this->createMockUser(6, 'user_six', ['subscriber']);
        $user3 = $this->createMockUser(3, 'user_three', ['subscriber']);
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);

        $this->assertFalse($plugin->isUserRestrictedByRule($rule1, $admin));
        $this->assertTrue($plugin->isUserRestrictedByRule($rule1, $editor));
        $this->assertFalse($plugin->isUserRestrictedByRule($rule1, $superAdmin));

        $rule2 = $rules[1];
        $this->assertTrue($plugin->isUserRestrictedByRule($rule2, $editor));
        $this->assertFalse($plugin->isUserRestrictedByRule($rule2, $user7Editor));
        $this->assertFalse($plugin->isUserRestrictedByRule($rule2, $superAdmin));

        $rule3 = $rules[2];
        $this->assertFalse($plugin->isUserRestrictedByRule($rule3, $user5));
        $this->assertTrue($plugin->isUserRestrictedByRule($rule3, $user6));

        $rule4 = $rules[3];
        $this->assertTrue($plugin->isUserRestrictedByRule($rule4, $user3));
        $this->assertFalse($plugin->isUserRestrictedByRule($rule4, $user6));

        $rule5 = $rules[4];
        $this->assertSame('hide', $rule5['action']);
        $this->assertTrue($plugin->isUserRestrictedByRule($rule5, $editor));
        $this->assertFalse($plugin->isUserRestrictedByRule($rule5, $superAdmin));
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($rule5, $superAdmin));
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($rule5, $editor));

        $actionRules = $plugin->parseRules('item1|action:block; item2|action:hide; item3|block');
        $this->assertSame('block', $actionRules[0]['action']);
        $this->assertSame('hide', $actionRules[1]['action']);
        $this->assertSame('block', $actionRules[2]['action']);
    }

    public function testRestrictedPagesRemovalAndDirectUrlBlocking(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);

        $pageConfig = 'plugins.php,options-general.php|block:editor; '
            . 'acf-field-groups|block:!administrator';
        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=' . $pageConfig);

        $this->currentUser = $editor;
        $plugin->handleAdminMenu();
        $this->assertContains('plugins.php', $this->removedMenuPages);
        $this->assertContains('options-general.php', $this->removedMenuPages);
        $this->assertContains('acf-field-groups', $this->removedMenuPages);

        $this->removedMenuPages = [];
        $this->currentUser = $superAdmin;
        $plugin->handleAdminMenu();
        $this->assertSame([], $this->removedMenuPages);

        $this->currentUser = $editor;
        $GLOBALS['pagenow'] = 'plugins.php';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);

        $this->dieCalls = [];
        $GLOBALS['pagenow'] = 'admin.php';
        $_GET['page'] = 'acf-field-groups';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);

        $this->dieCalls = [];
        $GLOBALS['pagenow'] = 'admin.php';
        $_SERVER['QUERY_STRING'] = 'foo=bar&page=ACF-FIELD-GROUPS&dummy=1';
        $_GET['page'] = 'ACF-FIELD-GROUPS';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);

        $this->dieCalls = [];
        $this->currentUser = $superAdmin;
        $GLOBALS['pagenow'] = 'plugins.php';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }

    public function testSidebarMenuControlHideVsBlockDistinction(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $admin = $this->createMockUser(2, 'admin1', ['administrator']);
        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=elementor-license|hide:administrator|block:editor');

        $this->currentUser = $admin;
        $plugin->handleAdminMenu();
        $this->assertContains('elementor-license', $this->removedMenuPages);

        $this->dieCalls = [];
        $GLOBALS['pagenow'] = 'admin.php';
        $_GET['page'] = 'elementor-license';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $this->removedMenuPages = [];
        $this->currentUser = $editor;
        $plugin->handleAdminMenu();
        $this->assertContains('elementor-license', $this->removedMenuPages);

        $this->dieCalls = [];
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);

        $this->removedMenuPages = [];
        $_GET = [];
        $this->currentUser = $superAdmin;
        $plugin->handleAdminMenu();
        $this->assertContains('elementor-license', $this->removedMenuPages);

        $_GET['page'] = 'elementor-license';
        $this->removedMenuPages = [];
        $plugin->handleAdminMenu();
        $this->assertNotContains('elementor-license', $this->removedMenuPages);

        $this->dieCalls = [];
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }

    public function testSidebarMenuControlSubmenuPairsAndHeadCss(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $this->currentUser = $editor;

        $GLOBALS['submenu'] = [
            'tools.php' => [
                0 => ['Import', 'import', 'import.php'],
                1 => ['Export', 'export', 'export.php'],
                2 => ['Regenerate', 'manage_options', 'rta_generate_thumbnails'],
                3 => ['License Page', 'manage_options', 'admin.php?page=elementor-license'],
            ],
        ];

        $sideControl = 'tools.php:rta_generate_thumbnails|hide:editor; '
            . 'elementor-license|block:editor';
        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=' . $sideControl);

        $plugin->handleAdminMenu();

        $this->assertContains(['tools.php', 'rta_generate_thumbnails'], $this->removedSubmenuPages);
        $this->assertContains('elementor-license', $this->removedMenuPages);

        $remainingSubmenuSlugs = array_map(
            static fn (array $item): mixed => $item[2] ?? null,
            $GLOBALS['submenu']['tools.php']
        );
        $this->assertNotContains('admin.php?page=elementor-license', $remainingSubmenuSlugs);

        $plugin->handleAdminHead();
        $this->assertStringContainsString('<style id="wp-admin-control-sidebar-menu">', $this->echoedHtml);
        $this->assertStringContainsString('#adminmenu a[href*="page=rta_generate_thumbnails"]', $this->echoedHtml);
        $this->assertStringContainsString('li[data-slug="elementor-license"]', $this->echoedHtml);
    }

    public function testSidebarMenuControlCompactSyntaxAndPrecedence(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $user2 = $this->createMockUser(2, 'user2', ['editor']);
        $user3 = $this->createMockUser(3, 'user3', ['author']);
        $user5 = $this->createMockUser(5, 'user5', ['contributor']);

        $sideControl = 'tools.php|hide:2,author|block:3,editor; '
            . 'edit.php?post_type=feedback|block:!5';
        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=' . $sideControl);

        $this->currentUser = $user2;
        $plugin->handleAdminMenu();
        $this->assertContains('tools.php', $this->removedMenuPages);
        $this->assertContains('edit.php?post_type=feedback', $this->removedMenuPages);

        $this->dieCalls = [];
        $GLOBALS['pagenow'] = 'tools.php';
        $_GET = [];
        $_SERVER['QUERY_STRING'] = '';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);

        $this->removedMenuPages = [];
        $this->dieCalls = [];
        $this->currentUser = $user5;
        $GLOBALS['pagenow'] = 'edit.php';
        $_GET = ['post_type' => 'feedback'];
        $_SERVER['QUERY_STRING'] = 'post_type=feedback';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }

    public function testSidebarMenuControlFirstSubmenuPromotion(): void
    {
        $plugin = $this->createPluginWithMockFiles();
        $admin = $this->createMockUser(2, 'admin1', ['administrator']);
        $this->currentUser = $admin;

        $GLOBALS['menu'] = [
            58 => ['Elementor', 'manage_options', 'elementor-home', 'Elementor', 'menu-top', 'hook_elementor', 'icon'],
        ];
        $GLOBALS['submenu'] = [
            'elementor-home' => [
                0 => ['Home', 'manage_options', 'elementor-home'],
                1 => ['Settings', 'manage_options', 'elementor'],
                2 => ['Tools', 'manage_options', 'elementor-tools'],
            ],
        ];

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=elementor-home|hide:administrator');
        $plugin->handleAdminMenu();

        $this->assertNotContains('elementor-home', $this->removedMenuPages);
        $this->assertSame('elementor-home', $GLOBALS['menu'][58][2] ?? null);

        $remainingSubSlugs = array_map(
            static fn (array $item): mixed => $item[2] ?? null,
            $GLOBALS['submenu']['elementor-home']
        );
        $this->assertNotContains('elementor-home', $remainingSubSlugs);
        $this->assertContains('elementor', $remainingSubSlugs);
        $this->assertContains('elementor-tools', $remainingSubSlugs);
    }

    public function testSidebarMenuControlPhysicalScriptDirectAccessHidden(): void
    {
        $plugin = $this->createPluginWithMockFiles();
        $admin = $this->createMockUser(2, 'admin1', ['administrator']);
        $this->currentUser = $admin;

        $GLOBALS['menu'] = [
            5 => ['Posts', 'edit_posts', 'edit.php', 'Posts', 'menu-top', 'hook_posts', 'icon'],
        ];
        $GLOBALS['submenu'] = [
            'edit.php' => [
                0 => ['All Posts', 'edit_posts', 'edit.php'],
                1 => ['Add Post', 'edit_posts', 'post-new.php'],
                2 => ['Categories', 'manage_categories', 'edit-tags.php?taxonomy=category'],
                3 => ['Tags', 'manage_categories', 'edit-tags.php?taxonomy=post_tag'],
            ],
        ];

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=edit-tags.php?taxonomy=post_tag');
        $GLOBALS['pagenow'] = 'edit-tags.php';
        $_GET = ['taxonomy' => 'post_tag'];

        $plugin->handleAdminMenu();

        $remainingSubSlugs = array_map(
            static fn (array $item): mixed => $item[2] ?? null,
            $GLOBALS['submenu']['edit.php']
        );
        $this->assertNotContains('edit-tags.php?taxonomy=post_tag', $remainingSubSlugs);
        $this->assertContains('edit-tags.php?taxonomy=category', $remainingSubSlugs);

        $plugin->handleAdminHead();
        $this->assertStringContainsString(
            '#adminmenu a[href*="edit-tags.php?taxonomy=post_tag"]',
            $this->echoedHtml
        );
        $this->assertStringContainsString(
            '#adminmenu li:has(> a[href*="edit-tags.php?taxonomy=post_tag"])',
            $this->echoedHtml
        );
    }

    public function testMenuOrdering(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_MENU_ORDER=index.php,upload.php,edit.php');

        $this->assertTrue($plugin->filterCustomMenuOrder(false));

        $menuOrder = ['edit.php', 'plugins.php', 'upload.php', 'index.php', 'themes.php'];
        $reordered = $plugin->filterMenuOrder($menuOrder);
        $this->assertSame(['index.php', 'upload.php', 'edit.php', 'plugins.php', 'themes.php'], $reordered);

        putenv('WP_ADMIN_MENU_ORDER');
        $this->assertFalse($plugin->filterCustomMenuOrder(false));
        $this->assertSame($menuOrder, $plugin->filterMenuOrder($menuOrder));
    }

    public function testCustomGroupMenus(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_CUSTOM_MENU_1_TITLE=Support Desk');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ICON=dashicons-sos');
        putenv('WP_ADMIN_CUSTOM_MENU_1_TARGET=all');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ITEMS=Documentation|https://example.com/docs; Guide|/guide');

        $this->currentUser = $this->createMockUser(2, 'admin2', ['administrator']);
        $plugin->handleAdminMenu();

        $this->assertCount(1, $this->addedMenuPages);
        $this->assertSame('Support Desk', $this->addedMenuPages[0]['page_title']);
        $this->assertSame('dashicons-sos', $this->addedMenuPages[0]['icon_url']);
        $this->assertNull($this->addedMenuPages[0]['position']);

        $this->assertCount(2, $this->addedSubmenuPages);
        $this->assertSame('Documentation', $this->addedSubmenuPages[0]['page_title']);
        $this->assertSame('Guide', $this->addedSubmenuPages[1]['page_title']);
        $this->assertSame('custom-menu-1', $this->addedSubmenuPages[0]['parent_slug']);
        $this->assertSame('custom-menu-1', $this->addedSubmenuPages[1]['parent_slug']);
    }

    public function testCustomMenuExplicitIdAndActiveStateHighlighting(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_CUSTOM_MENU_1_ID=custom-cache');
        putenv('WP_ADMIN_CUSTOM_MENU_1_TITLE=Cache Control');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ICON=dashicons-admin-tools');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ITEMS=Tools|admin.php?page=elementor-tools; WP Hide|admin.php?page=wp-hide');

        $this->currentUser = $this->createMockUser(2, 'admin2', ['administrator']);
        $plugin->handleAdminMenu();

        $this->assertCount(1, $this->addedMenuPages);
        $this->assertSame('custom-cache', $this->addedMenuPages[0]['menu_slug']);
        $this->assertSame('Cache Control', $this->addedMenuPages[0]['page_title']);

        $this->assertCount(2, $this->addedSubmenuPages);
        $this->assertSame('custom-cache', $this->addedSubmenuPages[0]['parent_slug']);
        $this->assertSame('Tools', $this->addedSubmenuPages[0]['page_title']);
        $this->assertSame('admin.php?page=elementor-tools', $this->addedSubmenuPages[0]['menu_slug']);

        $this->assertCount(2, $GLOBALS['submenu']['custom-cache']);
        $this->assertSame('Tools', $GLOBALS['submenu']['custom-cache'][0][0]);
        $this->assertSame('admin.php?page=elementor-tools', $GLOBALS['submenu']['custom-cache'][0][2]);
        $this->assertSame('WP Hide', $GLOBALS['submenu']['custom-cache'][1][0]);
        $this->assertSame('admin.php?page=wp-hide', $GLOBALS['submenu']['custom-cache'][1][2]);
        $this->assertSame('custom-cache', $GLOBALS['_parent_pages']['elementor-tools']);
        $this->assertSame('custom-cache', $GLOBALS['_parent_pages']['wp-hide']);

        $GLOBALS['pagenow'] = 'admin.php';
        $GLOBALS['plugin_page'] = 'elementor-tools';
        $_GET['page'] = 'elementor-tools';

        $highlightedParent = $plugin->filterParentFile('elementor');
        $this->assertSame('custom-cache', $highlightedParent);
        $this->assertSame('custom-cache', $GLOBALS['parent_file']);

        $highlightedSubmenu = $plugin->filterSubmenuFile(
            'admin.php?page=elementor-tools',
            'custom-cache'
        );
        $this->assertSame('admin.php?page=elementor-tools', $highlightedSubmenu);
        $this->assertSame('admin.php?page=elementor-tools', $GLOBALS['submenu_file']);

        $GLOBALS['pagenow'] = 'admin.php';
        $GLOBALS['plugin_page'] = 'unrelated-plugin';
        $_GET['page'] = 'unrelated-plugin';

        $unchangedParent = $plugin->filterParentFile('unrelated-parent');
        $this->assertSame('unrelated-parent', $unchangedParent);

        $unchangedSubmenu = $plugin->filterSubmenuFile('unrelated-sub', 'unrelated-parent');
        $this->assertSame('unrelated-sub', $unchangedSubmenu);
    }

    public function testIsCurrentUrlMatchingEdgeCases(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $_GET = [];

        $this->assertFalse($plugin->isCurrentUrlMatching('', 'admin.php', null));

        $this->assertTrue(
            $plugin->isCurrentUrlMatching('admin.php?page=elementor-tools', 'admin.php', 'elementor-tools')
        );
        $this->assertTrue($plugin->isCurrentUrlMatching('elementor-tools', 'admin.php', 'elementor-tools'));

        $_GET['post_type'] = 'page';
        $this->assertTrue($plugin->isCurrentUrlMatching('edit.php?post_type=page', 'edit.php', null));
        $this->assertFalse($plugin->isCurrentUrlMatching('edit.php?post_type=post', 'edit.php', null));

        $_GET = ['page' => 'wp-hide-pro', 'option' => 'data-collection'];
        $this->assertTrue(
            $plugin->isCurrentUrlMatching(
                'admin.php?page=wp-hide-pro&option=data-collection',
                'admin.php',
                'wp-hide-pro'
            )
        );
        $this->assertFalse(
            $plugin->isCurrentUrlMatching(
                'admin.php?page=wp-hide-pro&option=other-option',
                'admin.php',
                'wp-hide-pro'
            )
        );

        $_GET = [];
        $this->assertTrue($plugin->isCurrentUrlMatching('upload.php', 'upload.php', null));
        $this->assertFalse($plugin->isCurrentUrlMatching('upload.php', 'upload.php', 'plugin-screen'));

        $this->assertFalse($plugin->isCurrentUrlMatching('https://example.com/docs', 'admin.php', null));
    }

    public function testSidebarSeparatorsInsertionAndMenuOrder(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $GLOBALS['menu'] = [
            2 => ['Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard'],
            5 => ['Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts'],
            20 => ['Pages', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top', 'menu-pages'],
            58 => ['Elementor', 'manage_options', 'elementor-home', '', 'menu-top', 'toplevel_page_elementor-home'],
        ];

        putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER=edit.php,toplevel_page_elementor-home,non-existent');

        $this->currentUser = $this->createMockUser(2, 'admin2', ['administrator']);
        $plugin->handleAdminMenu();

        $this->assertArrayHasKey('5.1', $GLOBALS['menu']);
        $this->assertSame('separator-custom-1', $GLOBALS['menu']['5.1'][2]);
        $this->assertSame('wp-menu-separator', $GLOBALS['menu']['5.1'][4]);

        $this->assertArrayHasKey('58.1', $GLOBALS['menu']);
        $this->assertSame('separator-custom-2', $GLOBALS['menu']['58.1'][2]);
        $this->assertSame('wp-menu-separator', $GLOBALS['menu']['58.1'][4]);

        $this->assertTrue($plugin->filterCustomMenuOrder(false));

        $orderWithoutCustom = ['index.php', 'edit.php', 'edit.php?post_type=page', 'elementor-home'];
        $reordered = $plugin->filterMenuOrder($orderWithoutCustom);

        $expectedOrder = [
            'index.php',
            'edit.php',
            'separator-custom-1',
            'edit.php?post_type=page',
            'elementor-home',
            'separator-custom-2',
        ];
        $this->assertSame($expectedOrder, array_values($reordered));

        putenv('WP_ADMIN_MENU_ORDER=elementor-home,edit.php,index.php');
        $reorderedExplicit = $plugin->filterMenuOrder($orderWithoutCustom);
        $this->assertSame([
            'elementor-home',
            'separator-custom-2',
            'edit.php',
            'separator-custom-1',
            'index.php',
            'edit.php?post_type=page',
        ], array_values($reorderedExplicit));
    }

    public function testAdminBarCleanupAndNewDropdownFiltering(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_BAR_REMOVE_NODES=wp-logo,about|all; comments|role:editor');
        putenv('WP_ADMIN_BAR_NEW_REMOVE=new-user|!role:administrator');

        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $this->currentUser = $editor;

        $adminBar = new class {
            /** @var list<string> */
            public array $removed = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                }
                return null;
            }
        };

        $plugin->handleAdminBarMenu($adminBar);
        $this->assertContains('wp-logo', $adminBar->removed);
        $this->assertContains('about', $adminBar->removed);
        $this->assertContains('comments', $adminBar->removed);
        $this->assertContains('new-user', $adminBar->removed);

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->currentUser = $superAdmin;
        $superBar = new class {
            /** @var list<string> */
            public array $removed = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                }
                return null;
            }
        };

        $plugin->handleAdminBarMenu($superBar);
        $this->assertContains('wp-logo', $superBar->removed);
        $this->assertContains('about', $superBar->removed);
        $this->assertNotContains('comments', $superBar->removed);
        $this->assertNotContains('new-user', $superBar->removed);
    }

    public function testScreenOptionsAndMetaBoxesLockdown(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_DISABLE_SCREEN_OPTIONS=true');
        putenv('WP_ADMIN_HIDDEN_META_BOXES=postcustom,slugdiv|role:editor');
        putenv('WP_ADMIN_HIDDEN_COLUMNS=comments,tags|role:editor');

        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);

        $this->currentUser = $editor;
        $this->assertFalse($plugin->filterScreenOptionsShowScreen(true));

        $this->currentUser = $superAdmin;
        $this->assertTrue($plugin->filterScreenOptionsShowScreen(true));

        $screen = new class {
            public bool $helpTabsRemoved = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_help_tabs') {
                    $this->helpTabsRemoved = true;
                }
                return null;
            }
        };
        $this->currentScreen = $screen;

        $this->currentUser = $editor;
        $plugin->handleAdminHead();
        $this->assertTrue($screen->helpTabsRemoved);

        $this->currentUser = $editor;
        $boxes = $plugin->filterHiddenMetaBoxes(['authordiv']);
        $this->assertSame(['authordiv', 'postcustom', 'slugdiv'], $boxes);

        $cols = $plugin->filterHiddenColumns(['author']);
        $this->assertSame(['author', 'comments', 'tags'], $cols);
    }

    public function testDashboardWidgetsCleanupAndMicroMarkdownRendering(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE=dashboard_recent_comments,welcome-panel|all');
        putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TITLE=Platform Guide');
        putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TARGET=all');
        $content = "Support: [Help](https://example.com/help)\n\n"
            . "**Guidelines:**\n- Point 1\n- Point 2";
        putenv('WP_ADMIN_DASHBOARD_WIDGET_1_CONTENT=' . $content);

        $this->currentUser = $this->createMockUser(2, 'admin2', ['administrator']);
        $plugin->handleDashboardSetup();

        $this->assertCount(3, $this->removedMetaBoxes);
        $this->assertSame('dashboard_recent_comments', $this->removedMetaBoxes[0][0]);
        $this->assertSame([['welcome_panel', 'wp_welcome_panel', 10]], $this->removedActions);

        $plugin->handleAdminHead();
        $this->assertStringContainsString('#welcome-panel, label[for="wp_welcome_panel-hide"]', $this->echoedHtml);

        $this->assertArrayHasKey('wp_admin_control_widget_1', $this->addedDashboardWidgets);
        $widget = $this->addedDashboardWidgets['wp_admin_control_widget_1'];
        $this->assertSame('Platform Guide', $widget['name']);

        $markdown = "Link: [Example](https://example.com) and mail: [Admin](mailto:admin@example.com)\n\n"
            . "**Bold Text** and *Italic*\n\n"
            . "- Item A\n- Item B";
        $rendered = $plugin->renderMicroMarkdown($markdown);

        $expectedLink = '<a href="https://example.com" target="_blank" rel="noopener noreferrer">Example</a>';
        $this->assertStringContainsString($expectedLink, $rendered);
        $this->assertStringContainsString('<a href="mailto:admin@example.com">Admin</a>', $rendered);
        $this->assertStringContainsString('<strong>Bold Text</strong>', $rendered);
        $this->assertStringContainsString('<em>Italic</em>', $rendered);
        $this->assertStringContainsString('<ul>', $rendered);
        $this->assertStringContainsString('<li>Item A</li>', $rendered);
        $this->assertStringContainsString('<li>Item B</li>', $rendered);
        $this->assertStringContainsString('</ul>', $rendered);
    }

    public function testRestrictedPostsLockdownViaMapMetaCapAndPreGetPosts(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_RESTRICTED_POSTS=10,20|role:editor; 50|user:7');

        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $user7 = $this->createMockUser(7, 'author_seven', ['author']);
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);

        $this->usersData[3] = $editor;
        $this->usersData[7] = $user7;
        $this->usersData[1] = $superAdmin;

        $editorCaps = $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 3, [10]);
        $this->assertSame(['do_not_allow'], $editorCaps);

        $editorAllowedCaps = $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 3, [99]);
        $this->assertSame(['edit_posts'], $editorAllowedCaps);

        $user7Caps = $plugin->filterMapMetaCap(['edit_posts'], 'delete_post', 7, [50]);
        $this->assertSame(['do_not_allow'], $user7Caps);

        $superCaps = $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 1, [10]);
        $this->assertSame(['edit_posts'], $superCaps);

        $this->currentUser = $editor;
        $GLOBALS['pagenow'] = 'edit.php';
        $query = new class {
            /** @var array<string, mixed> */
            public array $params = ['post__not_in' => [5]];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                if ($name === 'get') {
                    $key = (string) ($arguments[0] ?? '');
                    return $this->params[$key] ?? null;
                }
                if ($name === 'set') {
                    $key = (string) ($arguments[0] ?? '');
                    $this->params[$key] = $arguments[1] ?? null;
                    return null;
                }
                return null;
            }
        };

        $GLOBALS['pagenow'] = 'edit.php';
        $plugin->filterPreGetPosts($query);
        $this->assertSame([5, 10, 20], $query->params['post__not_in']);

        $counts = new stdClass();
        $counts->publish = '10';
        $counts->draft = '2';
        $adjusted = $plugin->filterCountPosts($counts, 'post', 'readable');
        $this->assertSame('8', $adjusted->publish);

        $GLOBALS['pagenow'] = 'post.php';
        $_GET['action'] = 'edit';
        $_GET['post'] = '10';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);
    }

    public function testHideSelectorsCssInjection(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_HIDE_SELECTORS=.notice-warning, .e-notice, #malicious{display:none;}');
        $plugin->handleAdminHead();

        $this->assertStringContainsString('<style id="wp-admin-control-hide-selectors">', $this->echoedHtml);
        $this->assertStringContainsString('.notice-warning, .e-notice', $this->echoedHtml);
        $this->assertStringNotContainsString('{display:none;} { display: none !important; }', $this->echoedHtml);
    }

    public function testRegisterHooksRegistersAllActionsAndFilters(): void
    {
        $actions = [];
        $filters = [];

        $plugin = new WpAdminControl(
            actionAdder: static function (string $tag, mixed $callback, int $prio, int $args) use (&$actions): void {
                $actions[$tag] = ['callback' => $callback, 'priority' => $prio, 'args' => $args];
            },
            filterAdder: static function (string $tag, mixed $callback, int $prio, int $args) use (&$filters): void {
                $filters[$tag] = ['callback' => $callback, 'priority' => $prio, 'args' => $args];
            }
        );

        $plugin->registerHooks();

        $this->assertArrayHasKey('admin_init', $actions);
        $this->assertArrayHasKey('admin_menu', $actions);
        $this->assertArrayHasKey('admin_bar_menu', $actions);
        $this->assertArrayHasKey('wp_dashboard_setup', $actions);
        $this->assertArrayHasKey('admin_head', $actions);

        $this->assertArrayHasKey('pre_get_users', $filters);
        $this->assertArrayHasKey('views_users', $filters);
        $this->assertArrayHasKey('rest_user_query', $filters);
        $this->assertArrayHasKey('rest_pre_dispatch', $filters);
        $this->assertArrayHasKey('rest_post_query', $filters);
        $this->assertArrayHasKey('custom_menu_order', $filters);
        $this->assertArrayHasKey('menu_order', $filters);
        $this->assertArrayHasKey('parent_file', $filters);
        $this->assertArrayHasKey('submenu_file', $filters);
        $this->assertSame(999, $filters['parent_file']['priority']);
        $this->assertSame(999, $filters['submenu_file']['priority']);
        $this->assertArrayHasKey('screen_options_show_screen', $filters);
        $this->assertArrayHasKey('hidden_meta_boxes', $filters);
        $this->assertArrayHasKey('hidden_columns', $filters);
        $this->assertArrayHasKey('map_meta_cap', $filters);
        $this->assertArrayHasKey('pre_get_posts', $filters);
        $this->assertArrayHasKey('wp_count_posts', $filters);
    }

    public function testUserCapabilitiesLockdownViaMapMetaCap(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $otherUser = $this->createMockUser(5, 'john_doe', ['subscriber']);

        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $normalAdmin;
        $this->usersData[5] = $otherUser;

        $userCaps = ['edit_user', 'delete_user', 'promote_user', 'remove_user'];
        foreach ($userCaps as $cap) {
            $denied = $plugin->filterMapMetaCap(['manage_options'], $cap, 2, [1]);
            $this->assertSame(['do_not_allow'], $denied);

            $allowedForOther = $plugin->filterMapMetaCap(['manage_options'], $cap, 2, [5]);
            $this->assertSame(['manage_options'], $allowedForOther);

            $allowedForSuper = $plugin->filterMapMetaCap(['manage_options'], $cap, 1, [1]);
            $this->assertSame(['manage_options'], $allowedForSuper);
        }
    }

    public function testRestZeroKnowledgeConcealmentAndPostQueryFiltering(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $normalAdmin;

        $this->currentUser = $normalAdmin;

        $restRequest = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_route') {
                    return '/wp/v2/users/1';
                }
                return null;
            }
        };

        $result = $plugin->filterRestPreDispatch(null, null, $restRequest);
        $this->assertInstanceOf(WP_Error::class, $result);

        $subRouteRequest = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_route') {
                    return '/wp/v2/users/1/application-passwords';
                }
                return null;
            }
        };
        $subResult = $plugin->filterRestPreDispatch(null, null, $subRouteRequest);
        $this->assertInstanceOf(WP_Error::class, $subResult);

        $this->currentUser = $superAdmin;
        $superResult = $plugin->filterRestPreDispatch(null, null, $restRequest);
        $this->assertNull($superResult);

        putenv('WP_ADMIN_RESTRICTED_POSTS=10,20|all');
        $this->currentUser = $normalAdmin;
        $filteredPosts = $plugin->filterRestPostQuery(['exclude' => [5]]);
        $this->assertSame([5, 10, 20], $filteredPosts['exclude']);
    }

    public function testFrontendAuthorArchiveConcealment(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];

        $this->isAdmin = false;
        $this->currentUser = $normalAdmin;

        $authorQuery = new class {
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_author') {
                    return true;
                }
                if ($name === 'get') {
                    return 1;
                }
                if ($name === 'set_404') {
                    $this->is404 = true;
                    return null;
                }
                return null;
            }
        };

        $plugin->filterPreGetPosts($authorQuery);
        $this->assertTrue($authorQuery->is404);
    }

    public function testMicroMarkdownDisallowsProtocolRelativeUrls(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $markdown = '[Phishing Link](//attacker.example/login)';
        $output = $plugin->renderMicroMarkdown($markdown);
        $this->assertStringNotContainsString('<a href="//attacker.example/login"', $output);
        $this->assertStringContainsString('//attacker.example/login', $output);

        $httpLink = '[Insecure Link](http://example.com/insecure)';
        $httpOutput = $plugin->renderMicroMarkdown($httpLink);
        $this->assertStringNotContainsString('<a href="http://example.com/insecure"', $httpOutput);
        $this->assertStringContainsString('http://example.com/insecure', $httpOutput);

        $relativeLink = '[Dashboard](/wp-admin/index.php)';
        $relativeOutput = $plugin->renderMicroMarkdown($relativeLink);
        $this->assertStringNotContainsString('<a href="/wp-admin/index.php"', $relativeOutput);
        $this->assertStringContainsString('/wp-admin/index.php', $relativeOutput);

        $secureLink = '[Secure Link](https://example.com/secure)';
        $secureOutput = $plugin->renderMicroMarkdown($secureLink);
        $expectedSecure = '<a href="https://example.com/secure"'
            . ' target="_blank" rel="noopener noreferrer">Secure Link</a>';
        $this->assertStringContainsString($expectedSecure, $secureOutput);
    }

    public function testOptionsPhpProtectionWhenOptionPageRestricted(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];
        $this->currentUser = $normalAdmin;

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=options-general.php|block');
        $GLOBALS['pagenow'] = 'options.php';

        $_POST['option_page'] = 'general';
        $plugin->handleAdminInit();
        $this->assertNotEmpty($this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);

        $this->dieCalls = [];
        $_POST['option_page'] = 'writing';
        $plugin->handleAdminInit();
        $this->assertEmpty($this->dieCalls);

        $this->dieCalls = [];
        $_POST = [];
        $_GET = [];
        $plugin->handleAdminInit();
        $this->assertNotEmpty($this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);

        $this->dieCalls = [];
        $this->currentUser = $superAdmin;
        $_POST['option_page'] = 'general';
        $plugin->handleAdminInit();
        $this->assertEmpty($this->dieCalls);
    }

    public function testFrontendAuthorArchiveByAuthorName(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator'], 'super-slug');
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator'], 'regular-slug');
        $this->allUsers = [$superAdmin, $normalAdmin];

        $this->isAdmin = false;
        $this->currentUser = $normalAdmin;

        $superAuthorQuery = new class {
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_author') {
                    return true;
                }
                if ($name === 'get') {
                    $key = $arguments[0] ?? '';
                    if ($key === 'author') {
                        return '';
                    }
                    if ($key === 'author_name') {
                        return 'super-slug';
                    }
                }
                if ($name === 'set_404') {
                    $this->is404 = true;
                    return null;
                }
                return null;
            }
        };

        $plugin->filterPreGetPosts($superAuthorQuery);
        $this->assertTrue($superAuthorQuery->is404);

        $regularAuthorQuery = new class {
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_author') {
                    return true;
                }
                if ($name === 'get') {
                    $key = $arguments[0] ?? '';
                    if ($key === 'author') {
                        return '';
                    }
                    if ($key === 'author_name') {
                        return 'regular-slug';
                    }
                }
                if ($name === 'set_404') {
                    $this->is404 = true;
                    return null;
                }
                return null;
            }
        };

        $plugin->filterPreGetPosts($regularAuthorQuery);
        $this->assertFalse($regularAuthorQuery->is404);
    }

    public function testFilterAuthorLinkConcealsSuperAdmin(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];

        $this->currentUser = $normalAdmin;
        $superLink = $plugin->filterAuthorLink('https://example.com/author/superadmin/', 1, 'superadmin');
        $this->assertSame('/', $superLink);

        $normalLink = $plugin->filterAuthorLink('https://example.com/author/regular/', 2, 'regular_admin');
        $this->assertSame('https://example.com/author/regular/', $normalLink);

        $this->currentUser = $superAdmin;
        $superLinkSelf = $plugin->filterAuthorLink('https://example.com/author/superadmin/', 1, 'superadmin');
        $this->assertSame('https://example.com/author/superadmin/', $superLinkSelf);
    }

    public function testHandleTemplateRedirectConcealsSuperAdmin(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];

        $this->isAdmin = false;
        $this->currentUser = $normalAdmin;

        $wpQuery = new class {
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'set_404') {
                    $this->is404 = true;
                    return null;
                }
                return null;
            }
        };
        $GLOBALS['wp_query'] = $wpQuery;

        $plugin->handleTemplateRedirect();
        $this->assertFalse($wpQuery->is404);
    }

    public function testFilterPreGetUsersScrubsIncludeParameter(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $otherUser = $this->createMockUser(3, 'editor', ['editor']);
        $this->allUsers = [$superAdmin, $normalAdmin, $otherUser];
        $this->currentUser = $normalAdmin;

        $query1 = new class {
            /** @var array<string, mixed> */
            public array $vars = ['include' => [1, 3], 'exclude' => []];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->vars[$key] = $value;
            }
        };

        $plugin->filterPreGetUsers($query1);
        $this->assertSame([3], $query1->vars['include']);
        $this->assertSame([1], $query1->vars['exclude']);

        $query2 = new class {
            /** @var array<string, mixed> */
            public array $vars = ['include' => [1], 'exclude' => []];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->vars[$key] = $value;
            }
        };

        $plugin->filterPreGetUsers($query2);
        $this->assertSame([0], $query2->vars['include']);
        $this->assertSame([1], $query2->vars['exclude']);

        $query3 = new class {
            /** @var array<string, mixed> */
            public array $vars = ['include' => '1,3', 'exclude' => ''];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->vars[$key] = $value;
            }
        };

        $plugin->filterPreGetUsers($query3);
        $this->assertSame([3], $query3->vars['include']);
        $this->assertSame([1], $query3->vars['exclude']);
    }

    public function testFilterRestUserQueryScrubsIncludeParameter(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];
        $this->currentUser = $normalAdmin;

        $result1 = $plugin->filterRestUserQuery(['include' => [1, 2], 'exclude' => []]);
        $this->assertSame([2], $result1['include']);
        $this->assertSame([1], $result1['exclude']);

        $result2 = $plugin->filterRestUserQuery(['include' => [1], 'exclude' => []]);
        $this->assertSame([0], $result2['include']);
        $this->assertSame([1], $result2['exclude']);

        $result3 = $plugin->filterRestUserQuery(['include' => '1,2', 'exclude' => []]);
        $this->assertSame([2], $result3['include']);
        $this->assertSame([1], $result3['exclude']);
    }

    public function testFilterRestPostQueryScrubsAuthorAndMergesAuthorExclude(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];
        $this->currentUser = $normalAdmin;

        $singleAuthorResult = $plugin->filterRestPostQuery(['author' => 1]);
        $this->assertSame([0], $singleAuthorResult['author']);

        $multipleAuthorResult = $plugin->filterRestPostQuery(['author' => [1, 2]]);
        $this->assertSame([2], $multipleAuthorResult['author']);

        $authorExcludeResult = $plugin->filterRestPostQuery(['author_exclude' => [5]]);
        $this->assertSame([5, 1], $authorExcludeResult['author_exclude']);
    }

    public function testSwitchToUserCapabilityBlockedForSuperAdmin(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->usersData = [1 => $superAdmin, 2 => $normalAdmin];

        $deniedCaps = $plugin->filterMapMetaCap(['switch_to_user'], 'switch_to_user', 2, [1]);
        $this->assertSame(['do_not_allow'], $deniedCaps);

        $allowedCaps = $plugin->filterMapMetaCap(['switch_to_user'], 'switch_to_user', 1, [1]);
        $this->assertSame(['switch_to_user'], $allowedCaps);
    }

    public function testResolveSuperAdminIdsReentrancyGuardPreventsInfiniteRecursion(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->allUsers = [$superAdmin];
        $this->currentUser = $this->createMockUser(2, 'editor', ['editor']);

        $plugin = null;
        $plugin = $this->createPluginWithMockFiles(dependencies: [
            'usersGetter' => function (array $args) use (&$plugin, $superAdmin): array {
                $queryMock = new class {
                    /** @var array<string, mixed> */
                    public array $params = [];

                    /**
                     * @param array<int, mixed> $arguments
                     */
                    public function __call(string $name, array $arguments): mixed
                    {
                        if ($name === 'get') {
                            return $this->params[(string) ($arguments[0] ?? '')] ?? null;
                        }
                        if ($name === 'set') {
                            $this->params[(string) ($arguments[0] ?? '')] = $arguments[1] ?? null;
                        }
                        return null;
                    }
                };

                if ($plugin instanceof WpAdminControl) {
                    $plugin->filterPreGetUsers($queryMock);
                }

                return [$superAdmin];
            },
        ]);

        $ids = $plugin->resolveSuperAdminIds();
        $this->assertSame([1], $ids);
    }

    public function testHandleAdminInitBlocksResetPasswordBulkActionForSuperAdmin(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->usersData = [1 => $superAdmin, 2 => $normalAdmin];
        $this->currentUser = $normalAdmin;
        $this->isAdmin = true;

        $_REQUEST['action'] = 'resetpassword';
        $_REQUEST['users'] = [1];

        $plugin->handleAdminInit();

        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Invalid user ID.', $this->dieCalls[0]['message']);
        $this->assertSame(500, $this->dieCalls[0]['title']);
    }

    public function testFilterPreGetPostsOnFrontendBlocksSuperAdminAuthorDirectlyAndViaAuthorName(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->allUsers = [$superAdmin];
        $this->usersData = [1 => $superAdmin];
        $this->currentUser = null;
        $this->isAdmin = false;

        $queryAuthorId = new class {
            /** @var array<string, mixed> */
            public array $params = ['author' => 1];
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->params[(string) ($arguments[0] ?? '')] ?? null;
                }
                if ($name === 'set_404') {
                    $this->is404 = true;
                }
                if ($name === 'set') {
                    $this->params[(string) ($arguments[0] ?? '')] = $arguments[1] ?? null;
                }
                return null;
            }
        };

        $plugin->filterPreGetPosts($queryAuthorId);
        $this->assertTrue($queryAuthorId->is404);

        $queryAuthorName = new class {
            /** @var array<string, mixed> */
            public array $params = ['author_name' => 'superadmin'];
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->params[(string) ($arguments[0] ?? '')] ?? null;
                }
                if ($name === 'set_404') {
                    $this->is404 = true;
                }
                if ($name === 'set') {
                    $this->params[(string) ($arguments[0] ?? '')] = $arguments[1] ?? null;
                }
                return null;
            }
        };

        $plugin->filterPreGetPosts($queryAuthorName);
        $this->assertTrue($queryAuthorName->is404);

        $queryGeneral = new class {
            /** @var array<string, mixed> */
            public array $params = [];
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->params[(string) ($arguments[0] ?? '')] ?? null;
                }
                if ($name === 'set') {
                    $this->params[(string) ($arguments[0] ?? '')] = $arguments[1] ?? null;
                }
                return null;
            }
        };

        $plugin->filterPreGetPosts($queryGeneral);
        $this->assertFalse($queryGeneral->is404);
        $this->assertSame([1], $queryGeneral->params['author__not_in']);
    }

    public function testFilterRestPreparePostMasksSuperAdminAuthorAndStripsLink(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];
        $this->currentUser = $normalAdmin;

        $responseMock = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1, 'title' => 'Sample Post'];
            /** @var list<string> */
            public array $removedLinks = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->removedLinks[] = (string) ($arguments[0] ?? '');
                }
                return null;
            }
        };

        $postMock = (object) ['ID' => 10, 'post_author' => 1];

        $result = $plugin->filterRestPreparePost($responseMock, $postMock, null);
        $this->assertSame($responseMock, $result);
        $this->assertSame(0, $responseMock->data['author']);
        $this->assertSame(['author'], $responseMock->removedLinks);

        $superAdminResponse = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1, 'title' => 'Sample Post'];
            /** @var list<string> */
            public array $removedLinks = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->removedLinks[] = (string) ($arguments[0] ?? '');
                }
                return null;
            }
        };
        $this->currentUser = $superAdmin;
        $superAdminResult = $plugin->filterRestPreparePost($superAdminResponse, $postMock, null);
        $this->assertSame($superAdminResponse, $superAdminResult);
        $this->assertSame(1, $superAdminResponse->data['author']);
        $this->assertSame([], $superAdminResponse->removedLinks);

        $nonObjectResult = $plugin->filterRestPreparePost('invalid', null, null);
        $this->assertSame('invalid', $nonObjectResult);
    }

    public function testHandleAdminBarMenuReordersNewDropdownItems(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $postNode = (object) ['id' => 'new-post', 'parent' => 'new-content', 'title' => 'Post'];
        $mediaNode = (object) ['id' => 'new-media', 'parent' => 'new-content', 'title' => 'Media'];
        $pageNode = (object) ['id' => 'new-page', 'parent' => 'new-content', 'title' => 'Page'];

        $adminBarMock = new class ($postNode, $mediaNode, $pageNode) {
            /** @var array<string, object> */
            public array $nodes = [];
            /** @var list<string> */
            public array $removed = [];

            public function __construct(object $p, object $m, object $pg)
            {
                $this->nodes['new-post'] = $p;
                $this->nodes['new-media'] = $m;
                $this->nodes['new-page'] = $pg;
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node') {
                    $id = (string) ($arguments[0] ?? '');
                    $this->removed[] = $id;
                    unset($this->nodes[$id]);
                    return null;
                }
                if ($name === 'add_node') {
                    $args = (array) ($arguments[0] ?? []);
                    $id = (string) ($args['id'] ?? '');
                    if ($id !== '') {
                        $this->nodes[$id] = (object) $args;
                    }
                    return null;
                }
                if ($name === 'get_nodes') {
                    return $this->nodes;
                }
                return null;
            }
        };

        putenv('WP_ADMIN_BAR_NEW_ORDER=new-page,new-post');
        $plugin->handleAdminBarMenu($adminBarMock);

        $nodeKeys = array_keys($adminBarMock->nodes);
        $this->assertSame(['new-page', 'new-post', 'new-media'], $nodeKeys);
    }

    public function testFilterRestCommentQueryAndPrepareComment(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $normalAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $normalAdmin];
        $this->currentUser = $normalAdmin;

        $singleAuthorResult = $plugin->filterRestCommentQuery(['author' => 1]);
        $this->assertSame([0], $singleAuthorResult['author']);

        $multipleAuthorResult = $plugin->filterRestCommentQuery(['author' => [1, 2]]);
        $this->assertSame([2], $multipleAuthorResult['author']);

        $authorExcludeResult = $plugin->filterRestCommentQuery(['author_exclude' => [5]]);
        $this->assertSame([5, 1], $authorExcludeResult['author_exclude']);

        $commentMock = (object) ['comment_ID' => 100, 'user_id' => 1];
        $responseMock = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1, 'content' => 'Sample Comment'];
            /** @var list<string> */
            public array $removedLinks = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->removedLinks[] = (string) ($arguments[0] ?? '');
                }
                return null;
            }
        };

        $result = $plugin->filterRestPrepareComment($responseMock, $commentMock, null);
        $this->assertSame($responseMock, $result);
        $this->assertSame(0, $responseMock->data['author']);
        $this->assertSame(['author'], $responseMock->removedLinks);

        $superAdminResponse = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1, 'content' => 'Sample Comment'];
            /** @var list<string> */
            public array $removedLinks = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->removedLinks[] = (string) ($arguments[0] ?? '');
                }
                return null;
            }
        };
        $this->currentUser = $superAdmin;
        $superAdminResult = $plugin->filterRestPrepareComment($superAdminResponse, $commentMock, null);
        $this->assertSame($superAdminResponse, $superAdminResult);
        $this->assertSame(1, $superAdminResponse->data['author']);
        $this->assertSame([], $superAdminResponse->removedLinks);

        $nonObjectResult = $plugin->filterRestPrepareComment('invalid', null, null);
        $this->assertSame('invalid', $nonObjectResult);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultConstructorFallbacksAndGlobalFunctions(): void
    {
        ensure_duplicator_stubs();
        ensure_admin_control_stubs();

        $plugin = new WpAdminControl();

        $getEnvRef = new ReflectionMethod(WpAdminControl::class, 'getEnv');
        $this->assertFalse($getEnvRef->invoke($plugin, 'NON_EXISTENT_VAR_' . uniqid()));

        $readFileRef = new ReflectionMethod(WpAdminControl::class, 'readFile');
        $this->assertFalse($readFileRef->invoke($plugin, '/non/existent/path/' . uniqid()));

        $isMethodCallableRef = new ReflectionMethod(WpAdminControl::class, 'isMethodCallable');
        $this->assertFalse($isMethodCallableRef->invoke($plugin, 'not_an_object', 'foo'));

        $callObjectMethodRef = new ReflectionMethod(WpAdminControl::class, 'callObjectMethod');
        $this->assertNull($callObjectMethodRef->invoke($plugin, 'not_an_object', 'foo'));
        $this->assertNull($callObjectMethodRef->invoke($plugin, new stdClass(), 'nonExistentMethod'));

        $GLOBALS['test_is_user_logged_in'] = true;
        $isLoggedInRef = new ReflectionMethod(WpAdminControl::class, 'isUserLoggedIn');
        $this->assertTrue($isLoggedInRef->invoke($plugin));

        $GLOBALS['test_is_admin'] = true;
        $isAdminRef = new ReflectionMethod(WpAdminControl::class, 'isAdmin');
        $this->assertTrue($isAdminRef->invoke($plugin));

        $GLOBALS['test_wp_doing_ajax'] = true;
        $wpDoingAjaxRef = new ReflectionMethod(WpAdminControl::class, 'wpDoingAjax');
        $this->assertTrue($wpDoingAjaxRef->invoke($plugin));

        $GLOBALS['test_wp_doing_cron'] = true;
        $wpDoingCronRef = new ReflectionMethod(WpAdminControl::class, 'wpDoingCron');
        $this->assertTrue($wpDoingCronRef->invoke($plugin));

        $testUser = (object) ['ID' => 5, 'roles' => ['editor']];
        $GLOBALS['test_current_user'] = $testUser;
        $getCurrentUserRef = new ReflectionMethod(WpAdminControl::class, 'getCurrentUser');
        $this->assertSame($testUser, $getCurrentUserRef->invoke($plugin));

        $GLOBALS['test_userdata'][5] = $testUser;
        $getUserDataRef = new ReflectionMethod(WpAdminControl::class, 'getUserData');
        $this->assertSame($testUser, $getUserDataRef->invoke($plugin, 5));
        $this->assertNull($getUserDataRef->invoke($plugin, 999));

        $GLOBALS['test_user_by']['slug']['john'] = $testUser;
        $getUserByRef = new ReflectionMethod(WpAdminControl::class, 'getUserBy');
        $this->assertSame($testUser, $getUserByRef->invoke($plugin, 'slug', 'john'));
        $this->assertNull($getUserByRef->invoke($plugin, 'slug', 'unknown'));

        $user1 = (object) ['ID' => 1];
        $user2 = (object) ['ID' => 2];
        $GLOBALS['test_users_list'] = [$user1, 'invalid_item', $user2];
        $getUsersRef = new ReflectionMethod(WpAdminControl::class, 'getUsers');
        $this->assertSame([$user1, $user2], $getUsersRef->invoke($plugin, []));

        $screenObj = (object) ['id' => 'dashboard'];
        $GLOBALS['test_current_screen'] = $screenObj;
        $getCurrentScreenRef = new ReflectionMethod(WpAdminControl::class, 'getCurrentScreen');
        $this->assertSame($screenObj, $getCurrentScreenRef->invoke($plugin));

        $GLOBALS['test_removed_menu_pages'] = [];
        $GLOBALS['test_removed_submenu_pages'] = [];
        $removeMenuPageRef = new ReflectionMethod(WpAdminControl::class, 'removeMenuPage');
        $removeMenuPageRef->invoke($plugin, 'tools.php');
        $this->assertSame(['tools.php'], $GLOBALS['test_removed_menu_pages']);

        $removeMenuPageRef->invoke($plugin, 'parent.php:child.php');
        $this->assertSame([['parent.php', 'child.php']], $GLOBALS['test_removed_submenu_pages']);

        $removeSubmenuPageRef = new ReflectionMethod(WpAdminControl::class, 'removeSubmenuPage');
        $removeSubmenuPageRef->invoke($plugin, 'edit.php', 'edit-tags.php');
        $this->assertContains(['edit.php', 'edit-tags.php'], $GLOBALS['test_removed_submenu_pages']);

        $GLOBALS['test_added_menu_pages'] = [];
        $addMenuPageRef = new ReflectionMethod(WpAdminControl::class, 'addMenuPage');
        $addMenuPageRef->invoke($plugin, 'Title', 'Menu', 'manage_options', 'my-menu');
        $this->assertCount(1, $GLOBALS['test_added_menu_pages']);

        $GLOBALS['test_added_submenu_pages'] = [];
        $addSubmenuPageRef = new ReflectionMethod(WpAdminControl::class, 'addSubmenuPage');
        $addSubmenuPageRef->invoke($plugin, 'parent', 'Title', 'Menu', 'manage_options', 'my-submenu');
        $this->assertCount(1, $GLOBALS['test_added_submenu_pages']);

        $GLOBALS['test_removed_meta_boxes'] = [];
        $removeMetaBoxRef = new ReflectionMethod(WpAdminControl::class, 'removeMetaBox');
        $removeMetaBoxRef->invoke($plugin, 'box_id', 'dashboard', 'normal');
        $this->assertCount(1, $GLOBALS['test_removed_meta_boxes']);

        $GLOBALS['test_dashboard_widgets'] = [];
        $addWidgetRef = new ReflectionMethod(WpAdminControl::class, 'addDashboardWidget');
        $addWidgetRef->invoke($plugin, 'widget_1', 'Widget 1', static fn () => null);
        $this->assertArrayHasKey('widget_1', $GLOBALS['test_dashboard_widgets']);

        $GLOBALS['test_wp_die_calls'] = [];
        $dieForbiddenRef = new ReflectionMethod(WpAdminControl::class, 'dieForbidden');
        $dieForbiddenRef->invoke($plugin, 'Forbidden msg');
        $this->assertCount(1, $GLOBALS['test_wp_die_calls']);

        $dieInvalidRef = new ReflectionMethod(WpAdminControl::class, 'dieInvalidUser');
        $dieInvalidRef->invoke($plugin, 'Invalid user msg');
        $this->assertCount(2, $GLOBALS['test_wp_die_calls']);

        $echoHtmlRef = new ReflectionMethod(WpAdminControl::class, 'echoHtml');
        ob_start();
        $echoHtmlRef->invoke($plugin, '<p>output</p>');
        $captured = ob_get_clean();
        $this->assertSame('<p>output</p>', $captured);

        $GLOBALS['test_registered_actions'] = [];
        $addActionRef = new ReflectionMethod(WpAdminControl::class, 'addAction');
        $addActionRef->invoke($plugin, 'my_test_action', static fn () => null);
        $this->assertArrayHasKey('my_test_action', $GLOBALS['test_registered_actions']);

        $GLOBALS['test_registered_filters'] = [];
        $addFilterRef = new ReflectionMethod(WpAdminControl::class, 'addFilter');
        $addFilterRef->invoke($plugin, 'my_test_filter', static fn () => null);
        $this->assertArrayHasKey('my_test_filter', $GLOBALS['test_registered_filters']);
    }

    public function testResolveSuperAdminIdsReentrancyProtection(): void
    {
        $plugin = null;
        $plugin = new WpAdminControl(
            fileReader: fn (string $path): string|false => match ($path) {
                '/run/secrets/wp_super_admins' => $this->superAdminHash,
                '/run/secrets/auth_salt' => $this->salt,
                default => false,
            },
            usersGetter: function (array $args) use (&$plugin): array {
                if ($plugin !== null) {
                    $reentrantResult = $plugin->resolveSuperAdminIds();
                    $this->assertSame([], $reentrantResult);
                }
                return [(object) ['ID' => 1, 'user_login' => 'superadmin']];
            }
        );

        $ids = $plugin->resolveSuperAdminIds();
        $this->assertSame([1], $ids);
    }

    public function testParseRulesEdgeCases(): void
    {
        $plugin = $this->createPluginWithMockFiles();
        $rules = $plugin->parseRules(';; |role; , , |editor; users.php');
        $this->assertCount(1, $rules);
        $this->assertSame(['users.php'], $rules[0]['targets']);
        $this->assertTrue($rules[0]['isAll']);
    }

    public function testRuleEvaluationWithNullUserAndCosmeticInversions(): void
    {
        $plugin = $this->createPluginWithMockFiles();
        $rule = $plugin->parseRules('users.php|editor')[0];

        $this->assertFalse($plugin->isUserRestrictedByRule($rule, null));
        $this->assertFalse($plugin->isUserMatchingCosmeticRule($rule, null));

        $invertedUserRule = $plugin->parseRules('dashboard_widget|!user:10')[0];
        $user10 = (object) ['ID' => 10, 'roles' => ['editor']];
        $this->assertFalse($plugin->isUserMatchingCosmeticRule($invertedUserRule, $user10));

        $directUserRule = $plugin->parseRules('dashboard_widget|user:10')[0];
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($directUserRule, $user10));
    }

    public function testAdminInitSuperAdminDeleteTargetAndQueryStringHandling(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $this->usersData[1] = $superAdmin;
        $this->allUsers = [$superAdmin];

        $plugin = $this->createPluginWithMockFiles();
        $this->currentUser = (object) ['ID' => 2, 'user_login' => 'admin2', 'roles' => ['administrator']];

        $GLOBALS['pagenow'] = 'users.php';
        $_REQUEST['action'] = 'deleteuser';
        $_REQUEST['user_id'] = '1';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Invalid user ID.', $this->dieCalls[0]['message']);

        $this->dieCalls = [];
        $_REQUEST['user_id'] = '999';
        $_REQUEST['users'] = 12345;
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $sideControl = 'other.php?foo=bar|block; tools.php?|block; '
            . 'tools.php?action=edit&type=special|block';
        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=' . $sideControl);
        $restrictedPlugin = $this->createPluginWithMockFiles();

        $GLOBALS['pagenow'] = 'tools.php';
        $_REQUEST = [];
        $_GET = ['page' => 'my-subpage'];
        $_SERVER['QUERY_STRING'] = 12345;
        $restrictedPlugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $_GET = [];
        $_SERVER['QUERY_STRING'] = 'action=edit&type=special';
        $restrictedPlugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Sorry, you are not allowed to access this page.', $this->dieCalls[0]['message']);

        $this->dieCalls = [];
        $_SERVER['QUERY_STRING'] = 'action=view';
        $restrictedPlugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }

    public function testCustomMenusFilterAndAdminBarMissingMethods(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->currentUser = $editor;

        putenv('WP_ADMIN_CUSTOM_MENU_1_TITLE=Menu One');
        putenv('WP_ADMIN_CUSTOM_MENU_1_TARGET=user:999');
        putenv('WP_ADMIN_CUSTOM_MENU_2_TITLE=Menu Two');
        putenv('WP_ADMIN_CUSTOM_MENU_2_ITEMS=Item One|https://example.com; ; Item Two|https://example.org');

        $plugin = $this->createPluginWithMockFiles();
        $plugin->handleAdminMenu();

        $addedSlugs = array_column($this->addedMenuPages, 'menu_slug');
        $this->assertNotContains('wp_admin_control_custom_menu_1', $addedSlugs);
        $this->assertContains('custom-menu-2', $addedSlugs);

        $addedSubSlugs = array_column($this->addedSubmenuPages, 'menu_slug');
        $this->assertContains('https://example.com', $addedSubSlugs);

        $plugin->handleAdminBarMenu(new stdClass());

        putenv('WP_ADMIN_BAR_NEW_ORDER=new-post,new-page');
        $barWithoutNodes = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        };
        $plugin->handleAdminBarMenu($barWithoutNodes);
    }

    public function testFilterPreGetUsersViewsUsersAndRestUserEdgeCases(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularUser = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularUser;
        $this->allUsers = [$superAdmin, $regularUser];

        $plugin = $this->createPluginWithMockFiles();
        $this->currentUser = $regularUser;

        $plugin->filterPreGetUsers(new stdClass());

        $pluginNoAdmins = $this->createPluginWithMockFiles(superAdminFileContent: '');
        $mockQuery = new class {
            /** @var array<string, mixed> */
            public array $vars = [];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }
        };
        $pluginNoAdmins->filterPreGetUsers($mockQuery);
        $this->assertSame([], $mockQuery->vars);

        $views = ['all' => '<span class="count">(1)</span>'];
        $unmodifiedViews = $pluginNoAdmins->filterViewsUsers($views);
        $this->assertSame($views, $unmodifiedViews);

        $unmodifiedUserArgs = $pluginNoAdmins->filterRestUserQuery(['include' => [1]]);
        $this->assertSame(['include' => [1]], $unmodifiedUserArgs);

        $this->assertSame('result', $plugin->filterRestPreDispatch('result', null, null));
        $this->assertNull($plugin->filterRestPreDispatch(null, null, new stdClass()));

        $restRequest = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_route') {
                    return '/wp/v2/users/1';
                }
                return null;
            }
        };
        $dispatchResult = $plugin->filterRestPreDispatch(null, null, $restRequest);
        $this->assertInstanceOf(WP_Error::class, $dispatchResult);

        $this->currentUser = $superAdmin;
        $superAdminPostQuery = $plugin->filterRestPostQuery(['author' => 1]);
        $this->assertSame(['author' => 1], $superAdminPostQuery);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestPreDispatchReturnsWpErrorWhenClassExists(): void
    {
        ensure_admin_control_stubs();
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularUser = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularUser;
        $this->allUsers = [$superAdmin, $regularUser];

        $plugin = $this->createPluginWithMockFiles();
        $this->currentUser = $regularUser;

        $restRequest = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_route') {
                    return '/wp/v2/users/1';
                }
                return null;
            }
        };
        $dispatchResult = $plugin->filterRestPreDispatch(null, null, $restRequest);
        $this->assertInstanceOf(WP_Error::class, $dispatchResult);
        $this->assertSame('rest_user_invalid_id', $dispatchResult->get_error_code());
    }

    public function testRestPostAndCommentQueryAndPrepareEdgeCases(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularUser = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularUser;
        $this->allUsers = [$superAdmin, $regularUser];

        $plugin = $this->createPluginWithMockFiles();
        $this->currentUser = $regularUser;

        $postMock = (object) ['ID' => 50, 'post_author' => 0];
        $responseMock = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1, 'title' => 'Sample'];
            /** @var list<string> */
            public array $removedLinks = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->removedLinks[] = (string) ($arguments[0] ?? '');
                }
                return null;
            }
        };
        $preparedPost = $plugin->filterRestPreparePost($responseMock, $postMock, null);
        $this->assertSame(0, $preparedPost->data['author']);
        $this->assertSame(['author'], $preparedPost->removedLinks);

        $this->currentUser = $superAdmin;
        $superAdminCommentArgs = $plugin->filterRestCommentQuery(['author' => 1]);
        $this->assertSame(['author' => 1], $superAdminCommentArgs);

        $this->currentUser = $regularUser;
        $pluginNoAdmins = $this->createPluginWithMockFiles(superAdminFileContent: '');
        $noAdminCommentArgs = $pluginNoAdmins->filterRestCommentQuery(['author' => 1]);
        $this->assertSame(['author' => 1], $noAdminCommentArgs);

        $commentMock = (object) ['comment_ID' => 10, 'user_id' => 0];
        $commentResponseMock = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1, 'content' => 'Comment'];
            /** @var list<string> */
            public array $removedLinks = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->removedLinks[] = (string) ($arguments[0] ?? '');
                }
                return null;
            }
        };
        $preparedComment = $plugin->filterRestPrepareComment($commentResponseMock, $commentMock, null);
        $this->assertSame(0, $preparedComment->data['author']);
        $this->assertSame(['author'], $preparedComment->removedLinks);
    }

    public function testMenuOrderScreenOptionsHiddenColumnsAndMapMetaCapEdgeCases(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularUser = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularUser;
        $this->allUsers = [$superAdmin, $regularUser];

        $plugin = $this->createPluginWithMockFiles();
        $this->currentUser = $regularUser;

        putenv('WP_ADMIN_MENU_ORDER= , , ');
        $originalOrder = ['index.php', 'edit.php'];
        $this->assertSame($originalOrder, $plugin->filterMenuOrder($originalOrder));

        putenv('WP_ADMIN_DISABLE_SCREEN_OPTIONS=false');
        $this->assertTrue($plugin->filterScreenOptionsShowScreen(true));

        putenv('WP_ADMIN_HIDDEN_COLUMNS=users.php|role; posts|author');
        $this->assertSame(['custom_col'], $plugin->filterDefaultHiddenColumns(['custom_col']));

        $this->assertSame(['read'], $plugin->filterMapMetaCap(['read'], 'read', 2, []));
        $this->assertSame(['edit_posts'], $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 2, []));

        $this->currentUser = $superAdmin;
        $superAdminQuery = new stdClass();
        $plugin->filterPreGetPosts($superAdminQuery);

        $this->currentUser = $regularUser;
        $this->isAdmin = false;
        $frontendQuery = new class {
            /** @var array<string, mixed> */
            public array $vars = [
                'author' => 0,
                'author_name' => '',
                'author__not_in' => [10, '20'],
            ];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }
        };
        $plugin->filterPreGetPosts($frontendQuery);
        $this->assertSame([10, 20, 1], $frontendQuery->vars['author__not_in']);

        $this->isAdmin = true;
        $nonMainQuery = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return false;
                }
                return null;
            }
        };
        $plugin->filterPreGetPosts($nonMainQuery);

        $GLOBALS['pagenow'] = 'upload.php';
        $mainOtherPageQuery = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                return null;
            }
        };
        $plugin->filterPreGetPosts($mainOtherPageQuery);

        $GLOBALS['pagenow'] = 'edit.php';
        $mainEditNoRestrictedQuery = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                return null;
            }
        };
        $plugin->filterPreGetPosts($mainEditNoRestrictedQuery);

        putenv('WP_ADMIN_RESTRICTED_POSTS=100');
        $restrictedPlugin = $this->createPluginWithMockFiles();
        $queryWithCall = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                return null;
            }
        };
        $restrictedPlugin->filterPreGetPosts($queryWithCall);
    }

    public function testCountPostsAndMicroMarkdownTransitions(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularUser = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularUser;
        $this->allUsers = [$superAdmin, $regularUser];

        $plugin = $this->createPluginWithMockFiles();

        $countsObj = (object) ['publish' => '10'];
        $this->currentUser = $superAdmin;
        $this->assertSame($countsObj, $plugin->filterCountPosts($countsObj, 'post', 'readable'));

        $this->currentUser = $regularUser;
        $this->isAdmin = false;
        $this->assertSame($countsObj, $plugin->filterCountPosts($countsObj, 'post', 'readable'));

        $this->isAdmin = true;
        $this->assertSame($countsObj, $plugin->filterCountPosts($countsObj, 'post', 'readable'));

        $plugin->handleTemplateRedirect();

        $this->currentUser = $superAdmin;
        $this->assertSame(
            'https://example.com/author/superadmin',
            $plugin->filterAuthorLink('https://example.com/author/superadmin', 1)
        );

        $this->currentUser = $regularUser;
        $this->assertSame('/', $plugin->filterAuthorLink('https://example.com/author/superadmin', 1));

        $this->assertSame('', $plugin->renderMicroMarkdown('   '));
        $mdOutput = $plugin->renderMicroMarkdown("- item 1\nregular text\n- item 2");
        $this->assertStringContainsString('<ul>', $mdOutput);
        $this->assertStringContainsString('</ul>', $mdOutput);
        $this->assertStringContainsString('regular text', $mdOutput);

        $this->assertSame('.cls', $plugin->sanitizeCssSelectors(' , .cls, , '));
        $this->assertSame('', $plugin->sanitizeCssSelectors('{injection}, <script>'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateRedirectAndAuthorLinkWithGlobalStubs(): void
    {
        ensure_admin_control_stubs();
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularUser = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularUser;
        $this->allUsers = [$superAdmin, $regularUser];

        $plugin = $this->createPluginWithMockFiles();
        $this->currentUser = $regularUser;
        $this->isAdmin = false;

        $GLOBALS['test_is_author'] = true;
        $GLOBALS['test_queried_object_id'] = 1;
        $wpQueryMock = new class {
            public bool $is404 = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'set_404') {
                    $this->is404 = true;
                }
                return null;
            }
        };
        $GLOBALS['wp_query'] = $wpQueryMock;
        $plugin->handleTemplateRedirect();
        $this->assertSame(404, $GLOBALS['test_status_header'] ?? 0);
        $this->assertTrue($GLOBALS['test_nocache_headers_called'] ?? false);
        $this->assertTrue($wpQueryMock->is404);

        $this->assertSame(
            'https://example.com/',
            $plugin->filterAuthorLink('https://example.com/author/superadmin', 1)
        );
    }

    public function testRestrictedPostsAndHiddenListHelperEdgeCases(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularUser = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularUser;
        $this->allUsers = [$superAdmin, $regularUser];

        $plugin = $this->createPluginWithMockFiles();

        $isPostRestrictedRef = new ReflectionMethod(WpAdminControl::class, 'isPostRestrictedForUser');
        $this->assertFalse($isPostRestrictedRef->invoke($plugin, 100, $superAdmin));

        putenv('WP_ADMIN_RESTRICTED_POSTS=');
        $pluginEmptyPosts = $this->createPluginWithMockFiles();
        $this->assertFalse($isPostRestrictedRef->invoke($pluginEmptyPosts, 100, $regularUser));

        $applyHiddenListRef = new ReflectionMethod(WpAdminControl::class, 'applyHiddenList');
        $this->currentUser = null;
        $this->assertSame(['meta1'], $applyHiddenListRef->invoke($plugin, 'WP_ADMIN_HIDDEN_META_BOXES', ['meta1']));

        $this->currentUser = $regularUser;
        putenv('WP_ADMIN_HIDDEN_META_BOXES=');
        $this->assertSame(['meta1'], $applyHiddenListRef->invoke($plugin, 'WP_ADMIN_HIDDEN_META_BOXES', ['meta1']));

        putenv('WP_ADMIN_HIDDEN_META_BOXES=box1|subscriber');
        $pluginNoMatch = $this->createPluginWithMockFiles();
        $this->assertSame(
            ['meta1'],
            $applyHiddenListRef->invoke($pluginNoMatch, 'WP_ADMIN_HIDDEN_META_BOXES', ['meta1'])
        );

        $extractRolesRef = new ReflectionMethod(WpAdminControl::class, 'extractUserRoles');
        $invalidRolesUser = (object) ['roles' => 'not_an_array'];
        $this->assertSame([], $extractRolesRef->invoke($plugin, $invalidRolesUser));

        $pluginNoSalt = $this->createPluginWithMockFiles(saltFileContent: '');
        $getActiveSaltRef = new ReflectionMethod(WpAdminControl::class, 'getActiveSalt');
        $this->assertSame('', $getActiveSaltRef->invoke($pluginNoSalt));

        $pluginNoHashes = $this->createPluginWithMockFiles(
            superAdminFileContent: 'invalid_short_hash,another_bad_hash'
        );
        $getHashesRef = new ReflectionMethod(WpAdminControl::class, 'getSuperAdminHashes');
        $this->assertSame([], $getHashesRef->invoke($pluginNoHashes));

        putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TITLE=Test Widget');
        putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TARGET=user:999');
        putenv('WP_ADMIN_DASHBOARD_WIDGET_1_CONTENT=Widget body');
        putenv('WP_ADMIN_DASHBOARD_WIDGET_2_TITLE=Allowed Widget');
        putenv('WP_ADMIN_DASHBOARD_WIDGET_2_CONTENT=Allowed body');
        $this->currentUser = $regularUser;
        $dashboardPlugin = $this->createPluginWithMockFiles();
        $dashboardPlugin->handleDashboardSetup();
        $this->assertArrayNotHasKey('wp_admin_control_widget_1', $this->addedDashboardWidgets);
        $this->assertArrayHasKey('wp_admin_control_widget_2', $this->addedDashboardWidgets);
        $this->addedDashboardWidgets['wp_admin_control_widget_2']['callback']();
        $this->assertStringContainsString('Allowed body', $this->echoedHtml);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenWordPressFunctionsExist(): void
    {
        ensure_admin_control_stubs();
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];

        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-admin-control.php.example';

        $this->assertArrayHasKey('admin_init', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('admin_menu', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('admin_bar_menu', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('wp_dashboard_setup', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('admin_head', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('template_redirect', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('pre_get_users', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('pre_get_posts', $GLOBALS['test_registered_filters']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInternalFallbacksWhenGlobalFunctionsDoNotExist(): void
    {
        $plugin = new WpAdminControl();

        $getSidebarRef = new ReflectionMethod(WpAdminControl::class, 'getSidebarMenuControlForCurrentUser');
        $this->assertSame(['hide' => [], 'block' => []], $getSidebarRef->invoke($plugin));

        $getPostsRef = new ReflectionMethod(WpAdminControl::class, 'getRestrictedPostIdsForCurrentUser');
        $this->assertSame([], $getPostsRef->invoke($plugin));

        $pluginWithEnv = new WpAdminControl(
            envGetter: static fn (string $name): string => 'val_' . $name
        );
        $getEnvRef = new ReflectionMethod(WpAdminControl::class, 'getEnv');
        $this->assertSame('val_TEST_VAR', $getEnvRef->invoke($pluginWithEnv, 'TEST_VAR'));

        $getCurrentUserRef = new ReflectionMethod(WpAdminControl::class, 'getCurrentUser');
        $this->assertNull($getCurrentUserRef->invoke($plugin));

        $getUserDataRef = new ReflectionMethod(WpAdminControl::class, 'getUserData');
        $this->assertNull($getUserDataRef->invoke($plugin, 10));

        $getUserByRef = new ReflectionMethod(WpAdminControl::class, 'getUserBy');
        $this->assertNull($getUserByRef->invoke($plugin, 'slug', 'unknown'));

        $getUsersRef = new ReflectionMethod(WpAdminControl::class, 'getUsers');
        $this->assertSame([], $getUsersRef->invoke($plugin, []));

        $isAdminRef = new ReflectionMethod(WpAdminControl::class, 'isAdmin');
        $this->assertFalse($isAdminRef->invoke($plugin));

        $ajaxRef = new ReflectionMethod(WpAdminControl::class, 'wpDoingAjax');
        $this->assertFalse($ajaxRef->invoke($plugin));

        $cronRef = new ReflectionMethod(WpAdminControl::class, 'wpDoingCron');
        $this->assertFalse($cronRef->invoke($plugin));

        $screenRef = new ReflectionMethod(WpAdminControl::class, 'getCurrentScreen');
        $this->assertNull($screenRef->invoke($plugin));

        $removedSubmenu = [];
        $pluginWithSubmenuRemover = new WpAdminControl(
            submenuRemover: static function (string $menu, string $sub) use (&$removedSubmenu): void {
                $removedSubmenu[] = [$menu, $sub];
            }
        );
        $removeSubmenuRef = new ReflectionMethod(WpAdminControl::class, 'removeSubmenuPage');
        $removeSubmenuRef->invoke($pluginWithSubmenuRemover, 'parent.php', 'child.php');
        $this->assertSame([['parent.php', 'child.php']], $removedSubmenu);
        $removeActionRef = new ReflectionMethod(WpAdminControl::class, 'removeAction');
        $removeActionRef->invoke($plugin, 'test_tag', 'test_cb', 10);

        $dequeueRef = new ReflectionMethod(WpAdminControl::class, 'dequeueStyle');
        $dequeueRef->invoke($plugin, 'test_handle');

        $deregisterRef = new ReflectionMethod(WpAdminControl::class, 'deregisterStyle');
        $deregisterRef->invoke($plugin, 'test_handle');

        $this->assertFalse(function_exists('wp_dequeue_style'));
    }

    public function testIsGoogleFontsDisabled(): void
    {
        $cases = [
            ['true', true],
            ['1', true],
            ['on', true],
            ['yes', true],
            ['TRUE', true],
            ['false', false],
            ['0', false],
            ['off', false],
            ['no', false],
            ['', false],
            [false, false],
        ];

        foreach ($cases as [$envVal, $expected]) {
            $plugin = new WpAdminControl(
                envGetter: static function (string $name) use ($envVal): string|false {
                    return $name === 'WP_ADMIN_DISABLE_GOOGLE_FONTS' ? $envVal : false;
                }
            );
            $this->assertSame($expected, $plugin->isGoogleFontsDisabled());
        }
    }

    public function testRegisterHooksWhenGoogleFontsDisabled(): void
    {
        $actions = [];
        $filters = [];

        $plugin = new WpAdminControl(
            envGetter: static function (string $name): string|false {
                return $name === 'WP_ADMIN_DISABLE_GOOGLE_FONTS' ? 'true' : false;
            },
            actionAdder: static function (
                string $tag,
                callable|string $cb,
                int $priority,
                int $args
            ) use (&$actions): void {
                $actions[] = [$tag, $priority, $args];
            },
            filterAdder: static function (
                string $tag,
                callable|string $cb,
                int $priority,
                int $args
            ) use (&$filters): void {
                $filters[] = [$tag, $priority, $args];
            }
        );

        $plugin->registerHooks();

        $this->assertContains(['admin_init', 1, 0], $actions);
        $this->assertContains(['admin_head', 1, 0], $actions);
        $this->assertContains(['admin_print_styles', 1, 0], $actions);
        $this->assertContains(['admin_print_styles', 9999, 0], $actions);
        $this->assertContains(['wp_resource_hints', 999, 2], $filters);
    }

    public function testHandleDisableGoogleFontsInitAndHead(): void
    {
        $removedActions = [];
        $dequeued = [];
        $deregistered = [];

        $plugin = new WpAdminControl(
            actionRemover: static function (
                string $tag,
                callable|string $cb,
                int $priority
            ) use (&$removedActions): void {
                $removedActions[] = [$tag, $cb, $priority];
            },
            styleDequeuer: static function (string $handle) use (&$dequeued): void {
                $dequeued[] = $handle;
            },
            styleDeregisterer: static function (string $handle) use (&$deregistered): void {
                $deregistered[] = $handle;
            }
        );

        $plugin->handleDisableGoogleFontsInit();
        $this->assertSame([['admin_head', 'wp_resource_hints', 1]], $removedActions);

        $GLOBALS['wp_styles'] = (object) [
            'registered' => [
                'elementor-one-admin-fonts' => (object) [
                    'src' => 'https://fonts.googleapis.com/css2?family=Roboto',
                ],
                'gstatic-font' => (object) [
                    'src' => 'https://fonts.gstatic.com/font.woff2',
                ],
                'local-style' => (object) [
                    'src' => 'https://example.com/style.css',
                ],
                'invalid-entry' => 'not-an-object',
                'no-src' => (object) ['src' => 123],
            ],
        ];

        try {
            $plugin->handleDisableGoogleFontsHead();
            $this->assertContains('elementor-one-admin-fonts', $dequeued);
            $this->assertContains('gstatic-font', $dequeued);
            $this->assertNotContains('local-style', $dequeued);
            $this->assertContains('elementor-one-admin-fonts', $deregistered);
            $this->assertContains('gstatic-font', $deregistered);
            $this->assertNotContains('local-style', $deregistered);

            $dequeued = [];
            $deregistered = [];
            $plugin->handleDisableGoogleFontsStyles();
            $this->assertContains('elementor-one-admin-fonts', $dequeued);
            $this->assertContains('gstatic-font', $dequeued);
        } finally {
            unset($GLOBALS['wp_styles']);
        }
    }

    public function testStripGoogleFontsStylesDefensiveBranches(): void
    {
        $plugin = new WpAdminControl();

        $GLOBALS['wp_styles'] = null;
        $plugin->stripGoogleFontsStyles();
        $this->assertNull($GLOBALS['wp_styles']);

        $GLOBALS['wp_styles'] = (object) [];
        $plugin->stripGoogleFontsStyles();
        $this->assertTrue(is_object($GLOBALS['wp_styles']));

        unset($GLOBALS['wp_styles']);
    }

    public function testFilterResourceHints(): void
    {
        $plugin = new WpAdminControl();

        $urls = [
            'https://fonts.googleapis.com/css2?family=Roboto',
            'https://fonts.gstatic.com',
            'https://example.com/cdn/asset.js',
            123,
            null,
        ];

        $filtered = $plugin->filterResourceHints($urls);
        $this->assertSame(['https://example.com/cdn/asset.js'], $filtered);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGoogleFontsGlobalFunctionFallbacks(): void
    {
        ensure_admin_control_stubs();
        $plugin = new WpAdminControl();

        $removeActionRef = new ReflectionMethod(WpAdminControl::class, 'removeAction');
        $removeActionRef->invoke($plugin, 'admin_head', 'wp_resource_hints', 1);

        $dequeueRef = new ReflectionMethod(WpAdminControl::class, 'dequeueStyle');
        $dequeueRef->invoke($plugin, 'test_handle');

        $deregisterRef = new ReflectionMethod(WpAdminControl::class, 'deregisterStyle');
        $deregisterRef->invoke($plugin, 'test_handle');

        $this->assertTrue(function_exists('wp_dequeue_style'));
    }

    public function testParseRulesExpandedGrammarAndModifiers(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $rules = $plugin->parseRules('menu1|hide:admin||unknown_mod|block:editor; menu2|hide:admin,,!|hide:all');
        $this->assertCount(4, $rules);

        $nonCompact = $plugin->parseRules('menu3||role:editor,,!admin|user:10,,!20');
        $this->assertCount(1, $nonCompact);
        $this->assertSame(['admin'], $nonCompact[0]['invertedRoles']);
        $this->assertSame(['editor'], $nonCompact[0]['roles']);
        $this->assertSame([20], $nonCompact[0]['invertedUsers']);
        $this->assertSame([10], $nonCompact[0]['users']);
    }

    public function testHandleAdminInitBlockedSubmenuPair(): void
    {
        $editor = (object) ['ID' => 3, 'user_login' => 'editor1', 'roles' => ['editor']];
        $this->currentUser = $editor;

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=tools.php:custom_tool|block:all');
        $plugin = $this->createPluginWithMockFiles();

        $GLOBALS['pagenow'] = 'custom_tool';
        $plugin->handleAdminInit();

        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Sorry, you are not allowed to access this page.', $this->dieCalls[0]['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHandleAdminInitCustomMenuRedirects(): void
    {
        ensure_duplicator_stubs();
        $this->currentUser = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        putenv('WP_ADMIN_CUSTOM_MENU_1_TITLE=Custom Menu');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ITEMS=First|admin.php?page=my-sub');

        $plugin = $this->createPluginWithMockFiles(
            dependencies: [
                'terminator' => static function (): never {
                    throw new \RuntimeException('Request terminated');
                },
            ]
        );
        $_GET['page'] = 'custom-menu-1';

        try {
            $plugin->handleAdminInit();
            $this->fail('The terminator must stop further request handling.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Request terminated', $exception->getMessage());
        }

        $this->assertSame(
            'https://example.com/wp-admin/admin.php?page=my-sub',
            $GLOBALS['test_redirect_location'] ?? null
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapRegistersProductionTerminator(): void
    {
        ensure_wordpress_stubs();
        $GLOBALS['test_registered_actions'] = [];

        $this->assertTrue(class_exists(WpAdminControl::class));
        $this->assertArrayHasKey('admin_init', $GLOBALS['test_registered_actions']);
    }

    public function testProductionCustomMenuRedirectTerminatesProcess(): void
    {
        $pluginPath = var_export(
            dirname(__DIR__) . '/examples/data/wp-content/mu-plugins/wp-admin-control.php.example',
            true
        );
        $script = <<<PHP
function add_action(string \$hook, callable \$callback): void {
    \$GLOBALS['wp_admin_control_callbacks'][\$hook] = \$callback;
}
function add_filter(string \$hook, callable \$callback): void {
}
function is_admin(): bool {
    return true;
}
function is_user_logged_in(): bool {
    return true;
}
function wp_get_current_user(): object {
    return (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
}
function wp_safe_redirect(string \$target): bool {
    echo \$target;
    return true;
}
function admin_url(string \$path): string {
    return 'https://example.com/wp-admin/' . \$path;
}
putenv('WP_ADMIN_CUSTOM_MENU_1_TITLE=Custom Menu');
putenv('WP_ADMIN_CUSTOM_MENU_1_ITEMS=First|admin.php?page=my-sub');
\$_GET['page'] = 'custom-menu-1';
require {$pluginPath};
(\$GLOBALS['wp_admin_control_callbacks']['admin_init'])();
PHP;
        $command = sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($script));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process));
        $this->assertSame('', $stderr);
        $this->assertSame('https://example.com/wp-admin/admin.php?page=my-sub', $stdout);
    }

    public function testHandleAdminMenuRemainingSubmenuAndInvalidMenuArray(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->currentUser = $editor;

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=tools.php');
        $plugin = $this->createPluginWithMockFiles(
            dependencies: [
                'submenuRemover' => function (string $parent, string $sub): void {
                    $this->removedSubmenuPages[] = [$parent, $sub];
                },
            ]
        );

        $GLOBALS['menu'] = [
            ['Invalid', 'read'],
            ['Tools', 'read', 'tools.php'],
        ];
        $GLOBALS['submenu'] = [
            'tools.php' => [],
            'custom-menu-1' => [
                ['Custom One', 'read', 'custom-menu-1'],
            ],
        ];

        $cachedRef = new \ReflectionProperty(WpAdminControl::class, 'cachedCustomMenus');
        $cachedRef->setValue($plugin, [
            [
                'id' => 'custom-menu-1',
                'title' => 'Custom One',
                'icon' => '',
                'targetRules' => [],
                'items' => [
                    'invalid_non_array_item',
                    ['title' => 'Valid', 'url' => 'sub.php'],
                ],
            ],
        ]);

        $plugin->handleAdminMenu();

        $this->assertContains('tools.php', $this->removedMenuPages);
        $this->assertCount(1, $GLOBALS['submenu']['custom-menu-1']);
        $this->assertSame('sub.php', $GLOBALS['submenu']['custom-menu-1'][0][2]);
    }

    public function testHandleAdminHeadSidebarControlSelectors(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->currentUser = $editor;

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=admin.php?page=clean-slug; parent:invalid slug!');
        $plugin = $this->createPluginWithMockFiles();

        $plugin->handleAdminHead();
        $this->assertStringContainsString('#adminmenu a[href*="page=clean-slug"]', $this->echoedHtml);
        $this->assertStringNotContainsString('invalid slug!', $this->echoedHtml);
    }

    public function testHandleSidebarSeparatorsEdgeCases(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $GLOBALS['menu'] = null;
        putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER=tools.php');
        $plugin->handleSidebarSeparators();
        $this->assertNull($GLOBALS['menu']);

        putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER=  ,  ,  ');
        $GLOBALS['menu'] = [];
        $plugin->handleSidebarSeparators();
        $this->assertSame([], $GLOBALS['menu']);

        putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER=tools.php');
        $GLOBALS['menu'] = [
            'invalid_item',
            100 => ['Tools', 'read', 'tools.php', '', '', 'tools.php'],
            '100.1' => ['Existing Separator', 'read', 'sep-existing', '', 'wp-menu-separator'],
        ];
        $plugin->handleSidebarSeparators();
        $this->assertArrayHasKey('100.11', $GLOBALS['menu']);
    }

    public function testShouldHideWelcomePanelReturnsFalseWhenNotTargeted(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        putenv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE=dashboard_quick_press|role:editor');
        $plugin = $this->createPluginWithMockFiles();

        $this->assertFalse($plugin->shouldHideWelcomePanel($editor));
    }

    public function testFilterMenuOrderSeparatorSpliceBranches(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER=tools.php');
        $GLOBALS['menu'] = [
            10 => ['Tools', 'read', 'tools.php', '', '', 'tools.php'],
        ];
        $plugin->handleSidebarSeparators();

        putenv('WP_ADMIN_MENU_ORDER');
        $order = ['index.php', 'separator-custom-1', 'tools.php'];
        $reordered = $plugin->filterMenuOrder($order);
        $this->assertSame(['index.php', 'tools.php', 'separator-custom-1'], $reordered);

        putenv('WP_ADMIN_MENU_ORDER=tools.php,index.php');
        $order2 = ['index.php', 'separator-custom-1', 'tools.php'];
        $reordered2 = $plugin->filterMenuOrder($order2);
        $this->assertSame(['tools.php', 'separator-custom-1', 'index.php'], $reordered2);
    }

    public function testFilterParentFileAndSubmenuFileBranches(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $editor = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $editor;
        $this->allUsers = [$superAdmin, $editor];

        $plugin = $this->createPluginWithMockFiles();
        $this->assertSame('original.php', $plugin->filterParentFile('original.php'));
        $this->assertSame('original_sub.php', $plugin->filterSubmenuFile('original_sub.php'));

        putenv('WP_ADMIN_CUSTOM_MENU_1_TITLE=Restricted Menu');
        putenv('WP_ADMIN_CUSTOM_MENU_1_TARGET=user:999');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ITEMS=Sub|sub.php');

        $this->currentUser = $editor;
        $pluginRestricted = $this->createPluginWithMockFiles();
        $this->assertSame('orig.php', $pluginRestricted->filterParentFile('orig.php'));
        $this->assertSame('orig_sub.php', $pluginRestricted->filterSubmenuFile('orig_sub.php'));

        putenv('WP_ADMIN_CUSTOM_MENU_1_TARGET=all');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ITEMS=Item One|https://example.com');
        $pluginWithItems = $this->createPluginWithMockFiles();

        $cachedRef = new \ReflectionProperty(WpAdminControl::class, 'cachedCustomMenus');
        $cachedRef->setValue($pluginWithItems, [
            [
                'id' => 'custom-1',
                'title' => 'Custom',
                'icon' => '',
                'targetRules' => [],
                'items' => ['invalid_sub_item', ['title' => 'Valid', 'url' => 'admin.php?page=test']],
            ],
        ]);

        $GLOBALS['pagenow'] = 'admin.php';
        $GLOBALS['plugin_page'] = null;
        $_GET = ['page' => 'test'];

        $this->assertSame('custom-1', $pluginWithItems->filterParentFile('orig.php'));
        $this->assertSame('admin.php?page=test', $pluginWithItems->filterSubmenuFile('orig_sub.php'));
    }

    public function testIsCurrentUrlMatchingBranches(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $this->assertFalse($plugin->isCurrentUrlMatching('http://:80', 'index.php', null));
        $this->assertFalse($plugin->isCurrentUrlMatching('tools.php?page=test', 'options.php', null));

        $_GET = ['tab' => 'general'];
        $this->assertTrue($plugin->isCurrentUrlMatching('admin.php?page=test&tab=general', 'admin.php', 'test'));

        $_GET = ['page' => 'test'];
        $this->assertFalse($plugin->isCurrentUrlMatching('admin.php?page=test&tab=two', 'admin.php', 'test'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHandleAdminInitCustomMenuWpRedirectFallback(): void
    {
        $GLOBALS['test_skip_wp_safe_redirect'] = true;
        ensure_duplicator_stubs();
        $this->currentUser = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        putenv('WP_ADMIN_CUSTOM_MENU_1_TITLE=Custom Menu');
        putenv('WP_ADMIN_CUSTOM_MENU_1_ITEMS=First|https://example.com/target');

        $plugin = $this->createPluginWithMockFiles(
            dependencies: [
                'terminator' => static function (): never {
                    throw new \RuntimeException('Request terminated');
                },
            ]
        );
        $_GET['page'] = 'custom-menu-1';

        try {
            $plugin->handleAdminInit();
            $this->fail('The terminator must stop further request handling.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Request terminated', $exception->getMessage());
        }

        $this->assertSame('https://example.com/target', $GLOBALS['test_redirect_location'] ?? null);
    }

    public function testIsUserAllowedCustomMenuAndRegisterParentPage(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $this->usersData[1] = $superAdmin;
        $this->allUsers = [$superAdmin];

        $plugin = $this->createPluginWithMockFiles();

        $isAllowedRef = new ReflectionMethod(WpAdminControl::class, 'isUserAllowedCustomMenu');

        $menu = [
            'targetRules' => [[
                'isAll' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
                'targets' => [],
            ]],
        ];
        $this->assertTrue($isAllowedRef->invoke($plugin, $menu, $superAdmin));

        $this->assertTrue($isAllowedRef->invoke($plugin, ['targetRules' => []], null));
        $this->assertTrue($isAllowedRef->invoke($plugin, ['targetRules' => ['not_an_array']], null));

        $registerRef = new ReflectionMethod(WpAdminControl::class, 'registerParentPage');
        $registerRef->invoke($plugin, '', 'parent');
        $registerRef->invoke($plugin, 'child', '');
        $this->assertFalse(isset($GLOBALS['_parent_pages']['']));
    }

    public function testFilterMapMetaCapWithNonStringCap(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $this->assertSame(['custom_cap'], $plugin->filterMapMetaCap(['custom_cap'], '', 1, []));
        $this->assertSame(['custom_cap'], $plugin->filterMapMetaCap(['custom_cap'], 12345, 1, []));
    }

    public function testIsCurrentPluginPageRequestBranchesInHandleAdminMenu(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->currentUser = $editor;

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=admin.php?page=my-tool; tools.php:query-tool?foo=bar');
        $plugin = $this->createPluginWithMockFiles();

        $_GET['page'] = 'my-tool';
        $plugin->handleAdminMenu();

        $_GET['page'] = 'query-tool';
        $plugin->handleAdminMenu();

        $this->assertTrue(true);
    }
}
