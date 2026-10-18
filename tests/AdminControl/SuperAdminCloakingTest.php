<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use stdClass;
use WordPressDocker\AdminControl\Security\SuperAdminCloaking;
use WordPressDocker\Tests\WpCoreCleanupTest;

/**
 * Validates super-administrator cloaking, zero-knowledge queries, capability protection,
 * and user interface hardening.
 */
final class SuperAdminCloakingTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_REQUEST['user_id'],
            $_REQUEST['user'],
            $_REQUEST['users'],
            $_REQUEST['action'],
            $_REQUEST['action2'],
            $_GET['user_id'],
            $_GET['user'],
            $_POST['user_id']
        );
        parent::tearDown();
    }

    public function testIsSuperAdminDelegation(): void
    {
        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $user): bool => isset($user->ID) && $user->ID === 1
        );

        $superUser = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'editor'];

        $this->assertTrue($cloaking->isSuperAdmin($superUser));
        $this->assertFalse($cloaking->isSuperAdmin($normalUser));
        $this->assertFalse($cloaking->isSuperAdmin(null));

        $defaultCloaking = new SuperAdminCloaking();
        $this->assertFalse($defaultCloaking->isSuperAdmin($superUser));
    }

    public function testResolveSuperAdminIdsWithCustomResolver(): void
    {
        $cloaking = new SuperAdminCloaking(
            superAdminIdsResolver: static fn(): array => [1, 3, 1]
        );

        $this->assertSame([1, 3], $cloaking->resolveSuperAdminIds());
    }

    public function testResolveSuperAdminIdsByScanningAdministrators(): void
    {
        $admin1 = (object) ['ID' => 10, 'user_login' => 'alpha'];
        $admin2 = (object) ['ID' => 20, 'user_login' => 'beta'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $user): bool => isset($user->ID) && $user->ID === 10,
            usersGetter: static fn(array $args): array => [$admin1, $admin2]
        );

        $this->assertSame([10], $cloaking->resolveSuperAdminIds());
    }

    public function testBlockedTargetsAndChecks(): void
    {
        $user = (object) ['ID' => 5, 'user_login' => 'manager'];
        $cloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $user,
            blockedTargetsGetter: static fn(): array => ['user-new.php', 'users.php?action=delete']
        );

        $this->assertSame(['user-new.php', 'users.php?action=delete'], $cloaking->getBlockedTargetsForCurrentUser());
        $this->assertTrue($cloaking->isTargetBlocked('user-new.php'));
        $this->assertFalse($cloaking->isTargetBlocked('profile.php'));
        $this->assertTrue($cloaking->isDeleteBlocked());

        $defaultCloaking = new SuperAdminCloaking();
        $this->assertSame([], $defaultCloaking->getBlockedTargetsForCurrentUser());
        $this->assertFalse($defaultCloaking->isTargetBlocked('user-new.php'));
        $this->assertFalse($defaultCloaking->isDeleteBlocked());
    }

    public function testFilterPreGetUsersModifiesQuery(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            currentUserGetter: static fn(): object => $normalUser
        );

        $mockQuery = new class {
            /** @var array<string, mixed> */
            public array $vars = ['exclude' => [7], 'include' => [1, 8]];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }
        };

        $cloaking->filterPreGetUsers($mockQuery);
        $this->assertSame([7, 1], $mockQuery->get('exclude'));
        $this->assertSame([8], $mockQuery->get('include'));

        $superQuery = clone $mockQuery;
        $superCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $superAdmin
        );
        $superCloaking->filterPreGetUsers($superQuery);
        $this->assertSame([7, 1], $superQuery->get('exclude'));

        $stringExcludeQuery = new class {
            /** @var array<string, mixed> */
            public array $vars = ['exclude' => '7, 9', 'include' => '1, 8'];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }
        };

        $cloaking->filterPreGetUsers($stringExcludeQuery);
        $this->assertSame([7, 9, 1], $stringExcludeQuery->get('exclude'));
        $this->assertSame([8], $stringExcludeQuery->get('include'));
    }

    public function testFilterRestUserQueryScrubsAndExcludes(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            currentUserGetter: static fn(): object => $normalUser
        );

        $cleanedArray = $cloaking->filterRestUserQuery(['include' => [1, 2], 'exclude' => [9]]);
        $this->assertSame([2], $cleanedArray['include']);
        $this->assertSame([9, 1], $cleanedArray['exclude']);

        $cleanedString = $cloaking->filterRestUserQuery(['include' => '1, 2', 'exclude' => []]);
        $this->assertSame([2], $cleanedString['include']);
        $this->assertSame([1], $cleanedString['exclude']);

        $cleanedAllSuper = $cloaking->filterRestUserQuery(['include' => [1]]);
        $this->assertSame([0], $cleanedAllSuper['include']);

        $superCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $superAdmin
        );
        $untouched = ['include' => [1, 2]];
        $this->assertSame($untouched, $superCloaking->filterRestUserQuery($untouched));
    }

    public function testFilterUserRowActionsMaskingAndRevocation(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $otherUser = (object) ['ID' => 2, 'user_login' => 'other'];
        $viewer = (object) ['ID' => 3, 'user_login' => 'viewer'];

        $actions = ['edit' => '<a href="edit">Edit</a>', 'delete' => '<a href="delete">Delete</a>'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $viewer,
            blockedTargetsGetter: static fn(): array => ['user-edit.php', 'users.php?action=delete']
        );

        $this->assertSame([], $cloaking->filterUserRowActions($actions, $superAdmin));

        $filteredOther = $cloaking->filterUserRowActions($actions, $otherUser);
        $this->assertArrayNotHasKey('edit', $filteredOther);
        $this->assertArrayNotHasKey('delete', $filteredOther);

        $selfCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $viewer,
            blockedTargetsGetter: static fn(): array => ['profile.php']
        );
        $filteredSelf = $selfCloaking->filterUserRowActions($actions, $viewer);
        $this->assertArrayNotHasKey('edit', $filteredSelf);
        $this->assertArrayHasKey('delete', $filteredSelf);
    }

    public function testFilterBulkActionsUsersRemovesDeleteWhenBlocked(): void
    {
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];
        $actions = ['delete' => 'Delete', 'export' => 'Export'];

        $blockedCloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $normalUser,
            blockedTargetsGetter: static fn(): array => ['users.php?action=delete']
        );
        $this->assertSame(['export' => 'Export'], $blockedCloaking->filterBulkActionsUsers($actions));

        $openCloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $normalUser,
            blockedTargetsGetter: static fn(): array => []
        );
        $this->assertSame($actions, $openCloaking->filterBulkActionsUsers($actions));
    }

    public function testFilterViewsUsersAdjustsRoleCounts(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            currentUserGetter: static fn(): object => $normalUser,
            usersGetter: static fn(array $args): array => [(object) ['ID' => 1]],
            userMetaGetter: static fn(int $userId, string $key, bool $single): array => ['administrator' => true]
        );

        $views = [
            'all' => '<a href="users.php">All <span class="count">(5)</span></a>',
            'administrator' => '<a href="users.php?role=administrator">Administrator '
                . '<span class="count">(1)</span></a>',
            'subscriber' => '<a href="users.php?role=subscriber">Subscriber <span class="count">(4)</span></a>',
        ];

        $adjusted = $cloaking->filterViewsUsers($views);
        $this->assertStringContainsString('(4)', (string) ($adjusted['all'] ?? ''));
        $this->assertStringContainsString('(0)', (string) ($adjusted['administrator'] ?? ''));
        $this->assertStringContainsString('(4)', (string) ($adjusted['subscriber'] ?? ''));
    }

    public function testFilterManageUsersCustomColumnBlanksForSuperAdmin(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            userDataGetter: static fn(int $id): ?object => match ($id) {
                1 => $superAdmin,
                2 => $normalUser,
                default => null,
            },
            currentUserGetter: static fn(): object => $normalUser
        );

        $this->assertSame('', $cloaking->filterManageUsersCustomColumn('Some Value', 'custom_col', 1));
        $this->assertSame('Some Value', $cloaking->filterManageUsersCustomColumn('Some Value', 'custom_col', 2));

        $superViewerCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            userDataGetter: static fn(int $id): ?object => match ($id) {
                1 => $superAdmin,
                2 => $normalUser,
                default => null,
            },
            currentUserGetter: static fn(): object => $superAdmin
        );
        $this->assertSame(
            'Some Value',
            $superViewerCloaking->filterManageUsersCustomColumn('Some Value', 'custom_col', 1)
        );
    }

    public function testFilterMapMetaCapProtectsSuperAdminsAndEnforcesRules(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : ($id === 2 ? $normalUser : null),
            currentUserGetter: static fn(): object => $normalUser,
            blockedTargetsGetter: static fn(): array => [
                'user-new.php',
                'users.php?action=delete',
                'user-edit.php',
                'profile.php',
            ]
        );

        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_users'], 'edit_user', 2, [1]));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['delete_users'], 'delete_user', 2, [1]));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['promote_users'], 'promote_user', 2, [1]));

        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['create_users'], 'create_users', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['delete_users'], 'delete_users', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_users'], 'edit_user', 2, [3]));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_user'], 'edit_user', 2, [2]));
    }

    public function testHandleAdminInitAndHeadBufferDomAndOutputCss(): void
    {
        $bufferStarted = false;
        $echoed = '';
        $user = (object) ['ID' => 2, 'user_login' => 'admin'];

        $cloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $user,
            blockedTargetsGetter: static fn(): array => ['user-new.php'],
            pageNowGetter: static fn(): string => 'users.php',
            bufferStarter: static function (callable $cb) use (&$bufferStarted): bool {
                $bufferStarted = true;
                return true;
            },
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed .= $html;
            }
        );

        $cloaking->handleAdminInit();
        $this->assertTrue($bufferStarted);
        $this->assertTrue($cloaking->isBufferingStarted());

        $cloaking->handleAdminHead();
        $this->assertStringContainsString('.page-title-action', $echoed);
    }

    public function testFilterBufferStripsAddUserButton(): void
    {
        $cloaking = new SuperAdminCloaking(
            blockedTargetsGetter: static fn(): array => ['user-new.php']
        );
        $html = '<div class="wrap"><h1>Users <a href="user-new.php" class="page-title-action">Add New</a></h1></div>';
        $filtered = $cloaking->filterBuffer($html);
        $this->assertStringNotContainsString('page-title-action', $filtered);
        $this->assertStringNotContainsString('Add New', $filtered);
        $this->assertStringContainsString('Users', $filtered);

        $unblockedCloaking = new SuperAdminCloaking();
        $this->assertSame($html, $unblockedCloaking->filterBuffer($html));
    }

    public function testEnforceUserActionProtectionBlocksSuperAdminTampering(): void
    {
        $diedCount = 0;
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : null,
            currentUserGetter: static fn(): object => $normalUser,
            dieInvalidUserHandler: static function (string $msg) use (&$diedCount): void {
                $diedCount++;
            }
        );

        unset(
            $_REQUEST['user_id'],
            $_REQUEST['user'],
            $_GET['user_id'],
            $_GET['user'],
            $_POST['user_id']
        );
        $cloaking->enforceUserActionProtection('user-edit.php');
        $this->assertSame(0, $diedCount);

        $_REQUEST['user_id'] = 1;
        $cloaking->enforceUserActionProtection('user-edit.php');
        $this->assertSame(1, $diedCount);

        $_REQUEST['action'] = 'delete';
        $_REQUEST['user'] = 1;
        $cloaking->enforceUserActionProtection('users.php');
        $this->assertSame(2, $diedCount);

        $_REQUEST['action'] = 'dodelete';
        $_REQUEST['users'] = '1,2';
        $cloaking->enforceUserActionProtection('users.php');
        $this->assertSame(3, $diedCount);

        $cloaking->enforceUserActionProtection('index.php');
        $this->assertSame(3, $diedCount);
    }

    public function testFilterRestPreDispatchBranches(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : null,
            currentUserGetter: static fn(): object => $normalUser
        );

        $this->assertSame('preset', $cloaking->filterRestPreDispatch('preset', null, null));
        $this->assertNull($cloaking->filterRestPreDispatch(null, null, null));

        $reqNonUsers = new \WP_REST_Request_Stub('/wp/v2/posts/10');
        $this->assertNull($cloaking->filterRestPreDispatch(null, null, $reqNonUsers));

        $reqSuperUser = new \WP_REST_Request_Stub('/wp/v2/users/1');

        $result = $cloaking->filterRestPreDispatch(null, null, $reqSuperUser);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('rest_user_invalid_id', $result->get_error_code());

        $superViewerCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : null,
            currentUserGetter: static fn(): object => $superAdmin
        );
        $this->assertNull($superViewerCloaking->filterRestPreDispatch(null, null, $reqSuperUser));
    }

    public function testHandleTemplateRedirectBranches(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];

        $adminCloaking = new SuperAdminCloaking(
            adminChecker: static fn(): bool => true
        );
        $adminCloaking->handleTemplateRedirect();

        $superCloaking = new SuperAdminCloaking(
            adminChecker: static fn(): bool => false,
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $superAdmin
        );
        $superCloaking->handleTemplateRedirect();

        $notAuthorCloaking = new SuperAdminCloaking(
            adminChecker: static fn(): bool => false,
            superAdminChecker: static fn(?object $u): bool => false,
            authorArchiveChecker: static fn(): bool => false
        );
        $notAuthorCloaking->handleTemplateRedirect();

        $statusSent = 0;
        $nocacheSent = false;
        $mockQuery = new \WP_Query_Stub();
        $backupWpQuery = $GLOBALS['wp_query'] ?? null;

        try {
            $GLOBALS['wp_query'] = $mockQuery;
            $matchedCloaking = new SuperAdminCloaking(
                adminChecker: static fn(): bool => false,
                superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
                authorArchiveChecker: static fn(): bool => true,
                queriedObjectIdGetter: static fn(): int => 1,
                superAdminIdsResolver: static fn(): array => [1],
                statusHeaderSender: static function (int $code) use (&$statusSent): void {
                    $statusSent = $code;
                },
                nocacheHeadersSender: static function () use (&$nocacheSent): void {
                    $nocacheSent = true;
                }
            );

            $matchedCloaking->handleTemplateRedirect();
            $this->assertSame(404, $statusSent);
            $this->assertTrue($nocacheSent);
            $this->assertTrue($mockQuery->is404);
        } finally {
            $GLOBALS['wp_query'] = $backupWpQuery;
        }
    }

    public function testFilterViewsUsersAndRowActionsAdditionalBranches(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $superViewer = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $superAdmin
        );
        $views = ['all' => '<span class="count">(10)</span>'];
        $this->assertSame($views, $superViewer->filterViewsUsers($views));
        $this->assertSame(['delete' => 'Delete'], $superViewer->filterBulkActionsUsers(['delete' => 'Delete']));
        $this->assertSame(['edit' => 'Edit'], $superViewer->filterUserRowActions(['edit' => 'Edit'], $superAdmin));

        $noSuperAdmins = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => false,
            superAdminIdsResolver: static fn(): array => [],
            currentUserGetter: static fn(): object => $normalUser
        );
        $this->assertSame($views, $noSuperAdmins->filterViewsUsers($views));

        $adminViews = [
            'administrator' => '<span class="count">(5)</span>',
            '2fa' => '<span class="count">(3)</span>',
        ];
        $cloakingWithMeta = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            currentUserGetter: static fn(): object => $normalUser,
            userMetaGetter: static fn(int $id, string $k, bool $s): mixed => $k === 'wfls_2fa_active' ? 1 : ''
        );
        $filteredAdminViews = $cloakingWithMeta->filterViewsUsers($adminViews);
        $this->assertStringContainsString('(4)', $filteredAdminViews['administrator']);
        $this->assertStringContainsString('(2)', $filteredAdminViews['2fa']);

        $deleteBlockedCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $normalUser,
            blockedTargetsGetter: static fn(): array => ['users.php?action=delete']
        );
        $actions = ['edit' => 'Edit', 'delete' => 'Delete'];
        $this->assertSame(['edit' => 'Edit'], $deleteBlockedCloaking->filterBulkActionsUsers($actions));
        $this->assertSame([], $deleteBlockedCloaking->filterUserRowActions($actions, $superAdmin));
        $this->assertSame(['edit' => 'Edit'], $deleteBlockedCloaking->filterUserRowActions($actions, $normalUser));
    }

    public function testFilterMapMetaCapAdditionalBranches(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : $normalUser,
            currentUserGetter: static fn(): object => $normalUser,
            blockedTargetsGetter: static fn(): array => [
                'user-new.php',
                'users.php?action=delete',
                'user-edit.php',
                'profile.php',
            ]
        );

        $this->assertSame(['custom_cap'], $cloaking->filterMapMetaCap(['custom_cap'], '', 2, []));

        $superViewer = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $superAdmin
        );
        $this->assertSame(['create_users'], $superViewer->filterMapMetaCap(['create_users'], 'create_users', 1, []));

        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['promote_users'], 'promote_users', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_users'], 'edit_users', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['delete_user'], 'delete_user', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_user'], 'edit_user', 2, [2]));
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testSuperAdminCloakingDefaultFallbacksWithGlobalStubs(): void
    {
        ensure_admin_control_stubs();

        $backupPagenow = $GLOBALS['pagenow'] ?? null;
        $backupCurrentUser = $GLOBALS['test_current_user'] ?? null;
        $backupUsers = $GLOBALS['test_users_list'] ?? null;
        $backupIsAuthor = $GLOBALS['test_is_author'] ?? null;
        $backupQueriedId = $GLOBALS['test_queried_object_id'] ?? null;

        try {
            $GLOBALS['pagenow'] = 'users.php';
            $GLOBALS['test_current_user'] = (object) ['ID' => 1, 'user_login' => 'admin'];
            $GLOBALS['test_users_list'] = [(object) ['ID' => 1, 'user_login' => 'admin']];
            $GLOBALS['test_is_author'] = true;
            $GLOBALS['test_queried_object_id'] = 1;

            $cloaking = new SuperAdminCloaking();
            $this->assertSame([], $cloaking->resolveSuperAdminIds());
            $cloaking->handleTemplateRedirect();
            $cloaking->handleAdminInit();
        } finally {
            $GLOBALS['pagenow'] = $backupPagenow;
            $GLOBALS['test_current_user'] = $backupCurrentUser;
            $GLOBALS['test_users_list'] = $backupUsers;
            $GLOBALS['test_is_author'] = $backupIsAuthor;
            $GLOBALS['test_queried_object_id'] = $backupQueriedId;
        }
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testEnforceUserActionProtectionUsersArrayAndDie(): void
    {
        ensure_admin_control_stubs();

        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : null,
            currentUserGetter: static fn(): object => $normalUser
        );

        $backupDieCalls = $GLOBALS['test_wp_die_calls'] ?? [];
        try {
            $_REQUEST['action'] = 'delete';
            $_REQUEST['users'] = [1, 2];
            $cloaking->enforceUserActionProtection('users.php');
            $this->assertNotEmpty($GLOBALS['test_wp_die_calls']);

            $_REQUEST['users'] = false;
            $cloaking->enforceUserActionProtection('users.php');
        } finally {
            $GLOBALS['test_wp_die_calls'] = $backupDieCalls;
        }
    }

    public function testFilterViewsUsersInactiveAndPasskeyBranches(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 2, 'user_login' => 'normal'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            currentUserGetter: static fn(): object => $normalUser,
            userMetaGetter: static fn(int $id, string $k, bool $s): mixed => ''
        );

        $views = [
            'all' => '<span class="count">(10)</span>',
            '2fa-inactive' => '<span class="count">(5)</span>',
            'passkey-inactive' => '<span class="count">(5)</span>',
            'passkey' => '<span class="count">(5)</span>',
            'remembered-devices' => '<span class="count">(5)</span>',
            'non-string' => 123,
        ];

        $filtered = $cloaking->filterViewsUsers($views);
        $this->assertStringContainsString('(4)', $filtered['2fa-inactive']);
        $this->assertStringContainsString('(4)', $filtered['passkey-inactive']);
        $this->assertStringContainsString('(5)', $filtered['passkey']);
        $this->assertStringContainsString('(5)', $filtered['remembered-devices']);
        $this->assertSame(123, $filtered['non-string']);
    }

    public function testSuperAdminEarlyReturnsAndBuffering(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];

        $superCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => true,
            currentUserGetter: static fn(): object => $superAdmin
        );

        $superCloaking->handleAdminInit();
        $superCloaking->handleAdminHead();
        $superCloaking->enforceUserActionProtection('users.php');

        $this->assertFalse($superCloaking->isTargetBlocked(''));

        $cloakingBuffer = new SuperAdminCloaking(
            bufferStarter: static fn(callable $cb): bool => true
        );
        $cloakingBuffer->startOutputBuffering();
        $cloakingBuffer->startOutputBuffering();
        $this->assertTrue($cloakingBuffer->isBufferingStarted());

        $badQuery = new \stdClass();
        $cloakingBuffer->filterPreGetUsers($badQuery);

        $emptyQuery = new class {
            public function get(string $k): mixed
            {
                return [];
            }

            public function set(string $k, mixed $v): void
            {
            }
        };
        $cloakingNoAdmins = new SuperAdminCloaking(
            superAdminIdsResolver: static fn(): array => []
        );
        $cloakingNoAdmins->filterPreGetUsers($emptyQuery);
    }

    public function testResolveSuperAdminIdsRecursionGuard(): void
    {
        $cloaking = null;
        $cloaking = new SuperAdminCloaking(
            usersGetter: function (array $args) use (&$cloaking): array {
                if ($cloaking instanceof SuperAdminCloaking) {
                    $cloaking->resolveSuperAdminIds();
                }
                return [];
            }
        );
        $this->assertSame([], $cloaking->resolveSuperAdminIds());
    }

    public function testFilterRestUserQueryWithNoSuperAdmins(): void
    {
        $cloaking = new SuperAdminCloaking(
            superAdminIdsResolver: static fn(): array => []
        );
        $args = ['include' => [1, 2]];
        $this->assertSame($args, $cloaking->filterRestUserQuery($args));
    }

    public function testFilterMapMetaCapUnprotectedCap(): void
    {
        $cloaking = new SuperAdminCloaking();
        $this->assertSame(['read'], $cloaking->filterMapMetaCap(['read'], 'read', 2, []));
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testNativeTemplateRedirectAndHeaderFallbacks(): void
    {
        ensure_admin_control_stubs();

        $backupIsAdmin = $GLOBALS['test_is_admin'] ?? null;
        $backupCurrentUser = $GLOBALS['test_current_user'] ?? null;
        $backupIsAuthor = $GLOBALS['test_is_author'] ?? null;
        $backupQueriedId = $GLOBALS['test_queried_object_id'] ?? null;
        $backupStatus = $GLOBALS['test_status_header'] ?? null;
        $backupNocache = $GLOBALS['test_nocache_headers_called'] ?? null;

        try {
            $GLOBALS['test_is_admin'] = false;
            $GLOBALS['test_current_user'] = null;
            $GLOBALS['test_is_author'] = true;
            $GLOBALS['test_queried_object_id'] = 1;

            $cloaking = new SuperAdminCloaking(
                superAdminIdsResolver: static fn(): array => [1]
            );

            $cloaking->handleTemplateRedirect();
            $this->assertSame(404, $GLOBALS['test_status_header'] ?? 0);
            $this->assertTrue((bool) ($GLOBALS['test_nocache_headers_called'] ?? false));
        } finally {
            $GLOBALS['test_is_admin'] = $backupIsAdmin;
            $GLOBALS['test_current_user'] = $backupCurrentUser;
            $GLOBALS['test_is_author'] = $backupIsAuthor;
            $GLOBALS['test_queried_object_id'] = $backupQueriedId;
            $GLOBALS['test_status_header'] = $backupStatus;
            $GLOBALS['test_nocache_headers_called'] = $backupNocache;
        }
    }

    public function testBufferingFallbackWithNativeObStart(): void
    {
        $captured = null;
        $cloakingWithCallback = new SuperAdminCloaking(
            bufferStarter: static function (callable $h) use (&$captured): void {
                $captured = $h;
            },
            blockedTargetsGetter: static fn(): array => ['user-new.php']
        );
        $cloakingWithCallback->startOutputBuffering();
        $this->assertNotNull($captured);
        $this->assertSame(
            '<div>Content</div>',
            $captured('<div><a href="user-new.php" class="page-title-action">Add New</a>Content</div>')
        );

        $cloakingNative = new SuperAdminCloaking(
            blockedTargetsGetter: static fn(): array => ['user-new.php']
        );
        $cloakingNative->startOutputBuffering();
        \ob_end_clean();
        $this->assertTrue($cloakingNative->isBufferingStarted());
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testUserMetaAndEchoHtmlAndNonNumericQueriedIdFallbacks(): void
    {
        ensure_admin_control_stubs();

        $backupPagenow = $GLOBALS['pagenow'] ?? null;
        $backupIsAdmin = $GLOBALS['test_is_admin'] ?? null;
        $backupQueriedId = $GLOBALS['test_queried_object_id'] ?? null;
        $backupIsAuthor = $GLOBALS['test_is_author'] ?? null;
        $backupUsersList = $GLOBALS['test_users_list'] ?? null;

        try {
            $GLOBALS['test_is_admin'] = false;
            $GLOBALS['pagenow'] = 'users.php';
            $GLOBALS['test_queried_object_id'] = 'invalid-non-numeric';
            $GLOBALS['test_is_author'] = true;
            $GLOBALS['test_users_list'] = 'non-array-data';

            $cloaking = new SuperAdminCloaking(
                superAdminIdsResolver: static fn(): array => [1],
                blockedTargetsGetter: static fn(): array => ['user-new.php']
            );

            \ob_start();
            $cloaking->handleAdminHead();
            $out = \ob_get_clean();
            $this->assertStringContainsString('wp-admin-control-hide-user-new', (string) $out);

            $cloaking->handleTemplateRedirect();

            $views = ['administrator' => '<span class="count">(5)</span>'];
            $filtered = $cloaking->filterViewsUsers($views);
            $this->assertStringContainsString('(4)', $filtered['administrator']);

            $defaultCloaking = new SuperAdminCloaking();
            $this->assertSame([], $defaultCloaking->resolveSuperAdminIds());
        } finally {
            $GLOBALS['test_is_admin'] = $backupIsAdmin;
            $GLOBALS['pagenow'] = $backupPagenow;
            $GLOBALS['test_queried_object_id'] = $backupQueriedId;
            $GLOBALS['test_is_author'] = $backupIsAuthor;
            $GLOBALS['test_users_list'] = $backupUsersList;
        }
    }

    public function testSuperAdminCloakingGettersSafelyHandleFalseReturns(): void
    {
        $cloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): mixed => false,
            userDataGetter: static fn(int $id): mixed => false
        );

        $reflectionCurrentUser = new \ReflectionMethod($cloaking, 'getCurrentUser');
        $this->assertNull($reflectionCurrentUser->invoke($cloaking));

        $reflectionUserData = new \ReflectionMethod($cloaking, 'getUserData');
        $this->assertNull($reflectionUserData->invoke($cloaking, 999999));
    }

    public function testIsMetaValueActiveBranches(): void
    {
        $cloaking = new SuperAdminCloaking();

        $this->assertFalse($cloaking->isMetaValueActive([]));
        $this->assertTrue($cloaking->isMetaValueActive(['auth' => 'totp']));

        $this->assertFalse($cloaking->isMetaValueActive(false));
        $this->assertTrue($cloaking->isMetaValueActive(true));

        $this->assertFalse($cloaking->isMetaValueActive(''));
        $this->assertFalse($cloaking->isMetaValueActive('   '));
        $this->assertFalse($cloaking->isMetaValueActive('0'));
        $this->assertFalse($cloaking->isMetaValueActive('false'));
        $this->assertFalse($cloaking->isMetaValueActive('no'));
        $this->assertFalse($cloaking->isMetaValueActive('off'));
        $this->assertFalse($cloaking->isMetaValueActive('disabled'));
        $this->assertFalse($cloaking->isMetaValueActive('none'));
        $this->assertFalse($cloaking->isMetaValueActive('null'));
        $this->assertFalse($cloaking->isMetaValueActive('-1'));
        $this->assertTrue($cloaking->isMetaValueActive('1'));
        $this->assertTrue($cloaking->isMetaValueActive('enabled'));

        $this->assertFalse($cloaking->isMetaValueActive(0));
        $this->assertFalse($cloaking->isMetaValueActive(-1));
        $this->assertTrue($cloaking->isMetaValueActive(1));

        $this->assertFalse($cloaking->isMetaValueActive(null));
        $this->assertTrue($cloaking->isMetaValueActive((object) ['active' => true]));
    }

    public function testIsTargetBlockedWithParentChildSyntax(): void
    {
        $cloaking = new SuperAdminCloaking(
            blockedTargetsGetter: static fn(): array => [
                'users.php:user-new.php',
                'users.php:profile.php',
                'https://example.com:user-new.php',
                'http://example.org:user-new.php',
            ]
        );

        $this->assertFalse($cloaking->isTargetBlocked(''));
        $this->assertFalse($cloaking->isTargetBlocked('   '));
        $this->assertTrue($cloaking->isTargetBlocked('user-new.php'));
        $this->assertTrue($cloaking->isTargetBlocked('profile.php'));
        $this->assertFalse($cloaking->isTargetBlocked('user-edit.php'));

        $urlOnlyCloaking = new SuperAdminCloaking(
            blockedTargetsGetter: static fn(): array => [
                'https://example.com:user-new.php',
                'http://example.org:user-new.php',
            ]
        );
        $this->assertFalse($urlOnlyCloaking->isTargetBlocked('user-new.php'));
    }

    public function testFilterMapMetaCapWithParentChildBlockedTargetsAndZeroUserId(): void
    {
        $editor = (object) ['ID' => 2, 'user_login' => 'editor_user'];
        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => false,
            currentUserGetter: static fn(): object => $editor,
            userDataGetter: static fn(int $id): ?object => $id === 2 ? $editor : null,
            blockedTargetsGetter: static fn(): array => [
                'users.php:user-new.php',
                'users.php:profile.php',
                'users.php:user-edit.php',
            ]
        );

        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['create_users'], 'create_users', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['promote_users'], 'promote_users', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_users'], 'edit_users', 2, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_user'], 'edit_user', 2, [3]));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_user'], 'edit_user', 2, [2]));

        $this->assertSame(['edit_user'], $cloaking->filterMapMetaCap(['edit_user'], 'edit_user', 0, [0]));
    }

    public function testUserActionsControlDefaultsAndChecks(): void
    {
        $defaultCloaking = new SuperAdminCloaking();
        $this->assertSame(['hide' => [], 'block' => []], $defaultCloaking->getUserActionsControlForCurrentUser());
        $this->assertFalse($defaultCloaking->isUserActionBlocked(''));
        $this->assertFalse($defaultCloaking->isUserActionBlocked('   '));
        $this->assertFalse($defaultCloaking->isUserActionBlocked('edit'));
        $this->assertFalse($defaultCloaking->isEditBlocked());

        $customCloaking = new SuperAdminCloaking(
            userActionsControlGetter: static fn(): array => [
                'hide' => ['view', 'resetpassword'],
                'block' => ['edit', 'wfls', 'admin.php?page=security', 'delete'],
            ]
        );

        $this->assertTrue($customCloaking->isUserActionBlocked('edit'));
        $this->assertTrue($customCloaking->isUserActionBlocked('wfls'));
        $this->assertTrue($customCloaking->isUserActionBlocked('security'));
        $this->assertFalse($customCloaking->isUserActionBlocked('unknown'));
        $this->assertTrue($customCloaking->isEditBlocked());
        $this->assertTrue($customCloaking->isDeleteBlocked());
    }

    public function testDieForbiddenWithHandlerAndFallback(): void
    {
        $capturedMessage = '';
        $customCloaking = new SuperAdminCloaking(
            dieForbiddenHandler: static function (string $msg) use (&$capturedMessage): void {
                $capturedMessage = $msg;
            }
        );
        $customCloaking->dieForbidden('Access denied custom.');
        $this->assertSame('Access denied custom.', $capturedMessage);

        ensure_wordpress_stubs();
        $fallbackCloaking = new SuperAdminCloaking();
        $fallbackCloaking->dieForbidden('Fallback forbidden.');
        $lastDie = end($GLOBALS['test_wp_die_calls']);
        $this->assertSame('Fallback forbidden.', $lastDie['message'] ?? '');
    }

    public function testFilterUserRowActionsRouteSynergyWithSidebarMenu(): void
    {
        $viewer = (object) ['ID' => 5, 'user_login' => 'manager'];
        $otherUser = (object) ['ID' => 12, 'user_login' => 'editor_user'];

        $cloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $viewer,
            blockedTargetsGetter: static fn(): array => [
                '',
                'admin.php?page=WFLS',
                'user-edit.php',
                'users.php?action=delete',
                'tools.php:export_user',
                'mail-log',
            ]
        );

        $actions = [
            'wfls' => '<a href="admin.php?page=WFLS&amp;user=12">Login Security</a>',
            'custom_wfls' => '<a href="https://example.com/wp-admin/admin.php?page=WFLS">Security</a>',
            'edit' => '<a href="user-edit.php?user_id=12">Edit</a>',
            'delete' => '<a href="users.php?action=delete&amp;user=12">Delete</a>',
            'export_user' => '<a href="admin.php?page=export_user">Export</a>',
            'view' => '<a href="https://example.org/author/editor_user/">View</a>',
            'stats_action' => '<a href="admin.php?page=mail-log">Mail Log</a>',
        ];

        $filteredOther = $cloaking->filterUserRowActions($actions, $otherUser);
        $this->assertArrayNotHasKey('wfls', $filteredOther);
        $this->assertArrayNotHasKey('custom_wfls', $filteredOther);
        $this->assertArrayNotHasKey('edit', $filteredOther);
        $this->assertArrayNotHasKey('delete', $filteredOther);
        $this->assertArrayNotHasKey('export_user', $filteredOther);
        $this->assertArrayNotHasKey('stats_action', $filteredOther);
        $this->assertArrayHasKey('view', $filteredOther);

        $selfActions = [
            'edit' => '<a href="profile.php">Edit Profile</a>',
            'view' => '<a href="https://example.org/author/manager/">View</a>',
        ];
        $filteredSelf = $cloaking->filterUserRowActions($selfActions, $viewer);
        $this->assertArrayHasKey('edit', $filteredSelf);
        $this->assertArrayHasKey('view', $filteredSelf);

        $profileBlockedCloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $viewer,
            blockedTargetsGetter: static fn(): array => ['profile.php']
        );
        $filteredProfileSelf = $profileBlockedCloaking->filterUserRowActions($selfActions, $viewer);
        $this->assertArrayNotHasKey('edit', $filteredProfileSelf);

        $filteredProfileOther = $profileBlockedCloaking->filterUserRowActions($selfActions, $otherUser);
        $this->assertArrayHasKey('edit', $filteredProfileOther);
    }

    public function testFilterUserRowActionsDeclarativeUserActionsControl(): void
    {
        $viewer = (object) ['ID' => 7, 'user_login' => 'staff_user'];
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin'];
        $targetUser = (object) ['ID' => 15, 'user_login' => 'author_user'];

        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            currentUserGetter: static fn(): object => $viewer,
            userActionsControlGetter: static fn(): array => [
                'hide' => ['view', 'resetpassword', 'edit', 'delete'],
                'block' => ['edit', 'delete'],
            ]
        );

        $actions = [
            'view' => '<a href="https://example.net/author/author_user/">View</a>',
            'resetpassword' => '<a href="users.php?action=resetpassword">Reset Password</a>',
            'edit' => '<a href="user-edit.php?user_id=15">Edit</a>',
            'delete' => '<a href="users.php?action=delete&amp;user=15">Delete</a>',
            'stats' => '<a href="admin.php?page=user-stats">Stats</a>',
        ];

        $filtered = $cloaking->filterUserRowActions($actions, $targetUser);
        $this->assertSame(['stats' => '<a href="admin.php?page=user-stats">Stats</a>'], $filtered);

        $this->assertSame([], $cloaking->filterUserRowActions($actions, $superAdmin));

        $superCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            currentUserGetter: static fn(): object => $superAdmin,
            userActionsControlGetter: static fn(): array => [
                'hide' => ['view', 'resetpassword'],
                'block' => ['edit', 'delete'],
            ]
        );
        $expectedSuperActions = [
            'edit' => '<a href="user-edit.php?user_id=15">Edit</a>',
            'delete' => '<a href="users.php?action=delete&amp;user=15">Delete</a>',
            'stats' => '<a href="admin.php?page=user-stats">Stats</a>',
        ];
        $this->assertSame($expectedSuperActions, $superCloaking->filterUserRowActions($actions, $targetUser));
        $this->assertSame($expectedSuperActions, $superCloaking->filterUserRowActions($actions, $superAdmin));
        $this->assertFalse($superCloaking->isUserActionBlocked('edit'));
        $this->assertFalse($superCloaking->isEditBlocked());
        $this->assertFalse($superCloaking->isTargetBlocked('user-edit.php'));
        $this->assertFalse($superCloaking->isDeleteBlocked());
    }

    public function testFilterMapMetaCapWithBlockedUserActions(): void
    {
        $editor = (object) ['ID' => 8, 'user_login' => 'editor_cap'];

        $cloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $editor,
            userDataGetter: static fn(int $id): ?object => $editor,
            userActionsControlGetter: static fn(): array => [
                'hide' => ['edit', 'delete'],
                'block' => ['edit', 'delete'],
            ]
        );

        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_users'], 'edit_users', 8, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_user'], 'edit_user', 8, [15]));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['edit_user'], 'edit_user', 8, [8]));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['delete_users'], 'delete_users', 8, []));
        $this->assertSame(['do_not_allow'], $cloaking->filterMapMetaCap(['delete_user'], 'delete_user', 8, [15]));

        $profileBlockCloaking = new SuperAdminCloaking(
            currentUserGetter: static fn(): object => $editor,
            userDataGetter: static fn(int $id): ?object => $editor,
            userActionsControlGetter: static fn(): array => [
                'hide' => ['profile.php'],
                'block' => ['profile.php'],
            ]
        );
        $this->assertSame(
            ['do_not_allow'],
            $profileBlockCloaking->filterMapMetaCap(['edit_user'], 'edit_user', 8, [8])
        );
    }

    public function testEnforceUserActionProtectionWithBlockedUserActions(): void
    {
        $editor = (object) ['ID' => 9, 'user_login' => 'editor_guard'];
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'superadmin'];

        $forbiddenMessage = '';
        $cloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            currentUserGetter: static fn(): object => $editor,
            userActionsControlGetter: static fn(): array => [
                'hide' => ['edit', 'delete', 'wfls'],
                'block' => ['edit', 'delete', 'wfls'],
            ],
            dieForbiddenHandler: static function (string $msg) use (&$forbiddenMessage): void {
                $forbiddenMessage = $msg;
            }
        );

        $forbiddenMessage = '';
        $cloaking->enforceUserActionProtection('user-edit.php');
        $this->assertSame('Sorry, you are not allowed to access this page.', $forbiddenMessage);

        $_GET['page'] = 'WFLS';
        $forbiddenMessage = '';
        $cloaking->enforceUserActionProtection('admin.php');
        $this->assertSame('Sorry, you are not allowed to access this page.', $forbiddenMessage);

        $_GET['page'] = 'allowed_page';
        $forbiddenMessage = '';
        $cloaking->enforceUserActionProtection('admin.php');
        $this->assertSame('', $forbiddenMessage);

        unset($_GET['page']);
        $cloaking->enforceUserActionProtection('admin.php');
        $this->assertSame('', $forbiddenMessage);

        $_REQUEST['action'] = 'delete';
        $forbiddenMessage = '';
        $cloaking->enforceUserActionProtection('users.php');
        $this->assertSame('Sorry, you are not allowed to access this page.', $forbiddenMessage);

        $_REQUEST['action'] = '-1';
        $_REQUEST['action2'] = 'dodelete';
        $forbiddenMessage = '';
        $cloaking->enforceUserActionProtection('users.php');
        $this->assertSame('Sorry, you are not allowed to access this page.', $forbiddenMessage);

        unset($_REQUEST['action'], $_REQUEST['action2']);
        $forbiddenMessage = '';
        $cloaking->enforceUserActionProtection('options-general.php');
        $this->assertSame('', $forbiddenMessage);

        $superCloaking = new SuperAdminCloaking(
            superAdminChecker: static fn(?object $u): bool => true,
            currentUserGetter: static fn(): object => $superAdmin,
            userActionsControlGetter: static fn(): array => [
                'hide' => ['edit', 'delete', 'wfls'],
                'block' => ['edit', 'delete', 'wfls'],
            ],
            dieForbiddenHandler: static function (string $msg) use (&$forbiddenMessage): void {
                $forbiddenMessage = $msg;
            }
        );
        $forbiddenMessage = '';
        $superCloaking->enforceUserActionProtection('user-edit.php');
        $this->assertSame('', $forbiddenMessage);
    }
}
