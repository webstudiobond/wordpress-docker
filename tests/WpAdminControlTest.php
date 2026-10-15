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
    /** @var list<array{0: string, 1: string}> */
    private array $removedPostTypeSupports = [];
    private ?object $currentScreen = null;
    private string $echoedHtml = '';
    /** @var array<int, object> */
    private array $postsData = [];
    private int $queriedObjectId = 0;
    /** @var list<array{0: string, 1: int|false}> */
    private array $removedAllActions = [];
    /** @var list<string> */
    private array $unregisteredWidgets = [];
    /** @var list<string> */
    private array $dequeuedScripts = [];
    /** @var list<string> */
    private array $dequeuedStyles = [];
    /** @var array<int, array<string, mixed>> */
    private array $userMeta = [];
    private int $initialObLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialObLevel = ob_get_level();
        $this->superAdminHash = hash_hmac('sha512', '1:superadmin', $this->salt);

        $this->currentUser = null;
        $this->userMeta = [];
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
        $this->removedPostTypeSupports = [];
        $this->removedActions = [];
        $this->removedAllActions = [];
        $this->unregisteredWidgets = [];
        $this->dequeuedScripts = [];
        $this->dequeuedStyles = [];
        $this->addedDashboardWidgets = [];
        $this->currentScreen = null;
        $this->echoedHtml = '';
        $this->postsData = [];
        $this->queriedObjectId = 0;

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
        putenv('WP_ADMIN_SIDEBAR_MENU_ORDER');
        putenv('WP_ADMIN_TOPBAR_MENU_ORDER');
        putenv('WP_ADMIN_TOPBAR_MENU_REMOVE');
        putenv('WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE');
        putenv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_REMOVE');
        putenv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER');
        putenv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP');
        putenv('WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS');
        putenv('WP_ADMIN_HIDDEN_META_BOXES');
        putenv('WP_ADMIN_HIDDEN_COLUMNS');
        putenv('WP_ADMIN_DASHBOARD_REMOVE_BOXES');
        putenv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE');
        putenv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER');
        putenv('WP_ADMIN_CONTENT_ACCESS_RULES');
        putenv('WP_ADMIN_FOOTER_LEFT');
        putenv('WP_ADMIN_FOOTER_RIGHT');
        putenv('WP_ADMIN_HIDE_NOTICES');
        putenv('WP_ADMIN_GUTENBERG_BLOCKS_REMOVE');
        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE');
        putenv('WP_ADMIN_SIDEBAR_WIDGETS_REMOVE');
        putenv('WP_ADMIN_TINYMCE_BUTTONS_REMOVE');

        for ($i = 1; $i <= 5; $i++) {
            putenv('WP_ADMIN_DASHBOARD_WIDGET_' . $i . '_TITLE');
            putenv('WP_ADMIN_DASHBOARD_WIDGET_' . $i . '_TARGET');
            putenv('WP_ADMIN_DASHBOARD_WIDGET_' . $i . '_CONTENT');
        }

        for ($i = 1; $i <= 20; $i++) {
            putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_ID');
            putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_TITLE');
            putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_ICON');
            putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_TARGET');
            putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_SCOPE');
            putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_ITEMS');
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
            $GLOBALS['test_user_meta'],
            $GLOBALS['wp_query']
        );

        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }

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
            actionAdder: $dependencies['actionAdder'] ?? null,
            filterAdder: $dependencies['filterAdder'] ?? null,
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
            postGetter: $dependencies['postGetter'] ?? fn (int $id): ?object => $this->postsData[$id] ?? null,
            postResolver: $dependencies['postResolver'] ?? fn (
                string $target
            ): ?int => $this->resolveTestPostTarget($target),
            queriedObjectIdGetter: $dependencies['queriedObjectIdGetter'] ?? fn (): int => $this->queriedObjectId,
            userPostsCountGetter: $dependencies['userPostsCountGetter'] ?? function (
                int $userId,
                string $postType
            ): int {
                $count = 0;
                foreach ($this->postsData as $post) {
                    if (
                        ($post->post_author ?? 0) === $userId
                        && ($post->post_type ?? '') === $postType
                    ) {
                        $count++;
                    }
                }
                return $count;
            },
            inlineScriptAdder: $dependencies['inlineScriptAdder'] ?? null,
            postTypeSupportRemover: $dependencies['postTypeSupportRemover'] ?? function (
                string $postType,
                string $feature
            ): void {
                $this->removedPostTypeSupports[] = [$postType, $feature];
            },
            styleDequeuer: $dependencies['styleDequeuer'] ?? function (string $handle): void {
                $this->dequeuedStyles[] = $handle;
            },
            scriptDequeuer: $dependencies['scriptDequeuer'] ?? function (string $handle): void {
                $this->dequeuedScripts[] = $handle;
            },
            allActionsRemover: $dependencies['allActionsRemover'] ?? function (
                string $tag,
                int|false $priority = false
            ): void {
                $this->removedAllActions[] = [$tag, $priority];
            },
            widgetUnregisterer: $dependencies['widgetUnregisterer'] ?? function (string $widget): void {
                $this->unregisteredWidgets[] = $widget;
            },
            registeredBlockTypesGetter: $dependencies['registeredBlockTypesGetter'] ?? null,
            userMetaGetter: $dependencies['userMetaGetter'] ?? function (
                int $userId,
                string $key,
                bool $single = true
            ): mixed {
                $userM = $this->userMeta[$userId] ?? [];
                if ($key === '') {
                    return $userM;
                }
                if ($single) {
                    return $userM[$key] ?? '';
                }
                return isset($userM[$key]) ? [$userM[$key]] : [];
            },
            terminator: $dependencies['terminator'] ?? null
        );
    }

    private function createMockPost(
        int $id,
        string $slug,
        string $type = 'post',
        string $status = 'publish',
        int $author = 0
    ): object {
        $post = new stdClass();
        $post->ID = $id;
        $post->post_name = $slug;
        $post->post_type = $type;
        $post->post_status = $status;
        $post->post_author = $author;
        return $post;
    }

    private function resolveTestPostTarget(string $target): ?int
    {
        if (str_starts_with($target, 'id:')) {
            $rawId = substr($target, 3);
            return ctype_digit($rawId) ? (int) $rawId : null;
        }
        if (ctype_digit($target)) {
            return (int) $target;
        }
        $postType = null;
        $slug = $target;
        if (str_starts_with($target, 'slug:')) {
            $slug = substr($target, 5);
        } elseif (str_contains($target, ':')) {
            [$postType, $slug] = explode(':', $target, 2);
        }
        foreach ($this->postsData as $id => $post) {
            $pSlug = is_string($post->post_name ?? null) ? $post->post_name : '';
            $pType = is_string($post->post_type ?? null) ? $post->post_type : 'post';
            if ($postType !== null && $postType !== '') {
                if ($pType === $postType && $pSlug === $slug) {
                    return $id;
                }
            } elseif ($pSlug === $slug) {
                return $id;
            }
        }
        return null;
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

    public function testSidebarMenuOrderSparseNumericPositioning(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $GLOBALS['menu'] = [
            2 => ['Dashboard', 'read', 'index.php'],
            5 => ['Posts', 'edit_posts', 'edit.php'],
            25 => ['Comments', 'edit_posts', 'edit-comments.php'],
            70 => ['Users', 'list_users', 'users.php'],
            75 => ['Tools', 'edit_posts', 'tools.php'],
            100 => ['Cache', 'manage_options', 'custom-cache'],
        ];

        putenv('WP_ADMIN_SIDEBAR_MENU_ORDER=custom-cache:1,tools.php:90');

        $plugin->handleSidebarMenuOrder();

        $this->assertArrayHasKey(1, $GLOBALS['menu']);
        $this->assertSame('custom-cache', $GLOBALS['menu'][1][2]);
        $this->assertArrayHasKey(90, $GLOBALS['menu']);
        $this->assertSame('tools.php', $GLOBALS['menu'][90][2]);
        $this->assertArrayHasKey(2, $GLOBALS['menu']);
        $this->assertSame('index.php', $GLOBALS['menu'][2][2]);
        $this->assertArrayHasKey(25, $GLOBALS['menu']);
        $this->assertSame('edit-comments.php', $GLOBALS['menu'][25][2]);

        $keys = array_keys($GLOBALS['menu']);
        $this->assertSame([1, 2, 5, 25, 70, 90], $keys);
    }

    public function testSidebarMenuOrderCollisionResolution(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $GLOBALS['menu'] = [
            2 => ['Dashboard', 'read', 'index.php'],
            5 => ['Posts', 'edit_posts', 'edit.php'],
            25 => ['Comments', 'edit_posts', 'edit-comments.php'],
            70 => ['Users', 'list_users', 'users.php'],
            75 => ['Tools', 'edit_posts', 'tools.php'],
            100 => ['Cache', 'manage_options', 'custom-cache'],
        ];

        putenv('WP_ADMIN_SIDEBAR_MENU_ORDER=custom-cache:25,tools.php:25');

        $plugin->handleSidebarMenuOrder();

        $this->assertArrayHasKey(25, $GLOBALS['menu']);
        $this->assertSame('custom-cache', $GLOBALS['menu'][25][2]);
        $this->assertArrayHasKey('25.01', $GLOBALS['menu']);
        $this->assertSame('tools.php', $GLOBALS['menu']['25.01'][2]);
        $this->assertArrayHasKey('25.02', $GLOBALS['menu']);
        $this->assertSame('edit-comments.php', $GLOBALS['menu']['25.02'][2]);

        $keys = array_keys($GLOBALS['menu']);
        $this->assertSame([2, 5, 25, '25.01', '25.02', 70], $keys);
    }

    public function testTopbarMenuOrderSparseNumericPositioning(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $barMock = new class {
            /** @var array<string, object> */
            public array $nodes = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'add_node') {
                    $args = is_array($arguments[0] ?? null) ? $arguments[0] : [];
                    $id = (string) ($args['id'] ?? '');
                    $this->nodes[$id] = (object) $args;
                    return null;
                }
                if ($name === 'get_node') {
                    $id = (string) ($arguments[0] ?? '');
                    return $this->nodes[$id] ?? null;
                }
                if ($name === 'get_nodes') {
                    return $this->nodes;
                }
                if ($name === 'remove_node') {
                    $id = (string) ($arguments[0] ?? '');
                    unset($this->nodes[$id]);
                    return null;
                }
                return null;
            }
        };

        $barMock->add_node(['id' => 'wp-logo', 'parent' => false]);
        $barMock->add_node(['id' => 'about', 'parent' => 'wp-logo']);
        $barMock->add_node(['id' => 'site-name', 'parent' => false]);
        $barMock->add_node(['id' => 'view-site', 'parent' => 'site-name']);
        $barMock->add_node(['id' => 'custom-tools', 'parent' => false]);
        $barMock->add_node(['id' => 'new-content', 'parent' => false]);
        $barMock->add_node(['id' => 'new-post', 'parent' => 'new-content']);
        $barMock->add_node(['id' => 'my-account', 'parent' => 'top-secondary']);

        putenv('WP_ADMIN_TOPBAR_MENU_ORDER=site-name:1,custom-tools:2,new-content:3');

        $plugin->handleTopbarMenuOrder($barMock);

        $nodes = $barMock->get_nodes();
        $this->assertArrayHasKey('about', $nodes);
        $this->assertArrayHasKey('view-site', $nodes);
        $this->assertArrayHasKey('new-post', $nodes);
        $this->assertArrayHasKey('my-account', $nodes);

        $rootOrder = [];
        foreach ($nodes as $nodeId => $node) {
            $parent = (string) ($node->parent ?? '');
            if ($parent === '' || $node->parent === false) {
                $rootOrder[] = $nodeId;
            }
        }

        $this->assertSame(['site-name', 'custom-tools', 'new-content', 'wp-logo'], $rootOrder);
    }

    public function testHandleAdminBarMenuUserProfileRemove(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE=!superadmins');

        $editor = $this->createMockUser(3, 'editor1', ['editor']);
        $this->currentUser = $editor;

        $editorBar = new class {
            /** @var list<string> */
            public array $removed = [];

            /** @var array<string, object> */
            public array $nodes = [];

            public function __construct()
            {
                $this->nodes['my-account'] = (object) [
                    'id' => 'my-account',
                    'title' => 'Howdy, Editor',
                    'href' => 'https://example.com/wp-admin/profile.php',
                ];
                $this->nodes['user-info'] = (object) [
                    'id' => 'user-info',
                    'parent' => 'user-actions',
                    'title' => 'Editor',
                    'href' => 'https://example.com/wp-admin/profile.php',
                ];
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                    unset($this->nodes[$arguments[0]]);
                    return null;
                }
                if ($name === 'get_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    return $this->nodes[$arguments[0]] ?? null;
                }
                if ($name === 'add_node' && isset($arguments[0])) {
                    $data = (array) $arguments[0];
                    $id = (string) ($data['id'] ?? '');
                    if ($id !== '') {
                        $this->nodes[$id] = (object) $data;
                    }
                    return null;
                }
                return null;
            }
        };

        $plugin->handleAdminBarMenu($editorBar);

        $this->assertContains('user-info', $editorBar->removed);
        $this->assertArrayNotHasKey('user-info', $editorBar->nodes);
        $this->assertArrayHasKey('my-account', $editorBar->nodes);
        $this->assertFalse($editorBar->nodes['my-account']->href);

        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->currentUser = $superAdmin;

        $superBar = new class {
            /** @var list<string> */
            public array $removed = [];

            /** @var array<string, object> */
            public array $nodes = [];

            public function __construct()
            {
                $this->nodes['my-account'] = (object) [
                    'id' => 'my-account',
                    'title' => 'Howdy, Superadmin',
                    'href' => 'https://example.com/wp-admin/profile.php',
                ];
                $this->nodes['user-info'] = (object) [
                    'id' => 'user-info',
                    'parent' => 'user-actions',
                    'title' => 'Superadmin',
                    'href' => 'https://example.com/wp-admin/profile.php',
                ];
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                    unset($this->nodes[$arguments[0]]);
                    return null;
                }
                if ($name === 'get_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    return $this->nodes[$arguments[0]] ?? null;
                }
                if ($name === 'add_node' && isset($arguments[0])) {
                    $data = (array) $arguments[0];
                    $id = (string) ($data['id'] ?? '');
                    if ($id !== '') {
                        $this->nodes[$id] = (object) $data;
                    }
                    return null;
                }
                return null;
            }
        };

        $plugin->handleAdminBarMenu($superBar);

        $this->assertNotContains('user-info', $superBar->removed);
        $this->assertArrayHasKey('user-info', $superBar->nodes);
        $this->assertSame('https://example.com/wp-admin/profile.php', $superBar->nodes['my-account']->href);
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
    }

    public function testAdminBarCleanupAndNewDropdownFiltering(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_TOPBAR_MENU_REMOVE=wp-logo,about|all; comments|role:editor');
        putenv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_REMOVE=new-user|!role:administrator');

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

        putenv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP=!superadmins');
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

    public function testContentAccessRulesLockdownViaMapMetaCapAndPreGetPosts(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=id:10,20|role:editor|action:block; id:50|user:7|action:block');

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
        $this->assertArrayHasKey('admin_print_footer_scripts', $actions);
        $this->assertSame(999, $actions['admin_print_footer_scripts']['priority']);
        $this->assertArrayHasKey('add_meta_boxes', $actions);
        $this->assertSame(999, $actions['add_meta_boxes']['priority']);
        $this->assertArrayHasKey('enqueue_block_editor_assets', $actions);
        $this->assertSame(999, $actions['enqueue_block_editor_assets']['priority']);
        $this->assertArrayHasKey('rest_api_init', $actions);
        $this->assertSame(999, $actions['rest_api_init']['priority']);

        $this->assertArrayHasKey('pre_get_users', $filters);
        $this->assertArrayHasKey('views_users', $filters);
        $this->assertSame(999, $filters['views_users']['priority']);
        $this->assertArrayHasKey('user_row_actions', $filters);
        $this->assertSame(999, $filters['user_row_actions']['priority']);
        $this->assertArrayHasKey('manage_users_custom_column', $filters);
        $this->assertSame(999, $filters['manage_users_custom_column']['priority']);
        $this->assertArrayHasKey('rest_user_query', $filters);
        $this->assertArrayHasKey('rest_pre_dispatch', $filters);
        $this->assertArrayHasKey('rest_post_query', $filters);
        $this->assertArrayHasKey('custom_menu_order', $filters);
        $this->assertArrayHasKey('menu_order', $filters);
        $this->assertArrayHasKey('wp_die_handler', $filters);
        $this->assertSame(999, $filters['wp_die_handler']['priority']);
        $this->assertArrayHasKey('screen_options_show_screen', $filters);
        $this->assertArrayHasKey('hidden_meta_boxes', $filters);
        $this->assertArrayHasKey('hidden_columns', $filters);
        $this->assertArrayHasKey('default_hidden_columns', $filters);
        $this->assertArrayHasKey('manage_posts_columns', $filters);
        $this->assertSame(999, $filters['manage_posts_columns']['priority']);
        $this->assertArrayHasKey('manage_pages_columns', $filters);
        $this->assertSame(999, $filters['manage_pages_columns']['priority']);
        $this->assertArrayHasKey('map_meta_cap', $filters);
        $this->assertArrayHasKey('pre_get_posts', $filters);
        $this->assertArrayHasKey('wp_count_posts', $filters);
        $this->assertArrayHasKey('block_editor_settings_all', $filters);
        $this->assertSame(999, $filters['block_editor_settings_all']['priority']);
        $this->assertArrayHasKey('wp_insert_post_data', $filters);
        $this->assertSame(999, $filters['wp_insert_post_data']['priority']);
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

        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=id:10,20|all|action:block');
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

        $authorExcludeDefault = $plugin->filterRestPostQuery(['author_exclude' => [5]]);
        $this->assertSame([5], $authorExcludeDefault['author_exclude']);

        putenv('WP_ADMIN_HIDE_SUPERADMIN_POSTS=true');
        $authorExcludeResult = $plugin->filterRestPostQuery(['author_exclude' => [5]]);
        $this->assertSame([5, 1], $authorExcludeResult['author_exclude']);
        putenv('WP_ADMIN_HIDE_SUPERADMIN_POSTS');
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
        $this->assertArrayNotHasKey('author__not_in', $queryGeneral->params);

        putenv('WP_ADMIN_HIDE_SUPERADMIN_POSTS=true');
        $queryGeneralEnabled = clone $queryGeneral;
        $queryGeneralEnabled->params = [];
        $plugin->filterPreGetPosts($queryGeneralEnabled);
        $this->assertSame([1], $queryGeneralEnabled->params['author__not_in']);
        putenv('WP_ADMIN_HIDE_SUPERADMIN_POSTS');
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

        putenv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER=new-page,new-post');
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

    public function testAdminBarMissingMethodsAndInvalidInputs(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();
        $plugin->handleAdminBarMenu(new stdClass());

        putenv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER=new-post,new-page');
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
        $this->assertTrue(true);
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
        $this->assertSame([10, '20'], $frontendQuery->vars['author__not_in']);

        putenv('WP_ADMIN_HIDE_SUPERADMIN_POSTS=true');
        $plugin->filterPreGetPosts($frontendQuery);
        $this->assertSame([10, 20, 1], $frontendQuery->vars['author__not_in']);
        putenv('WP_ADMIN_HIDE_SUPERADMIN_POSTS');

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

        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=id:100|action:block');
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

        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=');
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
        $this->assertArrayHasKey('views_users', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('user_row_actions', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('manage_users_custom_column', $GLOBALS['test_registered_filters']);
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
                    return $name === 'WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS' ? $envVal : false;
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
                return $name === 'WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS' ? 'true' : false;
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
        $this->assertContains(['views_users', 999, 1], $filters);
        $this->assertContains(['user_row_actions', 999, 2], $filters);
        $this->assertContains(['manage_users_custom_column', 999, 3], $filters);
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
    public function testBootstrapRegistersHooks(): void
    {
        ensure_wordpress_stubs();
        $GLOBALS['test_registered_actions'] = [];

        $this->assertTrue(class_exists(WpAdminControl::class));
        $this->assertArrayHasKey('admin_init', $GLOBALS['test_registered_actions']);
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
        ];

        $plugin->handleAdminMenu();

        $this->assertContains('tools.php', $this->removedMenuPages);
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

        $order = ['index.php', 'separator-custom-1', 'tools.php'];
        $reordered = $plugin->filterMenuOrder($order);
        $this->assertSame(['index.php', 'tools.php', 'separator-custom-1'], $reordered);

        $order2 = ['index.php', 'tools.php', 'options-general.php', 'separator-custom-1'];
        $reordered2 = $plugin->filterMenuOrder($order2);
        $this->assertSame(['index.php', 'tools.php', 'separator-custom-1', 'options-general.php'], $reordered2);
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

    public function testParseRulesWithSuperadminsModifier(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $compactRules = $plugin->parseRules('tools.php|hide:superadmins|block:!superadmins');
        $this->assertCount(2, $compactRules);
        $this->assertTrue($compactRules[0]['superadmins']);
        $this->assertFalse($compactRules[0]['invertedSuperadmins']);
        $this->assertSame('hide', $compactRules[0]['action']);

        $this->assertFalse($compactRules[1]['superadmins']);
        $this->assertTrue($compactRules[1]['invertedSuperadmins']);
        $this->assertSame('block', $compactRules[1]['action']);

        $nonCompactRules = $plugin->parseRules('tools.php|superadmins; plugins.php|!superadmins');
        $this->assertCount(2, $nonCompactRules);
        $this->assertTrue($nonCompactRules[0]['superadmins']);
        $this->assertFalse($nonCompactRules[0]['invertedSuperadmins']);
        $this->assertFalse($nonCompactRules[1]['superadmins']);
        $this->assertTrue($nonCompactRules[1]['invertedSuperadmins']);
    }

    public function testRuleEvaluationWithSuperadminsPseudoRole(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularAdmin = (object) ['ID' => 2, 'user_login' => 'admin2', 'roles' => ['administrator']];
        $editor = (object) ['ID' => 3, 'user_login' => 'editor', 'roles' => ['editor']];
        $author = (object) ['ID' => 4, 'user_login' => 'author', 'roles' => ['author']];

        $plugin = $this->createPluginWithMockFiles();

        $blockNonSuper = $plugin->parseRules('tools.php|block:!superadmins')[0];
        $this->assertFalse($plugin->isUserRestrictedByRule($blockNonSuper, $superAdmin));
        $this->assertTrue($plugin->isUserRestrictedByRule($blockNonSuper, $regularAdmin));
        $this->assertTrue($plugin->isUserRestrictedByRule($blockNonSuper, $editor));

        $blockSuperOnly = $plugin->parseRules('tools.php|block:superadmins')[0];
        $this->assertFalse($plugin->isUserRestrictedByRule($blockSuperOnly, $superAdmin));
        $this->assertFalse($plugin->isUserRestrictedByRule($blockSuperOnly, $regularAdmin));

        $blockNonSuperExemptEditor = $plugin->parseRules('tools.php|block:!superadmins,!editor')[0];
        $this->assertFalse($plugin->isUserRestrictedByRule($blockNonSuperExemptEditor, $superAdmin));
        $this->assertFalse($plugin->isUserRestrictedByRule($blockNonSuperExemptEditor, $editor));
        $this->assertTrue($plugin->isUserRestrictedByRule($blockNonSuperExemptEditor, $regularAdmin));
        $this->assertTrue($plugin->isUserRestrictedByRule($blockNonSuperExemptEditor, $author));

        $hideSuper = $plugin->parseRules('tools.php|hide:superadmins')[0];
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($hideSuper, $superAdmin));
        $this->assertFalse($plugin->isUserMatchingCosmeticRule($hideSuper, $regularAdmin));
        $this->assertFalse($plugin->isUserMatchingCosmeticRule($hideSuper, $editor));

        $hideNonSuper = $plugin->parseRules('tools.php|hide:!superadmins')[0];
        $this->assertFalse($plugin->isUserMatchingCosmeticRule($hideNonSuper, $superAdmin));
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($hideNonSuper, $regularAdmin));
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($hideNonSuper, $editor));

        $hideNonSuperExemptEditor = $plugin->parseRules('tools.php|hide:!superadmins,!editor')[0];
        $this->assertFalse($plugin->isUserMatchingCosmeticRule($hideNonSuperExemptEditor, $superAdmin));
        $this->assertFalse($plugin->isUserMatchingCosmeticRule($hideNonSuperExemptEditor, $editor));
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($hideNonSuperExemptEditor, $regularAdmin));
        $this->assertTrue($plugin->isUserMatchingCosmeticRule($hideNonSuperExemptEditor, $author));
    }

    public function testHandleAdminInitBlockedForNonSuperadminViaPseudoRole(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularAdmin = (object) ['ID' => 2, 'user_login' => 'admin2', 'roles' => ['administrator']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularAdmin;

        putenv('WP_ADMIN_SIDEBAR_MENU_CONTROL=tools.php|block:!superadmins');
        $plugin = $this->createPluginWithMockFiles();

        $this->currentUser = $regularAdmin;
        $GLOBALS['pagenow'] = 'tools.php';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Sorry, you are not allowed to access this page.', $this->dieCalls[0]['message']);

        $this->dieCalls = [];
        $this->currentUser = $superAdmin;
        $GLOBALS['pagenow'] = 'tools.php';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }

    public function testFilterWpDieHandlerAndRenderStealthDie(): void
    {
        $capturedHtml = '';
        $plugin = $this->createPluginWithMockFiles(
            dependencies: [
                'headerEchoer' => static function (string $html) use (&$capturedHtml): void {
                    $capturedHtml .= $html;
                },
                'terminator' => static function (): never {
                    throw new \RuntimeException('Terminated');
                },
            ]
        );

        $defaultHandler = static function (): void {
        };
        $handler = $plugin->filterWpDieHandler($defaultHandler);
        $this->assertIsCallable($handler);

        try {
            $handler('Custom access denied string.', '403 Forbidden', ['response' => 403]);
            $this->fail('Terminator must stop execution.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Terminated', $exception->getMessage());
        }

        $this->assertStringContainsString('<!DOCTYPE html>', $capturedHtml);
        $this->assertStringContainsString('<title>403 Forbidden</title>', $capturedHtml);
        $this->assertStringContainsString('#0f172a', $capturedHtml);
        $this->assertStringContainsString('#f59e0b', $capturedHtml);
        $this->assertStringContainsString('<h1>403</h1>', $capturedHtml);
        $this->assertStringContainsString('Custom access denied string.', $capturedHtml);
        $this->assertStringNotContainsString('WordPress', $capturedHtml);

        $capturedHtml = '';
        try {
            $plugin->renderStealthDie(new WP_Error('error_code', 'Fatal error message.'), '', ['response' => 500]);
            $this->fail('Terminator must stop execution.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Terminated', $exception->getMessage());
        }

        $this->assertStringContainsString('<title>500 Forbidden</title>', $capturedHtml);
        $this->assertStringContainsString('#ef4444', $capturedHtml);
        $this->assertStringContainsString('<h1>500</h1>', $capturedHtml);
        $this->assertStringContainsString('Fatal error message.', $capturedHtml);
    }

    public function testResolveAdminUrlCases(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $this->assertSame('', $plugin->resolveAdminUrl(''));
        $this->assertSame('https://example.com/custom', $plugin->resolveAdminUrl('https://example.com/custom'));
        $this->assertSame('http://example.com/custom', $plugin->resolveAdminUrl('http://example.com/custom'));
        $this->assertSame('//example.com/custom', $plugin->resolveAdminUrl('//example.com/custom'));
        $this->assertSame('/custom/path', $plugin->resolveAdminUrl('/custom/path'));
        $this->assertSame('admin.php?page=test', $plugin->resolveAdminUrl('admin.php?page=test'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResolveAdminUrlWithAdminUrlFunction(): void
    {
        ensure_admin_control_stubs();
        $plugin = $this->createPluginWithMockFiles();
        $this->assertSame(
            'https://example.com/wp-admin/admin.php?page=test',
            $plugin->resolveAdminUrl('admin.php?page=test')
        );
    }

    public function testRegisterTopbarCustomMenusAndTargeting(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularAdmin = (object) ['ID' => 2, 'user_login' => 'admin2', 'roles' => ['administrator']];
        $editor = (object) ['ID' => 3, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularAdmin;
        $this->usersData[3] = $editor;

        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ID=custom-cache');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_TITLE=Cache & Tools');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ICON=dashicons-admin-tools<script>');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_TARGET=role:administrator');
        $items = '[Cache Actions] Purge Cache|admin.php?page=purge;'
            . ' [Cache Actions] Settings|admin.php?page=settings;'
            . ' External Docs|https://example.com/docs';
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ITEMS=' . $items);

        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_2_TITLE=Editor Quick Links');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_2_TARGET=role:editor');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_2_ITEMS=Posts|edit.php');

        $plugin = $this->createPluginWithMockFiles();

        $adminBarMock = new class {
            /** @var list<array<string, mixed>> */
            public array $nodes = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'add_node' && isset($arguments[0]) && is_array($arguments[0])) {
                    $this->nodes[] = $arguments[0];
                }
                return null;
            }
        };

        $plugin->registerTopbarCustomMenus($adminBarMock, $editor);
        $editorNodeIds = array_column($adminBarMock->nodes, 'id');
        $this->assertNotContains('custom-cache', $editorNodeIds);
        $this->assertContains('custom-topbar-menu-2', $editorNodeIds);
        $this->assertContains('custom-topbar-menu-2-item-1', $editorNodeIds);

        $adminBarMock->nodes = [];
        $plugin->registerTopbarCustomMenus($adminBarMock, $regularAdmin);
        $adminNodeIds = array_column($adminBarMock->nodes, 'id');
        $this->assertContains('custom-cache', $adminNodeIds);
        $this->assertContains('custom-cache-group-cache-actions', $adminNodeIds);
        $this->assertContains('custom-cache-item-1', $adminNodeIds);
        $this->assertContains('custom-cache-item-2', $adminNodeIds);
        $this->assertContains('custom-cache-item-3', $adminNodeIds);
        $this->assertNotContains('custom-topbar-menu-2', $adminNodeIds);

        $rootNode = null;
        $groupNode = null;
        $child1 = null;
        $child3 = null;
        foreach ($adminBarMock->nodes as $node) {
            if ($node['id'] === 'custom-cache') {
                $rootNode = $node;
            }
            if ($node['id'] === 'custom-cache-group-cache-actions') {
                $groupNode = $node;
            }
            if ($node['id'] === 'custom-cache-item-1') {
                $child1 = $node;
            }
            if ($node['id'] === 'custom-cache-item-3') {
                $child3 = $node;
            }
        }

        $this->assertNotNull($rootNode);
        $this->assertStringContainsString(
            '<span class="ab-icon dashicons dashicons-admin-tools"></span>',
            (string) $rootNode['title']
        );
        $this->assertStringNotContainsString('<script>', (string) $rootNode['title']);
        $this->assertStringContainsString('Cache &amp; Tools', (string) $rootNode['title']);
        $this->assertFalse($rootNode['href']);
        $this->assertSame(['class' => 'menupop'], $rootNode['meta']);

        $this->assertNotNull($groupNode);
        $this->assertSame('custom-cache', $groupNode['parent']);
        $this->assertSame('Cache Actions', $groupNode['title']);
        $this->assertFalse($groupNode['href']);
        $this->assertSame(['class' => 'menupop'], $groupNode['meta']);

        $this->assertNotNull($child1);
        $this->assertSame('custom-cache-group-cache-actions', $child1['parent']);
        $this->assertSame('Purge Cache', $child1['title']);
        $this->assertSame('admin.php?page=purge', $child1['href']);

        $this->assertNotNull($child3);
        $this->assertSame('custom-cache', $child3['parent']);
        $this->assertSame('External Docs', $child3['title']);
        $this->assertSame('https://example.com/docs', $child3['href']);
    }

    public function testTopbarCustomMenuScopeAndFrontendHead(): void
    {
        $admin = (object) ['ID' => 1, 'user_login' => 'admin', 'roles' => ['administrator']];
        $this->usersData[1] = $admin;

        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ID=menu-default');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_TITLE=Default Menu');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ITEMS=Item1|admin.php');

        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_2_ID=menu-backend');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_2_TITLE=Backend Menu');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_2_SCOPE=backend');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_2_ITEMS=Item2|admin.php');

        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_3_ID=menu-frontend');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_3_TITLE=Frontend Menu');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_3_SCOPE=frontend');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_3_ITEMS=Item3|admin.php');

        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_4_ID=menu-both');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_4_TITLE=Both Menu');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_4_SCOPE=both');
        putenv('WP_ADMIN_TOPBAR_CUSTOM_MENU_4_ITEMS=Item4|admin.php');

        $adminBarMock = new class {
            /** @var list<array<string, mixed>> */
            public array $nodes = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'add_node' && isset($arguments[0]) && is_array($arguments[0])) {
                    $this->nodes[] = $arguments[0];
                }
                return null;
            }
        };

        $this->isAdmin = true;
        $plugin = $this->createPluginWithMockFiles();
        $plugin->registerTopbarCustomMenus($adminBarMock, $admin);

        $backendNodeIds = array_column($adminBarMock->nodes, 'id');
        $this->assertContains('menu-default', $backendNodeIds);
        $this->assertContains('menu-backend', $backendNodeIds);
        $this->assertNotContains('menu-frontend', $backendNodeIds);
        $this->assertContains('menu-both', $backendNodeIds);

        $this->isAdmin = false;
        $adminBarMock->nodes = [];
        $plugin->registerTopbarCustomMenus($adminBarMock, $admin);

        $frontendNodeIds = array_column($adminBarMock->nodes, 'id');
        $this->assertNotContains('menu-default', $frontendNodeIds);
        $this->assertNotContains('menu-backend', $frontendNodeIds);
        $this->assertContains('menu-frontend', $frontendNodeIds);
        $this->assertContains('menu-both', $frontendNodeIds);

        $this->echoedHtml = '';
        $this->currentUser = null;
        $plugin->handleWpHead();
        $this->assertSame('', $this->echoedHtml);

        $this->currentUser = $admin;
        $plugin->handleWpHead();
        $this->assertStringContainsString('<style id="wp-admin-control-topbar-custom-icon">', $this->echoedHtml);
    }

    public function testShouldRemoveUserProfileAndHardening(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularAdmin = (object) ['ID' => 2, 'user_login' => 'admin2', 'roles' => ['administrator']];
        $editor = (object) ['ID' => 3, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularAdmin;
        $this->usersData[3] = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $this->assertFalse($plugin->shouldRemoveUserProfile($editor));

        putenv('WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE=!superadmins');

        $this->assertFalse($plugin->shouldRemoveUserProfile($superAdmin));
        $this->assertTrue($plugin->shouldRemoveUserProfile($regularAdmin));
        $this->assertTrue($plugin->shouldRemoveUserProfile($editor));

        $this->currentUser = $regularAdmin;
        $plugin->handleAdminHead();
        $this->assertStringContainsString('<style id="wp-admin-control-lock-profile">', $this->echoedHtml);
        $profileCss = '#wp-admin-bar-my-account > a.ab-item'
            . ' { pointer-events: none !important; cursor: default !important; }';
        $this->assertStringContainsString($profileCss, $this->echoedHtml);
        $this->assertStringContainsString('#wp-admin-bar-user-info { display: none !important; }', $this->echoedHtml);

        $this->echoedHtml = '';
        $this->currentUser = $superAdmin;
        $plugin->handleAdminHead();
        $this->assertStringNotContainsString('wp-admin-control-lock-profile', $this->echoedHtml);

        $adminBarMock = new class {
            /** @var list<string> */
            public array $removed = [];
            /** @var list<array<string, mixed>> */
            public array $added = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                    return null;
                }
                if ($name === 'get_node' && isset($arguments[0]) && $arguments[0] === 'my-account') {
                    return (object) [
                        'id' => 'my-account',
                        'title' => 'Howdy, Admin',
                        'href' => 'https://example.com/wp-admin/profile.php',
                    ];
                }
                if ($name === 'add_node' && isset($arguments[0]) && is_array($arguments[0])) {
                    $this->added[] = $arguments[0];
                    return null;
                }
                return null;
            }
        };

        $this->currentUser = $regularAdmin;
        $plugin->handleAdminBarMenu($adminBarMock);
        $this->assertContains('user-info', $adminBarMock->removed);
        $this->assertContains('my-account', $adminBarMock->removed);

        $myAccountReAdded = null;
        foreach ($adminBarMock->added as $node) {
            if ($node['id'] === 'my-account') {
                $myAccountReAdded = $node;
                break;
            }
        }

        $this->assertNotNull($myAccountReAdded);
        $this->assertFalse($myAccountReAdded['href']);
    }

    public function testShouldHideScreenOptionsAndHelpAudienceRules(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin', 'roles' => ['administrator']];
        $regularAdmin = (object) ['ID' => 2, 'user_login' => 'admin2', 'roles' => ['administrator']];
        $editor = (object) ['ID' => 3, 'user_login' => 'editor', 'roles' => ['editor']];
        $this->usersData[1] = $superAdmin;
        $this->usersData[2] = $regularAdmin;
        $this->usersData[3] = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $this->assertFalse($plugin->shouldHideScreenOptionsAndHelp($editor));

        putenv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP=all');
        $this->assertTrue($plugin->shouldHideScreenOptionsAndHelp($superAdmin));
        $this->assertTrue($plugin->shouldHideScreenOptionsAndHelp($regularAdmin));
        $this->assertTrue($plugin->shouldHideScreenOptionsAndHelp($editor));

        putenv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP=!superadmins');
        $this->assertFalse($plugin->shouldHideScreenOptionsAndHelp($superAdmin));
        $this->assertTrue($plugin->shouldHideScreenOptionsAndHelp($regularAdmin));
        $this->assertTrue($plugin->shouldHideScreenOptionsAndHelp($editor));

        putenv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP=role:editor');
        $this->assertFalse($plugin->shouldHideScreenOptionsAndHelp($superAdmin));
        $this->assertFalse($plugin->shouldHideScreenOptionsAndHelp($regularAdmin));
        $this->assertTrue($plugin->shouldHideScreenOptionsAndHelp($editor));

        $this->currentUser = $editor;
        $this->assertFalse($plugin->filterScreenOptionsShowScreen(true));

        $this->currentUser = $regularAdmin;
        $this->assertTrue($plugin->filterScreenOptionsShowScreen(true));

        $screen = new class {
            public bool $tabsRemoved = false;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_help_tabs') {
                    $this->tabsRemoved = true;
                }
                return null;
            }
        };
        $this->currentScreen = $screen;

        $this->currentUser = $editor;
        $plugin->handleAdminHead();
        $this->assertTrue($screen->tabsRemoved);
        $this->assertStringContainsString('<style id="wp-admin-control-hide-screen-options-help">', $this->echoedHtml);
        $helpCss = '#screen-meta-links, #screen-options-link-wrap,'
            . ' #contextual-help-link-wrap { display: none !important; }';
        $this->assertStringContainsString($helpCss, $this->echoedHtml);
    }

    public function testMicroMarkdownHeadingsAndUnescaping(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $markdownWithEscapes = "# Welcome\\n\\nIntro paragraph\\n- list 1\\n- list 2\\n## Second Header\\nEnd text";
        $rendered = $plugin->renderMicroMarkdown($markdownWithEscapes);

        $this->assertStringContainsString('<h2 style="margin: 0.5em 0;">Welcome</h2>', $rendered);
        $this->assertStringContainsString('<h3 style="margin: 0.5em 0;">Second Header</h3>', $rendered);
        $this->assertStringContainsString('<ul>', $rendered);
        $this->assertStringContainsString('<li>list 1</li>', $rendered);
        $this->assertStringContainsString('<li>list 2</li>', $rendered);
        $this->assertStringContainsString('</ul>', $rendered);
        $this->assertStringContainsString('Intro paragraph', $rendered);
        $this->assertStringContainsString('End text', $rendered);

        $this->assertStringNotContainsString('<h2', $plugin->renderMicroMarkdown('#NoSpace'));

        $headingCases = [
            ['# H1', '<h2 style="margin: 0.5em 0;">H1</h2>'],
            ['## H2', '<h3 style="margin: 0.5em 0;">H2</h3>'],
            ['### H3', '<h4 style="margin: 0.5em 0;">H3</h4>'],
            ['#### H4', '<h4 style="margin: 0.5em 0;">H4</h4>'],
        ];

        foreach ($headingCases as [$input, $expected]) {
            $this->assertStringContainsString($expected, $plugin->renderMicroMarkdown($input));
        }
    }

    public function testContentAccessRulesComprehensiveTargetsAndFrontendEditButton(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $editor = $this->createMockUser(2, 'editor_user', ['editor']);
        $author = $this->createMockUser(3, 'author_user', ['author']);
        $contributor = $this->createMockUser(4, 'contrib_user', ['contributor']);

        $this->usersData = [
            1 => $superAdmin,
            2 => $editor,
            3 => $author,
            4 => $contributor,
        ];
        $this->allUsers = [$superAdmin, $editor, $author, $contributor];

        $post42 = $this->createMockPost(42, 'privacy-policy', 'post', 'publish');
        $post108 = $this->createMockPost(108, 'terms-of-service', 'post', 'publish');
        $post201 = $this->createMockPost(201, 'about-us', 'post', 'publish');
        $post202 = $this->createMockPost(202, 'contact', 'page', 'publish');
        $post203 = $this->createMockPost(203, 'news-entry', 'post', 'publish');
        $post300 = $this->createMockPost(300, 'unrestricted-blog', 'post', 'publish');

        $this->postsData = [
            42 => $post42,
            108 => $post108,
            201 => $post201,
            202 => $post202,
            203 => $post203,
            300 => $post300,
        ];

        $contentRules = [
            'id:42|role:editor|action:block',
            '108|role:editor|action:block',
            'slug:about-us|!superadmins|action:block',
            'page:contact|role:editor|action:block',
            'post:news-entry|role:author|action:block',
        ];
        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=' . implode('; ', $contentRules));

        $plugin = $this->createPluginWithMockFiles();

        $this->assertSame(['do_not_allow'], $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 2, [42]));
        $this->assertSame(['do_not_allow'], $plugin->filterMapMetaCap(['edit_pages'], 'edit_page', 2, [202]));
        $this->assertSame(['do_not_allow'], $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 3, [203]));
        $this->assertSame(['edit_posts'], $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 2, [203]));
        $this->assertSame(['do_not_allow'], $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 4, [201]));
        $this->assertSame(['edit_posts'], $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 1, [42]));
        $this->assertSame(['edit_pages'], $plugin->filterMapMetaCap(['edit_pages'], 'edit_page', 1, [202]));
        $this->assertSame(['edit_posts'], $plugin->filterMapMetaCap(['edit_posts'], 'edit_post', 2, [300]));

        $this->currentUser = $editor;
        $this->isAdmin = true;
        $GLOBALS['pagenow'] = 'post.php';
        $_GET['post'] = '42';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);

        $this->dieCalls = [];
        $_GET = [];
        $_POST['post_ID'] = '202';
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame(403, $this->dieCalls[0]['title']);

        $this->dieCalls = [];
        $_POST = [];
        $_GET['post'] = '300';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $this->currentUser = $superAdmin;
        $_GET['post'] = '42';
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $this->currentUser = $editor;
        $GLOBALS['pagenow'] = 'edit.php';
        $query = new class {
            /** @var array<string, mixed> */
            public array $params = [];

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

        $plugin->filterPreGetPosts($query);
        $this->assertSame([42, 108, 201, 202], $query->params['post__not_in']);

        $pageCounts = (object) ['publish' => '10'];
        $filteredPageCounts = $plugin->filterCountPosts($pageCounts, 'page', 'readable');
        $this->assertSame('9', $filteredPageCounts->publish);

        $postCounts = (object) ['publish' => '20'];
        $filteredPostCounts = $plugin->filterCountPosts($postCounts, 'post', 'readable');
        $this->assertSame('17', $filteredPostCounts->publish);

        $this->isAdmin = false;
        $this->currentUser = $editor;
        $this->queriedObjectId = 202;

        $adminBarMock = new class {
            /** @var list<string> */
            public array $removedNodes = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removedNodes[] = $arguments[0];
                }
                return null;
            }
        };

        $plugin->handleAdminBarMenu($adminBarMock);
        $this->assertContains('edit', $adminBarMock->removedNodes);

        $adminBarMockUnrestricted = new class {
            /** @var list<string> */
            public array $removedNodes = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removedNodes[] = $arguments[0];
                }
                return null;
            }
        };
        $this->queriedObjectId = 300;
        $plugin->handleAdminBarMenu($adminBarMockUnrestricted);
        $this->assertNotContains('edit', $adminBarMockUnrestricted->removedNodes);

        $adminBarMockSuperAdmin = new class {
            /** @var list<string> */
            public array $removedNodes = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removedNodes[] = $arguments[0];
                }
                return null;
            }
        };
        $this->currentUser = $superAdmin;
        $this->queriedObjectId = 202;
        $plugin->handleAdminBarMenu($adminBarMockSuperAdmin);
        $this->assertNotContains('edit', $adminBarMockSuperAdmin->removedNodes);
    }

    public function testContentAccessRulesTargetResolutionAndEdgeCases(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $editor = $this->createMockUser(2, 'editor_user', ['editor']);
        $this->usersData = [1 => $superAdmin, 2 => $editor];
        $this->allUsers = [$superAdmin, $editor];

        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=sample-slug|role:editor|action:block; id:500|action:block');

        $samplePost = $this->createMockPost(50, 'sample-slug', 'post', 'publish');
        $blockAllPost = $this->createMockPost(500, 'block-all-sample', 'post', 'publish');
        $this->postsData = [
            50 => $samplePost,
            500 => $blockAllPost,
        ];

        $plugin = $this->createPluginWithMockFiles();

        $isPostRestrictedRef = new ReflectionMethod(WpAdminControl::class, 'isPostRestrictedForUser');
        $this->assertTrue($isPostRestrictedRef->invoke($plugin, 50, $editor));
        $this->assertFalse($isPostRestrictedRef->invoke($plugin, 50, $superAdmin));
        $this->assertTrue($isPostRestrictedRef->invoke($plugin, 500, $editor));
        $this->assertFalse($isPostRestrictedRef->invoke($plugin, 500, $superAdmin));
        $this->assertFalse($isPostRestrictedRef->invoke($plugin, 0, $editor));

        $resolveTargetRef = new ReflectionMethod(WpAdminControl::class, 'resolveContentTargetToPostId');
        $this->assertNull($resolveTargetRef->invoke($plugin, ''));
        $this->assertSame(99, $resolveTargetRef->invoke($plugin, 'id:99'));
        $this->assertSame(123, $resolveTargetRef->invoke($plugin, '123'));
        $this->assertSame(50, $resolveTargetRef->invoke($plugin, 'sample-slug'));
        $this->assertNull($resolveTargetRef->invoke($plugin, 'nonexistent-slug'));

        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=2,3,4,89|!superadmins');
        $pluginCompact = $this->createPluginWithMockFiles();
        $getRestrictedRef = new ReflectionMethod(WpAdminControl::class, 'getRestrictedPostIdsForUser');
        $this->assertSame([2, 3, 4, 89], $getRestrictedRef->invoke($pluginCompact, $editor));
        $this->assertSame([], $getRestrictedRef->invoke($pluginCompact, $superAdmin));
    }

    public function testAdminFooterControlsDefaultBehaviorWhenUnset(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->currentUser = $superAdmin;
        $plugin = $this->createPluginWithMockFiles();

        $defaultLeft = 'Thank you for creating with WordPress.';
        $defaultRight = 'Version 6.7';

        $this->assertSame($defaultLeft, $plugin->filterAdminFooterText($defaultLeft));
        $this->assertSame($defaultRight, $plugin->filterUpdateFooter($defaultRight));
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($superAdmin));

        $this->echoedHtml = '';
        $plugin->handleAdminHead();
        $this->assertStringNotContainsString('wp-admin-control-hide-footer', $this->echoedHtml);
    }

    public function testAdminFooterControlsRemoveForNonSuperadminAndPreserveForSuperadmin(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $editor = $this->createMockUser(2, 'editor_user', ['editor']);
        $this->usersData = [1 => $superAdmin, 2 => $editor];
        $this->allUsers = [$superAdmin, $editor];

        putenv('WP_ADMIN_FOOTER_LEFT=remove|!superadmins');
        putenv('WP_ADMIN_FOOTER_RIGHT=remove|!superadmins');

        $plugin = $this->createPluginWithMockFiles();

        $defaultLeft = 'Thank you for creating with WordPress.';
        $defaultRight = 'Version 6.7';

        $this->currentUser = $editor;
        $this->assertSame('', $plugin->filterAdminFooterText($defaultLeft));
        $this->assertSame('', $plugin->filterUpdateFooter($defaultRight));
        $this->assertTrue($plugin->shouldHideAdminFooterContainer($editor));

        $this->echoedHtml = '';
        $plugin->handleAdminHead();
        $this->assertStringContainsString('<style id="wp-admin-control-hide-footer">', $this->echoedHtml);
        $this->assertStringContainsString('#wpfooter { display: none !important; }', $this->echoedHtml);

        $this->currentUser = $superAdmin;
        $this->assertSame($defaultLeft, $plugin->filterAdminFooterText($defaultLeft));
        $this->assertSame($defaultRight, $plugin->filterUpdateFooter($defaultRight));
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($superAdmin));

        $this->echoedHtml = '';
        $plugin->handleAdminHead();
        $this->assertStringNotContainsString('wp-admin-control-hide-footer', $this->echoedHtml);
    }

    public function testAdminFooterControlsCustomStringsAndMultipleAudienceStatements(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $editor = $this->createMockUser(2, 'editor_user', ['editor']);
        $standardAdmin = $this->createMockUser(3, 'standard_admin', ['administrator']);
        $author = $this->createMockUser(4, 'author_user', ['author']);
        $this->usersData = [1 => $superAdmin, 2 => $editor, 3 => $standardAdmin, 4 => $author];
        $this->allUsers = [$superAdmin, $editor, $standardAdmin, $author];

        putenv('WP_ADMIN_FOOTER_LEFT=© 2026 Example Corp|role:editor; remove|!superadmins');
        putenv('WP_ADMIN_FOOTER_RIGHT=v2.1.0|user:3; remove|!superadmins');

        $plugin = $this->createPluginWithMockFiles();

        $defaultLeft = 'Thank you for creating with WordPress.';
        $defaultRight = 'Version 6.7';

        $this->currentUser = $editor;
        $this->assertSame('© 2026 Example Corp', $plugin->filterAdminFooterText($defaultLeft));
        $this->assertSame('', $plugin->filterUpdateFooter($defaultRight));
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($editor));

        $this->currentUser = $standardAdmin;
        $this->assertSame('', $plugin->filterAdminFooterText($defaultLeft));
        $this->assertSame('v2.1.0', $plugin->filterUpdateFooter($defaultRight));
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($standardAdmin));

        $this->currentUser = $author;
        $this->assertSame('', $plugin->filterAdminFooterText($defaultLeft));
        $this->assertSame('', $plugin->filterUpdateFooter($defaultRight));
        $this->assertTrue($plugin->shouldHideAdminFooterContainer($author));

        $this->currentUser = $superAdmin;
        $this->assertSame($defaultLeft, $plugin->filterAdminFooterText($defaultLeft));
        $this->assertSame($defaultRight, $plugin->filterUpdateFooter($defaultRight));
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($superAdmin));
    }

    public function testAdminFooterControlsEdgeCasesAndHtmlSupport(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $this->assertNull($plugin->getCustomFooterText('WP_ADMIN_FOOTER_LEFT', null));
        $this->assertFalse($plugin->shouldHideAdminFooterContainer(null));

        putenv('WP_ADMIN_FOOTER_LEFT=; ; <a href="https://example.com">Site</a>|all; ;');
        putenv('WP_ADMIN_FOOTER_RIGHT=remove');

        $user = $this->createMockUser(5, 'any_user', ['subscriber']);
        $this->currentUser = $user;

        $this->assertSame('<a href="https://example.com">Site</a>', $plugin->filterAdminFooterText('Old Left'));
        $this->assertSame('', $plugin->filterUpdateFooter('Old Right'));
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($user));

        putenv('WP_ADMIN_FOOTER_LEFT=remove');
        putenv('WP_ADMIN_FOOTER_RIGHT=');
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($user));

        putenv('WP_ADMIN_FOOTER_LEFT=');
        putenv('WP_ADMIN_FOOTER_RIGHT=remove');
        $this->assertFalse($plugin->shouldHideAdminFooterContainer($user));
    }

    public function testFilterViewsEditPostsDeductsRestrictedMineAndUnsetsWhenZero(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $editor = $this->createMockUser(2, 'editor_user', ['editor']);
        $this->usersData = [1 => $superAdmin, 2 => $editor];
        $this->allUsers = [$superAdmin, $editor];

        $page10 = $this->createMockPost(10, 'restricted-page-1', 'page', 'publish', 2);
        $page11 = $this->createMockPost(11, 'restricted-page-2', 'page', 'publish', 2);
        $page12 = $this->createMockPost(12, 'restricted-page-3', 'page', 'publish', 2);
        $page13 = $this->createMockPost(13, 'unrestricted-page', 'page', 'publish', 2);
        $this->postsData = [
            10 => $page10,
            11 => $page11,
            12 => $page12,
            13 => $page13,
        ];

        putenv('WP_ADMIN_CONTENT_ACCESS_RULES=10,11,12|!superadmins');
        $plugin = $this->createPluginWithMockFiles();

        $this->currentScreen = (object) ['id' => 'edit-page', 'post_type' => 'page'];
        $_GET['post_type'] = 'page';

        $initialViewsAll = [
            'all' => '<a href="edit.php?post_type=page">All <span class="count">(1)</span></a>',
            'mine' => '<a href="edit.php?post_type=page&author=2">Mine <span class="count">(4)</span></a>',
        ];

        $this->currentUser = $editor;
        $filteredAll = $plugin->filterViewsEditPosts($initialViewsAll);
        $this->assertArrayNotHasKey('mine', $filteredAll);

        $viewsOmittedByCore = [
            'all' => '<a href="edit.php?post_type=page">All <span class="count">(2)</span></a>',
            'publish' => '<a href="edit.php?post_type=page">Published <span class="count">(2)</span></a>',
        ];
        $filteredRestored = $plugin->filterViewsEditPosts($viewsOmittedByCore);
        $this->assertArrayHasKey('mine', $filteredRestored);
        $this->assertStringContainsString('<span class="count">(1)</span>', $filteredRestored['mine']);
        $this->assertStringContainsString('author=2', $filteredRestored['mine']);
        $this->assertSame(['all', 'mine', 'publish'], array_keys($filteredRestored));

        $initialViewsZero = [
            'all' => '<a href="edit.php?post_type=page">All <span class="count">(1)</span></a>',
            'mine' => '<a href="edit.php?post_type=page&author=2">Mine <span class="count">(3)</span></a>',
        ];
        $filteredZero = $plugin->filterViewsEditPosts($initialViewsZero);
        $this->assertArrayNotHasKey('mine', $filteredZero);

        $initialViewsPartial = [
            'all' => '<a href="edit.php?post_type=page">All <span class="count">(5)</span></a>',
            'mine' => '<a href="edit.php?post_type=page&author=2">Mine <span class="count">(5)</span></a>',
        ];
        $filteredPartial = $plugin->filterViewsEditPosts($initialViewsPartial);
        $this->assertArrayHasKey('mine', $filteredPartial);
        $this->assertStringContainsString('<span class="count">(2)</span>', $filteredPartial['mine']);

        $this->currentUser = $superAdmin;
        $superViews = $plugin->filterViewsEditPosts($initialViewsAll);
        $this->assertSame($initialViewsAll, $superViews);
    }

    public function testBeforeAdminBarRenderRemovesLateNodesAndInjectsCss(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $editor = $this->createMockUser(2, 'editor_user', ['editor']);
        $this->usersData = [1 => $superAdmin, 2 => $editor];
        $this->allUsers = [$superAdmin, $editor];

        putenv('WP_ADMIN_TOPBAR_MENU_REMOVE=elementor_edit_page|!superadmins');
        $plugin = $this->createPluginWithMockFiles();

        $adminBarMock = new class {
            /** @var list<string> */
            public array $removedNodes = [];

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->removedNodes[] = $arguments[0];
                }
                return null;
            }
        };
        $GLOBALS['wp_admin_bar'] = $adminBarMock;

        $this->currentUser = $editor;
        $plugin->handleBeforeAdminBarRender();
        $this->assertContains('elementor_edit_page', $adminBarMock->removedNodes);

        $this->echoedHtml = '';
        $plugin->handleAdminHead();
        $this->assertStringContainsString('<style id="wp-admin-control-hide-topbar-nodes">', $this->echoedHtml);
        $this->assertStringContainsString(
            '#wp-admin-bar-elementor_edit_page { display: none !important; }',
            $this->echoedHtml
        );

        $this->echoedHtml = '';
        $this->queriedObjectId = 999;
        $plugin->handleWpHead();
        $this->assertStringContainsString('<style id="wp-admin-control-hide-topbar-nodes">', $this->echoedHtml);
        $this->assertStringContainsString(
            '#wp-admin-bar-elementor_edit_page { display: none !important; }',
            $this->echoedHtml
        );

        $this->currentUser = $superAdmin;
        $this->echoedHtml = '';
        $plugin->handleAdminHead();
        $this->assertStringNotContainsString('wp-admin-control-hide-topbar-nodes', $this->echoedHtml);

        unset($GLOBALS['wp_admin_bar']);
    }

    public function testFilterScreenColumnsRemovesTargetColumnsForRestrictedUser(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $editor = $this->createMockUser(2, 'editor_user', ['editor']);
        $this->usersData = [1 => $superAdmin, 2 => $editor];
        $this->allUsers = [$superAdmin, $editor];

        putenv('WP_ADMIN_HIDDEN_COLUMNS=author,rocket_insights,wpseo-focuskw|role:editor');
        $plugin = $this->createPluginWithMockFiles();

        $initialColumns = [
            'cb' => '<input type="checkbox" />',
            'title' => 'Title',
            'author' => 'Author',
            'date' => 'Date',
            'rocket_insights' => 'Rocket Insights',
            'wpseo-focuskw' => 'Keyphrase',
        ];

        $this->currentUser = $editor;
        $filteredEditorColumns = $plugin->filterScreenColumns($initialColumns);
        $this->assertSame([
            'cb' => '<input type="checkbox" />',
            'title' => 'Title',
            'date' => 'Date',
        ], $filteredEditorColumns);

        $this->currentUser = $superAdmin;
        $filteredSuperColumns = $plugin->filterScreenColumns($initialColumns);
        $this->assertSame($initialColumns, $filteredSuperColumns);

        $this->currentUser = null;
        $this->assertSame($initialColumns, $plugin->filterScreenColumns($initialColumns));

        putenv('WP_ADMIN_HIDDEN_COLUMNS');
        $this->currentUser = $editor;
        $this->assertSame($initialColumns, $plugin->filterScreenColumns($initialColumns));
    }

    public function testHandleCurrentScreenHooksDynamicScreenColumnsFilter(): void
    {
        $addedFilters = [];
        $plugin = $this->createPluginWithMockFiles(dependencies: [
            'filterAdder' => static function (
                string $tag,
                mixed $callback,
                int $prio,
                int $args
            ) use (&$addedFilters): void {
                $addedFilters[$tag] = ['callback' => $callback, 'priority' => $prio, 'args' => $args];
            },
        ]);

        $screen = (object) ['id' => 'upload'];
        $plugin->handleCurrentScreen($screen);

        $this->assertArrayHasKey('manage_upload_columns', $addedFilters);
        $this->assertSame(999, $addedFilters['manage_upload_columns']['priority']);
    }

    public function testResolveMetaBoxTargetIdsAndGutenbergPanelIds(): void
    {
        $plugin = $this->createPluginWithMockFiles();

        $this->assertSame(['tagsdiv-post_tag', 'post_tag', 'tags'], $plugin->resolveMetaBoxTargetIds('tags'));
        $this->assertSame(['categorydiv', 'category', 'categories'], $plugin->resolveMetaBoxTargetIds('category'));
        $this->assertSame('taxonomy-panel-post_tag', $plugin->resolveGutenbergPanelId('tags'));
        $this->assertSame('taxonomy-panel-category', $plugin->resolveGutenbergPanelId('categories'));
        $this->assertSame('featured-image', $plugin->resolveGutenbergPanelId('featured_image'));
        $this->assertSame('post-excerpt', $plugin->resolveGutenbergPanelId('excerpt'));
        $this->assertSame('discussion-panel', $plugin->resolveGutenbergPanelId('discussion'));
        $this->assertSame('page-attributes', $plugin->resolveGutenbergPanelId('page_attributes'));
        $this->assertSame('page-attributes', $plugin->resolveGutenbergPanelId('template'));
        $this->assertSame(
            ['pageparentdiv', 'page-attributes', 'template'],
            $plugin->resolveMetaBoxTargetIds('template')
        );
        $this->assertSame(['authordiv', 'author'], $plugin->resolveMetaBoxTargetIds('author'));
        $this->assertSame('yoast-seo', $plugin->resolveGutenbergPanelId('yoast'));
        $this->assertSame('taxonomy-panel-custom', $plugin->resolveGutenbergPanelId('taxonomy-panel-custom'));
        $this->assertNull($plugin->resolveGutenbergPanelId('perfmatters'));
        $this->assertSame(['perfmatters'], $plugin->resolveMetaBoxTargetIds('perfmatters'));
    }

    public function testHandleMetaBoxesSetupRemovesBoxesFromRegistryAndCallsRemover(): void
    {
        putenv('WP_ADMIN_HIDDEN_META_BOXES=tags; perfmatters,rocket_post_exclude|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $GLOBALS['wp_meta_boxes'] = [
            'post' => [
                'side' => [
                    'default' => [
                        'tagsdiv-post_tag' => ['id' => 'tagsdiv-post_tag'],
                        'perfmatters' => ['id' => 'perfmatters'],
                        'rocket_post_exclude' => ['id' => 'rocket_post_exclude'],
                    ],
                ],
                'normal' => [
                    'default' => [
                        'keep_this_box' => ['id' => 'keep_this_box'],
                    ],
                ],
            ],
        ];

        $plugin = $this->createPluginWithMockFiles();
        $plugin->handleMetaBoxesSetup('post');

        $this->assertArrayNotHasKey('tagsdiv-post_tag', $GLOBALS['wp_meta_boxes']['post']['side']['default']);
        $this->assertArrayNotHasKey('perfmatters', $GLOBALS['wp_meta_boxes']['post']['side']['default']);
        $this->assertArrayNotHasKey('rocket_post_exclude', $GLOBALS['wp_meta_boxes']['post']['side']['default']);
        $this->assertArrayHasKey('keep_this_box', $GLOBALS['wp_meta_boxes']['post']['normal']['default']);

        $removedIds = array_column($this->removedMetaBoxes, 0);
        $this->assertContains('tagsdiv-post_tag', $removedIds);
        $this->assertContains('perfmatters', $removedIds);
        $this->assertContains('rocket_post_exclude', $removedIds);

        unset($GLOBALS['wp_meta_boxes']);
        putenv('WP_ADMIN_HIDDEN_META_BOXES');
    }

    public function testHandleMetaBoxesSetupPreservesForSuperAdminAndEmptyEnv(): void
    {
        putenv('WP_ADMIN_HIDDEN_META_BOXES=tags,perfmatters|!superadmins');
        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;

        $GLOBALS['wp_meta_boxes'] = [
            'post' => [
                'side' => [
                    'default' => [
                        'tagsdiv-post_tag' => ['id' => 'tagsdiv-post_tag'],
                        'perfmatters' => ['id' => 'perfmatters'],
                    ],
                ],
            ],
        ];

        $plugin = $this->createPluginWithMockFiles();
        $plugin->handleMetaBoxesSetup('post');

        $this->assertArrayHasKey('tagsdiv-post_tag', $GLOBALS['wp_meta_boxes']['post']['side']['default']);
        $this->assertArrayHasKey('perfmatters', $GLOBALS['wp_meta_boxes']['post']['side']['default']);
        $this->assertSame([], $this->removedMetaBoxes);

        putenv('WP_ADMIN_HIDDEN_META_BOXES');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->currentUser = $editor;
        $plugin->handleMetaBoxesSetup('post');

        $this->assertArrayHasKey('tagsdiv-post_tag', $GLOBALS['wp_meta_boxes']['post']['side']['default']);
        $this->assertSame([], $this->removedMetaBoxes);

        unset($GLOBALS['wp_meta_boxes']);
    }

    public function testHandleBlockEditorAssetsEnqueuesInlineScriptAndFooterOutputsFallback(): void
    {
        putenv('WP_ADMIN_HIDDEN_META_BOXES=tags; perfmatters,rocket_post_exclude|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $capturedHandle = '';
        $capturedScript = '';
        $plugin = $this->createPluginWithMockFiles(dependencies: [
            'inlineScriptAdder' => static function (
                string $handle,
                string $data,
                string $position
            ) use (
                &$capturedHandle,
                &$capturedScript
            ): bool {
                $capturedHandle = $handle;
                $capturedScript = $data;
                return true;
            },
        ]);

        $plugin->handleBlockEditorAssets();
        $this->assertSame('wp-edit-post', $capturedHandle);
        $this->assertStringContainsString('taxonomy-panel-post_tag', $capturedScript);
        $this->assertStringContainsString('removeEditorPanel', $capturedScript);

        $plugin->handleAdminPrintFooterScripts();
        $this->assertStringContainsString('<script id="wp-admin-control-gutenberg-panels">', $this->echoedHtml);
        $this->assertStringContainsString('taxonomy-panel-post_tag', $this->echoedHtml);

        $plugin->handleAdminHead();
        $this->assertStringContainsString('<style id="wp-admin-control-hide-meta-boxes">', $this->echoedHtml);
        $this->assertStringContainsString('#perfmatters', $this->echoedHtml);
        $this->assertStringContainsString('#rocket_post_exclude', $this->echoedHtml);
        $this->assertStringContainsString('components-panel__body', $this->echoedHtml);

        putenv('WP_ADMIN_HIDDEN_META_BOXES');
    }

    public function testPostTypeSupportRemovalForAuthorAndTemplate(): void
    {
        putenv('WP_ADMIN_HIDDEN_META_BOXES=author,template|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $plugin->handleAdminInit();
        $this->assertContains(['post', 'author'], $this->removedPostTypeSupports);
        $this->assertContains(['page', 'author'], $this->removedPostTypeSupports);
        $this->assertContains(['post', 'page-attributes'], $this->removedPostTypeSupports);
        $this->assertContains(['page', 'page-attributes'], $this->removedPostTypeSupports);

        $this->removedPostTypeSupports = [];
        $plugin->handleMetaBoxesSetup('post');
        $this->assertContains(['post', 'author'], $this->removedPostTypeSupports);
        $this->assertContains(['post', 'page-attributes'], $this->removedPostTypeSupports);

        $this->removedPostTypeSupports = [];
        $plugin->handleRestApiInit();
        $this->assertContains(['post', 'author'], $this->removedPostTypeSupports);

        $this->removedPostTypeSupports = [];
        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;

        $plugin->handleAdminInit();
        $this->assertSame([], $this->removedPostTypeSupports);

        putenv('WP_ADMIN_HIDDEN_META_BOXES');
    }

    public function testFilterBlockEditorSettingsAllDisablesTemplateMode(): void
    {
        putenv('WP_ADMIN_HIDDEN_META_BOXES=template|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();
        $settings = ['supportsTemplateMode' => true, 'title' => 'Sample'];
        $filtered = $plugin->filterBlockEditorSettingsAll($settings);
        $this->assertFalse($filtered['supportsTemplateMode']);

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;
        $superFiltered = $plugin->filterBlockEditorSettingsAll($settings);
        $this->assertTrue($superFiltered['supportsTemplateMode']);

        putenv('WP_ADMIN_HIDDEN_META_BOXES');
        $this->currentUser = $editor;
        $emptyFiltered = $plugin->filterBlockEditorSettingsAll($settings);
        $this->assertTrue($emptyFiltered['supportsTemplateMode']);
    }

    public function testFilterInsertPostDataEnforcesAuthorLockdown(): void
    {
        putenv('WP_ADMIN_HIDDEN_META_BOXES=author|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $existingPost = $this->createMockPost(101, 'test-item', 'post', 'publish', 42);
        $this->postsData[101] = $existingPost;

        $plugin = $this->createPluginWithMockFiles();

        $updateData = $plugin->filterInsertPostData(
            ['post_author' => 99, 'post_title' => 'Updated'],
            ['ID' => 101],
            [],
            true
        );
        $this->assertSame('42', $updateData['post_author']);

        $insertData = $plugin->filterInsertPostData(
            ['post_author' => 99, 'post_title' => 'Created'],
            [],
            [],
            false
        );
        $this->assertSame('10', $insertData['post_author']);

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;

        $superData = $plugin->filterInsertPostData(
            ['post_author' => 99, 'post_title' => 'Super Edit'],
            ['ID' => 101],
            [],
            true
        );
        $this->assertSame(99, $superData['post_author']);

        putenv('WP_ADMIN_HIDDEN_META_BOXES');
    }

    public function testHandleAdminHeadOutputsAuthorAndTemplateCssSelectors(): void
    {
        putenv('WP_ADMIN_HIDDEN_META_BOXES=author,template|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();
        $plugin->handleAdminHead();

        $this->assertStringContainsString('.editor-post-author', $this->echoedHtml);
        $this->assertStringContainsString('.editor-post-template', $this->echoedHtml);

        putenv('WP_ADMIN_HIDDEN_META_BOXES');
    }

    public function testAdminNoticesSuppression(): void
    {
        putenv('WP_ADMIN_HIDE_NOTICES=!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();
        $this->assertTrue($plugin->shouldHideAdminNotices($editor));

        $plugin->handleAdminPrintScripts();
        $this->assertCount(4, $this->removedAllActions);
        $this->assertContains(['admin_notices', false], $this->removedAllActions);
        $this->assertContains(['all_admin_notices', false], $this->removedAllActions);
        $this->assertContains(['user_admin_notices', false], $this->removedAllActions);
        $this->assertContains(['network_admin_notices', false], $this->removedAllActions);
        $this->assertContains('wp-pointer', $this->dequeuedStyles);
        $this->assertContains('wp-pointer', $this->dequeuedScripts);

        $plugin->handleAdminEnqueueScripts();
        $this->assertCount(2, array_keys($this->dequeuedStyles, 'wp-pointer', true));

        $plugin->handleAdminHead();
        $this->assertStringContainsString('#wpbody-content > .notice:not(.inline)', $this->echoedHtml);
        $this->assertStringContainsString('.wp-pointer', $this->echoedHtml);

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;
        $this->removedAllActions = [];
        $this->dequeuedStyles = [];
        $this->dequeuedScripts = [];
        $this->echoedHtml = '';

        $this->assertFalse($plugin->shouldHideAdminNotices($superadmin));
        $plugin->handleAdminPrintScripts();
        $this->assertSame([], $this->removedAllActions);
        $this->assertSame([], $this->dequeuedStyles);
        $this->assertSame([], $this->dequeuedScripts);
        $plugin->handleAdminHead();
        $this->assertStringNotContainsString('#wpbody-content > .notice:not(.inline)', $this->echoedHtml);

        putenv('WP_ADMIN_HIDE_NOTICES=true');
        $this->assertTrue($plugin->shouldHideAdminNotices($superadmin));

        putenv('WP_ADMIN_HIDE_NOTICES');
        $this->assertFalse($plugin->shouldHideAdminNotices($editor));
    }

    public function testGutenbergBlocksRemoval(): void
    {
        putenv('WP_ADMIN_GUTENBERG_BLOCKS_REMOVE=archives,calendar|!superadmins;rss|all');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles(dependencies: [
            'registeredBlockTypesGetter' => static fn (): array => [
                'core/paragraph',
                'core/archives',
                'core/calendar',
                'core/rss',
                'core/image',
            ],
        ]);

        $this->assertContains('core/archives', $plugin->getGutenbergBlocksToRemoveForUser($editor));
        $this->assertContains('core/calendar', $plugin->getGutenbergBlocksToRemoveForUser($editor));
        $this->assertContains('core/rss', $plugin->getGutenbergBlocksToRemoveForUser($editor));

        $blocks = ['core/paragraph', 'core/archives', 'core/calendar', 'core/rss', 'core/image'];
        $filtered = $plugin->filterAllowedBlockTypesAll($blocks);
        $this->assertSame(['core/paragraph', 'core/image'], $filtered);

        $fromRegistry = $plugin->filterAllowedBlockTypesAll(true);
        $this->assertSame(['core/paragraph', 'core/image'], $fromRegistry);

        $this->assertFalse($plugin->filterAllowedBlockTypesAll(false));

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;

        $superFiltered = $plugin->filterAllowedBlockTypesAll($blocks);
        $this->assertSame(['core/paragraph', 'core/archives', 'core/calendar', 'core/image'], $superFiltered);

        putenv('WP_ADMIN_GUTENBERG_BLOCKS_REMOVE');
        $this->assertSame($blocks, $plugin->filterAllowedBlockTypesAll($blocks));
    }

    public function testGutenbergBlocksAliasesAndCategories(): void
    {
        putenv('WP_ADMIN_GUTENBERG_BLOCKS_REMOVE=category:theme,custom/*,loginout,query-loop|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $blocks = [
            'core/paragraph',
            'core/loginout',
            'core/query',
            'core/site-logo',
            'core/post-time-to-read',
            'custom/banner',
            'core/image',
        ];

        $filtered = $plugin->filterAllowedBlockTypesAll($blocks);
        $this->assertSame(['core/paragraph', 'core/image'], $filtered);

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;
        $this->assertSame($blocks, $plugin->filterAllowedBlockTypesAll($blocks));
    }

    public function testProfileFieldsRemoval(): void
    {
        $fieldsRule = 'syntax_highlighting,color_scheme,shortcuts,toolbar,website,aim|!superadmins';
        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE=' . $fieldsRule);
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $this->assertSame(
            ['syntax_highlighting', 'color_scheme', 'shortcuts', 'toolbar', 'website', 'aim'],
            $plugin->getProfileFieldsToRemoveForUser($editor)
        );

        $contacts = [
            'website' => 'https://example.com',
            'aim' => 'aim_user',
            'jabber' => 'jabber_user',
        ];
        $filteredContacts = $plugin->filterUserContactMethods($contacts, $editor);
        $this->assertSame(['jabber' => 'jabber_user'], $filteredContacts);

        $this->assertFalse($plugin->filterShowAdminBar(true));

        $plugin->handleAdminInit();
        $this->assertContains(['admin_color_scheme_picker', 'admin_color_scheme_picker', 10], $this->removedActions);

        $plugin->handleAdminHead();
        $this->assertStringContainsString('tr.user-syntax-highlighting-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-admin-color-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-comment-shortcuts-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-admin-bar-front-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-url-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-aim-wrap', $this->echoedHtml);

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;

        $this->assertSame([], $plugin->getProfileFieldsToRemoveForUser($superadmin));
        $this->assertSame($contacts, $plugin->filterUserContactMethods($contacts, $superadmin));
        $this->assertTrue($plugin->filterShowAdminBar(true));

        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE');
        $this->assertSame([], $plugin->getProfileFieldsToRemoveForUser($editor));
    }

    public function testProfileFieldsExtendedSelectorsAndScript(): void
    {
        $fieldsRule = 'user_login,url,description,profile_picture,elementor_ai,wordfence,yoast|!superadmins';
        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE=' . $fieldsRule);
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $contacts = [
            'url' => 'https://example.com',
            'custom_skype' => 'skype_user',
        ];
        $filteredContacts = $plugin->filterUserContactMethods($contacts, $editor);
        $this->assertSame(['custom_skype' => 'skype_user'], $filteredContacts);

        $plugin->handleAdminHead();
        $this->assertStringContainsString('tr.user-user-login-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-url-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-description-wrap', $this->echoedHtml);
        $this->assertStringContainsString('tr.user-profile-picture-wrap', $this->echoedHtml);
        $this->assertStringContainsString('#wfls-user-settings', $this->echoedHtml);
        $this->assertStringContainsString('#yoast-seo-settings', $this->echoedHtml);
        $this->assertStringContainsString('elementor_enable_ai', $this->echoedHtml);
        $this->assertStringContainsString('wp-admin-control-clean-profile-sections', $this->echoedHtml);
        $this->assertStringContainsString('removeProfileElements', $this->echoedHtml);
        $this->assertStringNotContainsString('wp-admin-control-hide-profile-fields', $this->echoedHtml);

        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE');
    }

    public function testProfileFieldsServerSideDomRemoval(): void
    {
        $fieldsRule = 'user_login,url,description,profile_picture,wordfence|!superadmins';
        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE=' . $fieldsRule);
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $sampleHtml = <<<'HTML'
<form id="your-profile" action="profile.php" method="post">
  <h2>Name</h2>
  <table class="form-table">
    <tr class="user-user-login-wrap">
      <th><label for="user_login">Username</label></th>
      <td><input type="text" name="user_login" id="user_login" value="editor_bob" disabled="disabled" /></td>
    </tr>
    <tr class="user-first-name-wrap">
      <th><label for="first_name">First Name</label></th>
      <td><input type="text" name="first_name" id="first_name" value="Bob" /></td>
    </tr>
  </table>
  <h2>Contact Info</h2>
  <table class="form-table">
    <tr class="user-url-wrap">
      <th><label for="url">Website</label></th>
      <td><input type="url" name="url" id="url" value="https://example.org" /></td>
    </tr>
  </table>
  <h2>About Yourself</h2>
  <table class="form-table">
    <tr class="user-description-wrap">
      <th><label for="description">Biographical Info</label></th>
      <td><textarea name="description" id="description">Bio here</textarea></td>
    </tr>
  </table>
  <div id="wfls-user-settings">
    <h2>Wordfence 2FA</h2>
    <table class="form-table">
      <tr id="wfls-user-2fa-status"><th>Status</th><td>Active</td></tr>
    </table>
  </div>
</form>
HTML;

        $cleaned = $plugin->filterProfilePageBuffer($sampleHtml);

        $this->assertStringNotContainsString('user-user-login-wrap', $cleaned);
        $this->assertStringNotContainsString('id="user_login"', $cleaned);
        $this->assertStringNotContainsString('user-url-wrap', $cleaned);
        $this->assertStringNotContainsString('Contact Info', $cleaned);
        $this->assertStringNotContainsString('user-description-wrap', $cleaned);
        $this->assertStringNotContainsString('About Yourself', $cleaned);
        $this->assertStringNotContainsString('wfls-user-settings', $cleaned);
        $this->assertStringContainsString('First Name', $cleaned);
        $this->assertStringNotContainsString('name="url"', $cleaned);
        $this->assertStringNotContainsString('https://example.org', $cleaned);
        $this->assertStringNotContainsString('<input type="hidden"', $cleaned);

        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE');
    }

    public function testProfilePostProtection(): void
    {
        $initialObLevel = ob_get_level();
        $fieldsRule = 'email,nickname,toolbar,url,description|role:editor';
        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE=' . $fieldsRule);

        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $editor->user_email = 'editor_bob@example.org';
        $editor->nickname = 'BobTheEditor';
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $GLOBALS['pagenow'] = 'profile.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'email' => 'attacker@example.org',
            'nickname' => 'HackerNick',
            'admin_bar_front' => '1',
            'url' => 'https://example.org/attacker',
            'description' => 'Malicious biography',
            'first_name' => 'Bob',
        ];
        $_REQUEST = $_POST;

        $plugin->handleAdminInit();

        $this->assertSame('editor_bob@example.org', $_POST['email']);
        $this->assertSame('BobTheEditor', $_POST['nickname']);
        $this->assertSame('0', $_POST['admin_bar_front']);
        $this->assertArrayNotHasKey('url', $_POST);
        $this->assertArrayNotHasKey('description', $_POST);
        $this->assertSame('Bob', $_POST['first_name']);

        $this->assertSame('editor_bob@example.org', $_REQUEST['email']);
        $this->assertSame('BobTheEditor', $_REQUEST['nickname']);
        $this->assertSame('0', $_REQUEST['admin_bar_front']);
        $this->assertArrayNotHasKey('url', $_REQUEST);
        $this->assertArrayNotHasKey('description', $_REQUEST);

        $GLOBALS['pagenow'] = 'user-edit.php';
        $_POST = [
            'user_id' => '10',
            'email' => 'attacker2@example.org',
            'admin_bar_front' => '1',
            'url' => 'https://example.org/attacker2',
        ];
        $_REQUEST = $_POST;

        $plugin->handleAdminInit();

        $this->assertSame('editor_bob@example.org', $_POST['email']);
        $this->assertSame('0', $_POST['admin_bar_front']);
        $this->assertArrayNotHasKey('url', $_POST);

        $admin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $admin;
        $this->currentUser = $admin;

        $_POST = [
            'user_id' => '10',
            'email' => 'new_admin_set_email@example.org',
            'url' => 'https://example.org/new-website',
        ];
        $_REQUEST = $_POST;

        $plugin->handleAdminInit();

        $this->assertSame('new_admin_set_email@example.org', $_POST['email']);
        $this->assertSame('https://example.org/new-website', $_POST['url']);

        while (ob_get_level() > $initialObLevel) {
            ob_end_clean();
        }

        putenv('WP_ADMIN_PROFILE_FIELDS_REMOVE');
    }

    public function testSidebarWidgetsRemoval(): void
    {
        putenv('WP_ADMIN_SIDEBAR_WIDGETS_REMOVE=archives,calendar|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $this->assertContains('WP_Widget_Archives', $plugin->getSidebarWidgetsToRemoveForUser($editor));
        $this->assertContains('WP_Widget_Calendar', $plugin->getSidebarWidgetsToRemoveForUser($editor));

        $plugin->handleWidgetsInit();
        $this->assertContains('WP_Widget_Archives', $this->unregisteredWidgets);
        $this->assertContains('WP_Widget_Calendar', $this->unregisteredWidgets);

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;
        $this->unregisteredWidgets = [];

        $plugin->handleWidgetsInit();
        $this->assertSame([], $this->unregisteredWidgets);

        putenv('WP_ADMIN_SIDEBAR_WIDGETS_REMOVE');
        $this->assertSame([], $plugin->getSidebarWidgetsToRemoveForUser($editor));
    }

    public function testTinyMceButtonsRemoval(): void
    {
        putenv('WP_ADMIN_TINYMCE_BUTTONS_REMOVE=fontselect,fontsizeselect,backcolor,fullscreen|!superadmins');
        $editor = $this->createMockUser(10, 'editor_bob', ['editor']);
        $this->usersData[10] = $editor;
        $this->currentUser = $editor;

        $plugin = $this->createPluginWithMockFiles();

        $this->assertSame(
            ['fontselect', 'fontsizeselect', 'backcolor', 'fullscreen'],
            $plugin->getTinyMceButtonsToRemoveForUser($editor)
        );

        $buttons = ['bold', 'italic', 'fontselect', 'backcolor', 'charmap'];
        $filtered = $plugin->filterMceButtons($buttons);
        $this->assertSame(['bold', 'italic', 'charmap'], $filtered);

        $superadmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $this->usersData[1] = $superadmin;
        $this->currentUser = $superadmin;

        $this->assertSame($buttons, $plugin->filterMceButtons($buttons));

        putenv('WP_ADMIN_TINYMCE_BUTTONS_REMOVE');
        $this->assertSame($buttons, $plugin->filterMceButtons($buttons));
    }

    public function testFilterViewsUsersWordfence2FaAndPasskeyDefense(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $regularAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $regularAdmin];
        $this->usersData = [1 => $superAdmin, 2 => $regularAdmin];
        $this->currentUser = $regularAdmin;

        $this->userMeta[1] = [
            'wfls_2fa_active' => '1',
            '_wfls_passkeys' => ['key1'],
            '_wfls_remembered_devices' => ['dev1'],
        ];

        $plugin = $this->createPluginWithMockFiles();

        $initialViews = [
            'all' => '<a href="users.php">All <span class="count">(10)</span></a>',
            'administrator' => '<a href="users.php?role=administrator">Admin <span class="count">(2)</span></a>',
            'wfls-2fa-active' => '<a href="users.php?2fa=active">2FA Active <span class="count">(2)</span></a>',
            'wfls-2fa-inactive' => '<a href="users.php?2fa=inactive">2FA Inactive <span class="count">(8)</span></a>',
            'wfls-passkey-active' => '<a href="users.php?pass=1">Passkey Active <span class="count">(2)</span></a>',
            'wfls-passkey-inactive' => '<a href="users.php?pass=0">Passkey Inactive <span class="count">(8)</span></a>',
            'wfls-remembered-devices' => '<a href="users.php?rem=1">Remembered <span class="count">(3)</span></a>',
            'two_factor' => '<a href="users.php?tf=1">Two-Factor <span class="count">(2)</span></a>',
            'custom_view_overflow' => '<a href="users.php?custom=1">Custom <span class="count">(15)</span></a>',
        ];

        $filtered = $plugin->filterViewsUsers($initialViews);

        $this->assertStringContainsString('(9)', (string) $filtered['all']);
        $this->assertStringContainsString('(1)', (string) $filtered['administrator']);
        $this->assertStringContainsString('(1)', (string) $filtered['wfls-2fa-active']);
        $this->assertStringContainsString('(8)', (string) $filtered['wfls-2fa-inactive']);
        $this->assertStringContainsString('(1)', (string) $filtered['wfls-passkey-active']);
        $this->assertStringContainsString('(8)', (string) $filtered['wfls-passkey-inactive']);
        $this->assertStringContainsString('(2)', (string) $filtered['wfls-remembered-devices']);
        $this->assertStringContainsString('(1)', (string) $filtered['two_factor']);
        $this->assertStringContainsString('(9)', (string) $filtered['custom_view_overflow']);

        $this->userMeta[1] = [];
        $viewsWithoutMeta = [
            'all' => '<span class="count">(10)</span>',
            'wfls-2fa-active' => '<span class="count">(2)</span>',
            'wfls-2fa-inactive' => '<span class="count">(8)</span>',
            'wfls-passkey-active' => '<span class="count">(2)</span>',
            'wfls-passkey-inactive' => '<span class="count">(8)</span>',
        ];

        $filteredWithoutMeta = $plugin->filterViewsUsers($viewsWithoutMeta);
        $this->assertStringContainsString('(2)', (string) $filteredWithoutMeta['wfls-2fa-active']);
        $this->assertStringContainsString('(7)', (string) $filteredWithoutMeta['wfls-2fa-inactive']);
        $this->assertStringContainsString('(2)', (string) $filteredWithoutMeta['wfls-passkey-active']);
        $this->assertStringContainsString('(7)', (string) $filteredWithoutMeta['wfls-passkey-inactive']);

        $viewsOnlyAdmin = [
            'administrator' => '<span class="count">(3)</span>',
            'custom_overflow' => '<span class="count">(5)</span>',
        ];
        $filteredOnlyAdmin = $plugin->filterViewsUsers($viewsOnlyAdmin);
        $this->assertStringContainsString('(2)', (string) $filteredOnlyAdmin['administrator']);
        $this->assertStringContainsString('(2)', (string) $filteredOnlyAdmin['custom_overflow']);

        $this->currentUser = $superAdmin;
        $superViews = $plugin->filterViewsUsers($initialViews);
        $this->assertSame($initialViews, $superViews);
    }

    public function testFilterUserRowActionsZeroKnowledgeDefense(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $regularAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $editor = $this->createMockUser(3, 'editor_user', ['editor']);
        $this->allUsers = [$superAdmin, $regularAdmin, $editor];
        $this->usersData = [1 => $superAdmin, 2 => $regularAdmin, 3 => $editor];

        $plugin = $this->createPluginWithMockFiles();

        $actions = [
            'edit' => '<a href="user-edit.php?user_id=1">Edit</a>',
            'delete' => '<a href="users.php?action=delete&user=1">Delete</a>',
            'wfls_2fa' => '<a href="#">2FA</a>',
        ];

        $this->currentUser = $regularAdmin;
        $this->assertSame([], $plugin->filterUserRowActions($actions, $superAdmin));
        $this->assertSame($actions, $plugin->filterUserRowActions($actions, $editor));
        $this->assertSame($actions, $plugin->filterUserRowActions($actions, 'not-a-user-object'));

        $this->currentUser = $superAdmin;
        $this->assertSame($actions, $plugin->filterUserRowActions($actions, $superAdmin));
        $this->assertSame($actions, $plugin->filterUserRowActions($actions, $editor));
    }

    public function testFilterManageUsersCustomColumnZeroKnowledgeDefense(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $regularAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $this->allUsers = [$superAdmin, $regularAdmin];
        $this->usersData = [1 => $superAdmin, 2 => $regularAdmin];

        $plugin = $this->createPluginWithMockFiles();

        $columnOutput = '<span class="dashicons dashicons-yes-alt"></span> Enabled';

        $this->currentUser = $regularAdmin;
        $this->assertSame('', $plugin->filterManageUsersCustomColumn($columnOutput, 'wfls_2fa', 1));
        $this->assertSame('', $plugin->filterManageUsersCustomColumn($columnOutput, 'wfls_passkey', 1));
        $this->assertSame($columnOutput, $plugin->filterManageUsersCustomColumn($columnOutput, 'wfls_2fa', 2));

        $this->currentUser = $superAdmin;
        $this->assertSame($columnOutput, $plugin->filterManageUsersCustomColumn($columnOutput, 'wfls_2fa', 1));
        $this->assertSame($columnOutput, $plugin->filterManageUsersCustomColumn($columnOutput, 'wfls_2fa', 2));
    }

    public function testHandleAdminInitBlocksArbitraryBulkAndSingleUserActionsOnSuperAdmins(): void
    {
        $superAdmin = $this->createMockUser(1, 'superadmin', ['administrator']);
        $regularAdmin = $this->createMockUser(2, 'regular_admin', ['administrator']);
        $subscriber = $this->createMockUser(3, 'sub_user', ['subscriber']);
        $this->allUsers = [$superAdmin, $regularAdmin, $subscriber];
        $this->usersData = [1 => $superAdmin, 2 => $regularAdmin, 3 => $subscriber];

        $plugin = $this->createPluginWithMockFiles();

        $this->currentUser = $regularAdmin;
        $GLOBALS['pagenow'] = 'users.php';

        $_REQUEST = ['action' => 'wfls_revoke_passkey', 'users' => ['3', '1']];
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Invalid user ID.', $this->dieCalls[0]['message']);
        $this->assertSame(500, $this->dieCalls[0]['title']);

        $this->dieCalls = [];
        $_REQUEST = ['action' => '-1', 'action2' => 'wfls_enable_2fa', 'user_id' => '1'];
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Invalid user ID.', $this->dieCalls[0]['message']);

        $this->dieCalls = [];
        $_REQUEST = ['action' => 'two_factor_disable', 'user' => '1'];
        $plugin->handleAdminInit();
        $this->assertCount(1, $this->dieCalls);
        $this->assertSame('Invalid user ID.', $this->dieCalls[0]['message']);

        $this->dieCalls = [];
        $_REQUEST = ['action' => 'wfls_revoke_passkey', 'users' => ['2', '3']];
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);

        $this->dieCalls = [];
        $this->currentUser = $superAdmin;
        $_REQUEST = ['action' => 'wfls_revoke_passkey', 'users' => ['1']];
        $plugin->handleAdminInit();
        $this->assertCount(0, $this->dieCalls);
    }
}
