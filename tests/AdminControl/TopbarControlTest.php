<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use stdClass;
use WordPressDocker\AdminControl\Modules\TopbarControl;
use WordPressDocker\Tests\WpCoreCleanupTest;

/**
 * Validates administrative topbar customization, node removal, dropdown menus,
 * root node ordering, profile lock, and CSS rendering.
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
final class TopbarControlTest extends TestCase
{
    public function testShouldRemoveUserProfileScenarios(): void
    {
        $controlEmpty = new TopbarControl();
        $this->assertFalse($controlEmpty->shouldRemoveUserProfile());

        $user = (object) ['ID' => 7, 'user_login' => 'editor_user'];

        $control = new TopbarControl(
            envGetter: static fn(string $name): string => '!superadmins',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['profile'],
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

        $this->assertTrue($control->shouldRemoveUserProfile($user));
        $this->assertFalse($control->shouldRemoveUserProfile(null));
    }

    public function testGetRemovedTopbarNodeIdsForCurrentUserScenarios(): void
    {
        $controlNull = new TopbarControl(currentUserGetter: static fn(): null => null);
        $this->assertSame([], $controlNull->getRemovedTopbarNodeIdsForCurrentUser());

        $user = (object) ['ID' => 8, 'user_login' => 'author_user'];

        $controlEmpty = new TopbarControl(
            envGetter: static fn(string $name): false => false,
            currentUserGetter: static fn(): object => $user
        );
        $this->assertSame([], $controlEmpty->getRemovedTopbarNodeIdsForCurrentUser());

        $control = new TopbarControl(
            envGetter: static fn(string $name): string => 'wp-logo, comments; updates|administrator',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [
                [
                    'targets' => ['wp-logo', 'comments', ' '],
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
                    'targets' => ['updates'],
                    'isAll' => false,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => ['administrator'],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'hide',
                ],
            ],
            userMatcher: static fn(array $rule, ?object $u): bool => $rule['isAll']
        );

        $this->assertSame(['wp-logo', 'comments'], $control->getRemovedTopbarNodeIdsForCurrentUser($user));
    }

    public function testResolveAdminUrlScenarios(): void
    {
        $control = new TopbarControl();

        $this->assertSame('', $control->resolveAdminUrl(''));
        $this->assertSame('https://example.com/docs', $control->resolveAdminUrl('https://example.com/docs'));
        $this->assertSame('http://example.com/test', $control->resolveAdminUrl('http://example.com/test'));
        $this->assertSame('//example.com/asset', $control->resolveAdminUrl('//example.com/asset'));
        $expected = \function_exists('admin_url') ? \admin_url('options-general.php') : 'options-general.php';
        $this->assertSame($expected, $control->resolveAdminUrl('options-general.php'));
    }

    public function testRegisterTopbarCustomMenusAndScopeFiltering(): void
    {
        $admin = (object) ['ID' => 9, 'user_login' => 'admin_user'];
        $editor = (object) ['ID' => 10, 'user_login' => 'editor_user'];
        $addedNodes = [];

        $adminBarMock = new class ($addedNodes) {
            /**
             * @param list<array<string, mixed>> $addedNodes
             */
            public function __construct(public array &$addedNodes)
            {
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'add_node' && isset($arguments[0]) && \is_array($arguments[0])) {
                    $this->addedNodes[] = $arguments[0];
                }
                return null;
            }
        };

        $envMap = [
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_TITLE' => 'Tools & Cache',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_TARGET' => 'administrator',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_SCOPE' => 'backend',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ID' => 'custom-tools',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ICON' => 'dashicons-admin-tools<script>',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ITEMS' => 'Status|options-general.php; '
                . '[Cache] Purge|admin.php?action=purge; '
                . '[Cache] Preload|admin.php?action=preload; Empty|',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_TITLE' => 'Editor Portal',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_TARGET' => 'editor',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_SCOPE' => 'frontend',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_ITEMS' => 'Dashboard|index.php',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_3_TITLE' => 'Global Help',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_3_SCOPE' => 'all',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_3_ITEMS' => 'Docs|https://example.com/help',
        ];

        $control = new TopbarControl(
            envGetter: static fn(string $name): string|false => $envMap[$name] ?? false,
            ruleParser: static fn(string $raw): array => [
                [
                    'targets' => ['menu'],
                    'isAll' => false,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => \str_contains($raw, 'administrator') ? ['administrator'] : ['editor'],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'hide',
                ],
            ],
            userMatcher: static fn(array $r, ?object $u): bool => (
                $u === $admin && \in_array('administrator', $r['roles'], true)
            ) || (
                $u === $editor && \in_array('editor', $r['roles'], true)
            ),
            adminChecker: static fn(): bool => true
        );

        $control->registerTopbarCustomMenus($adminBarMock, $admin);

        $nodeIds = \array_column($addedNodes, 'id');
        $this->assertContains('custom-tools', $nodeIds);
        $this->assertContains('custom-tools-group-cache', $nodeIds);
        $this->assertContains('custom-tools-item-1', $nodeIds);
        $this->assertContains('custom-topbar-menu-3', $nodeIds);
        $this->assertNotContains('custom-topbar-menu-2', $nodeIds);
    }

    public function testHandleAdminBarMenuWithProfileLockAndNewContentReorder(): void
    {
        $user = (object) ['ID' => 11, 'user_login' => 'editor_user'];
        $removedNodes = [];
        $addedNodes = [];

        $existingNodes = [
            'my-account' => (object) [
                'id' => 'my-account',
                'parent' => 'top-secondary',
                'title' => 'Howdy, Editor',
                'href' => 'profile.php',
            ],
            'new-content' => (object) ['id' => 'new-content', 'title' => 'New'],
            'new-post' => (object) ['id' => 'new-post', 'parent' => 'new-content', 'title' => 'Post'],
            'new-media' => (object) ['id' => 'new-media', 'parent' => 'new-content', 'title' => 'Media'],
            'new-page' => (object) ['id' => 'new-page', 'parent' => 'new-content', 'title' => 'Page'],
        ];

        $barMock = new class ($existingNodes, $removedNodes, $addedNodes) {
            /**
             * @param array<string, object> $nodes
             * @param list<string> $removed
             * @param list<array<string, mixed>> $added
             */
            public function __construct(
                public array $nodes,
                public array &$removed,
                public array &$added
            ) {
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && \is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                    unset($this->nodes[$arguments[0]]);
                    return null;
                }
                if ($name === 'get_node' && isset($arguments[0]) && \is_string($arguments[0])) {
                    return $this->nodes[$arguments[0]] ?? null;
                }
                if ($name === 'get_nodes') {
                    return $this->nodes;
                }
                if ($name === 'add_node' && isset($arguments[0]) && \is_array($arguments[0])) {
                    $this->added[] = $arguments[0];
                    $rawId = $arguments[0]['id'] ?? '';
                    $id = \is_string($rawId) ? $rawId : '';
                    if ($id !== '') {
                        $this->nodes[$id] = (object) $arguments[0];
                    }
                    return null;
                }
                return null;
            }
        };

        $envMap = [
            'WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE' => 'editor',
            'WP_ADMIN_TOPBAR_MENU_REMOVE' => 'wp-logo',
            'WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_REMOVE' => 'new-media',
            'WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER' => 'new-page, new-post',
        ];

        $control = new TopbarControl(
            envGetter: static fn(string $name): string|false => $envMap[$name] ?? false,
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [
                [
                    'targets' => \str_contains($raw, 'profile')
                        ? ['profile']
                        : (\str_contains($raw, 'media') ? ['new-media'] : ['wp-logo']),
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
            adminChecker: static fn(): bool => false,
            queriedObjectIdGetter: static fn(): int => 42,
            postRestrictedChecker: static fn(int $id): bool => $id === 42
        );

        $control->handleAdminBarMenu($barMock);

        $this->assertContains('edit', $removedNodes);
        $this->assertContains('user-info', $removedNodes);
        $this->assertContains('my-account', $removedNodes);
        $this->assertContains('wp-logo', $removedNodes);
        $this->assertContains('new-media', $removedNodes);

        $accountNode = null;
        foreach ($addedNodes as $node) {
            if ($node['id'] === 'my-account') {
                $accountNode = $node;
                break;
            }
        }
        $this->assertNotNull($accountNode);
        $this->assertFalse($accountNode['href']);
    }

    public function testHandleTopbarMenuOrderReordersRoots(): void
    {
        $removed = [];
        $added = [];

        $rootNodes = [
            'site-name' => (object) ['id' => 'site-name', 'title' => 'My Site'],
            'comments' => (object) ['id' => 'comments', 'title' => 'Comments'],
            'updates' => (object) ['id' => 'updates', 'title' => 'Updates'],
        ];

        $barMock = new class ($rootNodes, $removed, $added) {
            /**
             * @param array<string, object> $nodes
             * @param list<string> $removed
             * @param list<array<string, mixed>> $added
             */
            public function __construct(
                public array $nodes,
                public array &$removed,
                public array &$added
            ) {
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_nodes') {
                    return $this->nodes;
                }
                if ($name === 'remove_node' && isset($arguments[0]) && \is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                    return null;
                }
                if ($name === 'add_node' && isset($arguments[0]) && \is_array($arguments[0])) {
                    $this->added[] = $arguments[0];
                    return null;
                }
                return null;
            }
        };

        $control = new TopbarControl(
            envGetter: static fn(string $name): string => 'comments:1, site-name:5, invalid:chunk, :'
        );

        $control->handleTopbarMenuOrder($barMock);

        $this->assertSame(['site-name', 'comments', 'updates'], $removed);
        $this->assertSame('comments', $added[0]['id']);
        $this->assertSame('site-name', $added[1]['id']);
        $this->assertSame('updates', $added[2]['id']);
    }

    public function testHandleBeforeAdminBarRenderRemovesRestrictedEditAndNodes(): void
    {
        $user = (object) ['ID' => 12, 'user_login' => 'restricted_user'];
        $removed = [];

        $barMock = new class ($removed) {
            /**
             * @param list<string> $removed
             */
            public function __construct(public array &$removed)
            {
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_node' && isset($arguments[0]) && \is_string($arguments[0])) {
                    $this->removed[] = $arguments[0];
                }
                return null;
            }
        };

        $GLOBALS['wp_admin_bar'] = $barMock;

        $control = new TopbarControl(
            envGetter: static fn(string $name): string => 'wp-logo',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['wp-logo'],
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
            adminChecker: static fn(): bool => false,
            queriedObjectIdGetter: static fn(): int => 55,
            postRestrictedChecker: static fn(int $id): bool => true
        );

        $control->handleBeforeAdminBarRender();

        $this->assertContains('edit', $removed);
        $this->assertContains('wp-logo', $removed);
    }

    public function testRenderStylesAndHandleWpHead(): void
    {
        $user = (object) ['ID' => 13, 'user_login' => 'front_user'];
        $echoed = [];

        $control = new TopbarControl(
            envGetter: static function (string $name): string|false {
                if ($name === 'WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE') {
                    return 'all';
                }
                if ($name === 'WP_ADMIN_TOPBAR_MENU_REMOVE') {
                    return 'wp-logo, comments';
                }
                return false;
            },
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => \str_contains($raw, 'profile') ? ['profile'] : ['wp-logo', 'comments'],
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
            queriedObjectIdGetter: static fn(): int => 99,
            postRestrictedChecker: static fn(int $id): bool => true,
            userLoggedInChecker: static fn(): bool => true,
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed[] = $html;
            }
        );

        $control->renderLockProfileStyles($user);
        $control->renderCustomIconStyles();
        $control->renderHideTopbarNodesStyles($user);
        $control->handleWpHead();

        $fullHtml = \implode("\n", $echoed);
        $this->assertStringContainsString('wp-admin-control-lock-profile', $fullHtml);
        $this->assertStringContainsString('wp-admin-control-topbar-custom-icon', $fullHtml);
        $this->assertStringContainsString('wp-admin-control-hide-topbar-nodes', $fullHtml);
        $this->assertStringContainsString('#wp-admin-bar-wp-logo', $fullHtml);
        $this->assertStringContainsString('#wp-admin-bar-comments', $fullHtml);
        $this->assertStringContainsString('#wp-admin-bar-edit', $fullHtml);
    }

    public function testDefaultFallbacksWithoutInjectedCallbacks(): void
    {
        \putenv('WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE=all');

        try {
            $control = new TopbarControl();
            $this->assertFalse($control->shouldRemoveUserProfile());

            $emptyBar = new stdClass();
            $control->handleAdminBarMenu($emptyBar);
            $control->handleTopbarMenuOrder($emptyBar);
            $control->registerTopbarCustomMenus($emptyBar);
        } finally {
            \putenv('WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE');
        }
    }

    public function testRegisterTopbarCustomMenusEdgeCases(): void
    {
        $adminBarMock = new \WP_Admin_Bar_Stub();

        $tokensItems = 'First|https://example.com/first;  ;  '
            . '|https://example.com/no-title; Second|https://example.com/second';
        $envMap = [
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_TITLE' => 'Empty Items Menu',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_ITEMS' => '   ',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_TITLE' => 'Tokens Menu',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_ITEMS' => $tokensItems,
        ];

        $control = new TopbarControl(
            envGetter: static fn(string $name): string|false => $envMap[$name] ?? false,
            currentUserGetter: static fn(): object => (object) ['ID' => 1],
            adminChecker: static fn(): bool => true
        );

        $control->registerTopbarCustomMenus($adminBarMock);

        $allTitlesCombined = \implode(' ', \array_column($adminBarMock->addedNodes, 'title'));
        $this->assertStringContainsString('Empty Items Menu', $allTitlesCombined);
        $this->assertStringContainsString('Tokens Menu', $allTitlesCombined);
        $this->assertStringContainsString('First', $allTitlesCombined);
        $this->assertStringContainsString('Second', $allTitlesCombined);
        $this->assertStringNotContainsString('no-title', $allTitlesCombined);
    }

    public function testHandleTopbarMenuOrderComprehensiveEdgeCases(): void
    {
        $control = new TopbarControl(
            envGetter: static function (string $name): string|false {
                if ($name === 'WP_ADMIN_TOPBAR_MENU_ORDER') {
                    return 'first_node:10, second_node:10';
                }
                return false;
            }
        );

        $emptyBar = new stdClass();
        $control->handleTopbarMenuOrder($emptyBar);

        $controlInvalidRules = new TopbarControl(
            envGetter: static fn(string $name): string => 'invalid_order_token, another_invalid'
        );
        $dummyBar = new \WP_Admin_Bar_Stub();
        $controlInvalidRules->handleTopbarMenuOrder($dummyBar);

        $nonArrayNodesBar = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        };
        $control->handleTopbarMenuOrder($nonArrayNodesBar);

        $nonObjectNodesBar = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_nodes') {
                    return [
                        'invalid' => 'not_an_object',
                        '' => (object) ['id' => 'site-name', 'parent' => ''],
                    ];
                }
                return null;
            }
        };
        $control->handleTopbarMenuOrder($nonObjectNodesBar);

        $onlyChildNodesBar = new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get_nodes') {
                    return [
                        'child-1' => (object) ['id' => 'child-1', 'parent' => 'parent-root'],
                    ];
                }
                return null;
            }
        };
        $control->handleTopbarMenuOrder($onlyChildNodesBar);

        $collisionBar = new \WP_Admin_Bar_Stub();
        $collisionBar->nodes = [
            'first_node' => (object) ['id' => 'first_node', 'parent' => ''],
            'second_node' => (object) ['id' => 'second_node', 'parent' => ''],
            'native_a' => (object) ['id' => 'native_a', 'parent' => ''],
            'native_b' => (object) ['id' => 'native_b', 'parent' => ''],
        ];

        $controlCollisions = new TopbarControl(
            envGetter: static fn(string $name): string => 'first_node:10, second_node:10, native_a:10'
        );
        $controlCollisions->handleTopbarMenuOrder($collisionBar);

        $addedInOrder = \array_column($collisionBar->addedNodes, 'id');
        $this->assertCount(4, $addedInOrder);
        $this->assertContains('first_node', $addedInOrder);
        $this->assertContains('second_node', $addedInOrder);
        $this->assertContains('native_a', $addedInOrder);
        $this->assertContains('native_b', $addedInOrder);
    }

    public function testHandleBeforeAdminBarRenderEdgeCases(): void
    {
        $backupAdminBar = $GLOBALS['wp_admin_bar'] ?? null;
        try {
            unset($GLOBALS['wp_admin_bar']);
            $control = new TopbarControl();
            $control->handleBeforeAdminBarRender();
            $this->assertFalse(isset($GLOBALS['wp_admin_bar']));

            $GLOBALS['wp_admin_bar'] = new stdClass();
            $controlNullUser = new TopbarControl(
                currentUserGetter: static fn(): null => null
            );
            $controlNullUser->handleBeforeAdminBarRender();
            $this->assertTrue(isset($GLOBALS['wp_admin_bar']));
        } finally {
            $GLOBALS['wp_admin_bar'] = $backupAdminBar;
        }
    }

    public function testDefaultFallbacksForHelpersAndEchoHtml(): void
    {
        $control = new TopbarControl();

        $emptyBar = new stdClass();
        $control->handleAdminBarMenu($emptyBar);

        $control->handleWpHead();

        $ruleUser = (object) ['ID' => 1, 'user_login' => 'node_user'];
        $barWithRemoveNodeOnly = new \WP_Admin_Bar_Remove_Only_Stub();
        $profileControl = new TopbarControl(
            envGetter: static fn(string $name): string => 'all',
            currentUserGetter: static fn(): object => $ruleUser,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['profile'],
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
        $profileControl->handleAdminBarMenu($barWithRemoveNodeOnly);

        $backupAdminBar = $GLOBALS['wp_admin_bar'] ?? null;
        try {
            $GLOBALS['wp_admin_bar'] = new stdClass();
            $adminFallbackControl = new TopbarControl(
                currentUserGetter: static fn(): object => $ruleUser,
                adminChecker: null
            );
            $adminFallbackControl->handleBeforeAdminBarRender();
        } finally {
            $GLOBALS['wp_admin_bar'] = $backupAdminBar;
        }

        $user = (object) ['ID' => 15, 'user_login' => 'helper_user'];
        $echoControl = new TopbarControl(
            envGetter: static fn(string $name): string => 'wp-logo',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['wp-logo'],
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
            htmlEchoer: null
        );

        \ob_start();
        $echoControl->renderHideTopbarNodesStyles($user);
        $output = \ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('wp-admin-control-hide-topbar-nodes', $output);

        $frontLoggedInControl = new TopbarControl(
            userLoggedInChecker: static fn(): bool => true,
            queriedObjectIdGetter: static fn(): int => 42,
            postRestrictedChecker: null,
            htmlEchoer: null
        );

        \ob_start();
        $frontLoggedInControl->handleWpHead();
        $frontOutput = \ob_get_clean();
        $this->assertIsString($frontOutput);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testDefaultFallbackBranchesWithoutInjectedDependencies(): void
    {
        ensure_duplicator_stubs();
        ensure_telemetry_stubs();

        $control = new TopbarControl();
        $this->assertFalse($control->shouldRemoveUserProfile());
        $this->assertSame([], $control->getRemovedTopbarNodeIdsForCurrentUser());
        $this->assertSame('https://example.com/wp-admin/edit.php', $control->resolveAdminUrl('edit.php'));

        $backupAdmin = $GLOBALS['test_is_admin'] ?? false;
        $backupLoggedIn = $GLOBALS['test_is_user_logged_in'] ?? false;
        $backupQueried = $GLOBALS['test_queried_object_id'] ?? 0;
        $backupAdminBar = $GLOBALS['wp_admin_bar'] ?? null;

        try {
            $GLOBALS['test_is_admin'] = false;
            $GLOBALS['test_is_user_logged_in'] = true;
            $GLOBALS['test_queried_object_id'] = 42;
            $GLOBALS['wp_admin_bar'] = new \WP_Admin_Bar_Remove_Only_Stub();

            $control->handleBeforeAdminBarRender();

            \ob_start();
            $control->handleWpHead();
            $control->renderLockProfileStyles();
            $control->renderCustomIconStyles();
            $control->renderHideTopbarNodesStyles();
            $output = \ob_get_clean();
            $this->assertIsString($output);
        } finally {
            $GLOBALS['test_is_admin'] = $backupAdmin;
            $GLOBALS['test_is_user_logged_in'] = $backupLoggedIn;
            $GLOBALS['test_queried_object_id'] = $backupQueried;
            $GLOBALS['wp_admin_bar'] = $backupAdminBar;
        }

        $this->assertSame('/custom-rel-path', $control->resolveAdminUrl('/custom-rel-path'));

        $scopeEnv = [
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_TITLE' => 'Frontend Menu',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_1_SCOPE' => 'frontend',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_TITLE' => 'Backend Menu',
            'WP_ADMIN_TOPBAR_CUSTOM_MENU_2_SCOPE' => 'backend',
        ];

        $scopeControlAdmin = new TopbarControl(
            envGetter: static fn(string $name): string|false => $scopeEnv[$name] ?? false,
            adminChecker: static fn(): bool => true
        );
        $barMock = new \WP_Admin_Bar_Stub();
        $scopeControlAdmin->registerTopbarCustomMenus($barMock);
        $this->assertCount(1, $barMock->addedNodes);
        $this->assertSame('<span class="ab-label">Backend Menu</span>', $barMock->addedNodes[0]['title'] ?? null);

        $barMockFrontend = new \WP_Admin_Bar_Stub();
        $scopeControlFrontend = new TopbarControl(
            envGetter: static fn(string $name): string|false => $scopeEnv[$name] ?? false,
            adminChecker: static fn(): bool => false
        );
        $scopeControlFrontend->registerTopbarCustomMenus($barMockFrontend);
        $this->assertCount(1, $barMockFrontend->addedNodes);
        $this->assertSame(
            '<span class="ab-label">Frontend Menu</span>',
            $barMockFrontend->addedNodes[0]['title'] ?? null
        );

        $orderEnv = [
            'WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER' => 'new-page,new-post',
        ];
        $orderControl = new TopbarControl(
            envGetter: static fn(string $name): string|false => $orderEnv[$name] ?? false,
        );
        $nodesMap = [
            'new-post' => (object) ['id' => 'new-post', 'parent' => 'new-content'],
            'new-page' => (object) ['id' => 'new-page', 'parent' => 'new-content'],
            'new-media' => (object) ['id' => 'new-media', 'parent' => 'new-content'],
        ];
        $barOrderMock = new \WP_Admin_Bar_Stub();
        $barOrderMock->nodes = $nodesMap;
        $orderControl->handleAdminBarMenu($barOrderMock);
        $this->assertArrayHasKey('new-media', $barOrderMock->get_nodes());
    }
}
