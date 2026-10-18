<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Modules\DashboardControl;
use WordPressDocker\Tests\WpCoreCleanupTest;

/**
 * Validates dashboard widget removal, welcome panel hiding, custom widget registration,
 * and micro-Markdown rendering.
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
final class DashboardControlTest extends TestCase
{
    private int $initialObLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialObLevel = \ob_get_level();
    }

    protected function tearDown(): void
    {
        while (\ob_get_level() > $this->initialObLevel) {
            \ob_end_clean();
        }
        parent::tearDown();
    }

    public function testShouldHideWelcomePanelScenarios(): void
    {
        $controlEmpty = new DashboardControl(
            envGetter: static fn(string $name): false => false
        );
        $this->assertFalse($controlEmpty->shouldHideWelcomePanel());

        $controlNoParser = new DashboardControl(
            envGetter: static fn(string $name): string => 'welcome-panel'
        );
        $this->assertFalse($controlNoParser->shouldHideWelcomePanel());

        $user = (object) ['ID' => 3, 'user_login' => 'editor_user'];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => ['dashboard_quick_press'],
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
                'targets' => ['welcome-panel'],
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

        $controlMatch = new DashboardControl(
            envGetter: static fn(string $name): string => 'dashboard_quick_press,welcome-panel',
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user
        );

        $this->assertTrue($controlMatch->shouldHideWelcomePanel($user));
        $this->assertFalse($controlMatch->shouldHideWelcomePanel(null));
    }

    public function testHandleDashboardSetupRemovesWidgetsAndRegistersCustomWidgets(): void
    {
        $user = (object) ['ID' => 5, 'user_login' => 'author_user'];
        $removedActions = [];
        $removedMetaBoxes = [];
        $addedWidgets = [];
        $echoedHtml = [];

        $envMap = [
            'WP_ADMIN_DASHBOARD_WIDGETS_REMOVE' => 'welcome-panel,dashboard_primary|all; '
                . 'dashboard_right_now|administrator',
            'WP_ADMIN_DASHBOARD_WIDGET_1_TITLE' => '  <b>Support Notice</b>  ',
            'WP_ADMIN_DASHBOARD_WIDGET_1_TARGET' => 'author',
            'WP_ADMIN_DASHBOARD_WIDGET_1_CONTENT' => '**Need help?** Contact [Support](mailto:help@example.com)',
            'WP_ADMIN_DASHBOARD_WIDGET_2_TITLE' => 'Admin Only Widget',
            'WP_ADMIN_DASHBOARD_WIDGET_2_TARGET' => 'administrator',
            'WP_ADMIN_DASHBOARD_WIDGET_2_CONTENT' => 'Restricted content',
            'WP_ADMIN_DASHBOARD_WIDGET_3_TITLE' => 'General Info',
            'WP_ADMIN_DASHBOARD_WIDGET_3_TARGET' => '',
            'WP_ADMIN_DASHBOARD_WIDGET_3_CONTENT' => false,
            'WP_ADMIN_DASHBOARD_WIDGET_4_TITLE' => '',
        ];

        $control = new DashboardControl(
            envGetter: static fn(string $name): string|false => $envMap[$name] ?? false,
            currentUserGetter: static fn(): object => $user,
            ruleParser: static function (string $raw): array {
                if (\str_starts_with($raw, 'widget|')) {
                    $role = \substr($raw, 7);
                    return [[
                        'targets' => ['widget'],
                        'isAll' => $role === 'all',
                        'superadmins' => false,
                        'invertedSuperadmins' => false,
                        'roles' => $role === 'all' ? [] : [$role],
                        'invertedRoles' => [],
                        'users' => [],
                        'invertedUsers' => [],
                        'action' => 'hide',
                    ]];
                }

                return [
                    [
                        'targets' => ['welcome-panel', 'dashboard_primary'],
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
                        'targets' => ['dashboard_right_now'],
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
            },
            userMatcher: static function (array $rule, ?object $u) use ($user): bool {
                if ($u !== $user) {
                    return false;
                }
                if ($rule['isAll']) {
                    return true;
                }
                return \in_array('author', $rule['roles'], true);
            },
            actionRemover: static function (string $tag, callable|string $cb, int $prio) use (&$removedActions): void {
                $removedActions[] = [$tag, $cb, $prio];
            },
            metaBoxRemover: static function (
                string $id,
                string|object $screen,
                string $ctx
            ) use (&$removedMetaBoxes): void {
                $removedMetaBoxes[] = [$id, $screen, $ctx];
            },
            dashboardWidgetAdder: static function (
                string $id,
                string $name,
                callable $cb
            ) use (&$addedWidgets): void {
                $addedWidgets[] = [$id, $name];
                $cb();
            },
            htmlEchoer: static function (string $html) use (&$echoedHtml): void {
                $echoedHtml[] = $html;
            }
        );

        $control->handleDashboardSetup();

        $this->assertSame([['welcome_panel', 'wp_welcome_panel', 10]], $removedActions);
        $this->assertSame([
            ['dashboard_primary', 'dashboard', 'normal'],
            ['dashboard_primary', 'dashboard', 'side'],
            ['dashboard_primary', 'dashboard', 'advanced'],
        ], $removedMetaBoxes);

        $this->assertSame([
            ['wp_admin_control_widget_1', 'Support Notice'],
            ['wp_admin_control_widget_3', 'General Info'],
        ], $addedWidgets);

        $this->assertCount(2, $echoedHtml);
        $this->assertStringContainsString('<strong>Need help?</strong>', $echoedHtml[0]);
        $this->assertStringContainsString('<a href="mailto:help@example.com">Support</a>', $echoedHtml[0]);
        $this->assertSame('', $echoedHtml[1]);
    }

    public function testRenderMicroMarkdownFormattingAndLists(): void
    {
        $control = new DashboardControl();

        $this->assertSame('', $control->renderMicroMarkdown('   '));

        $markdown = "# Heading 1\r\n"
            . "- First item\n"
            . "- Second *italic* and **bold** item\n"
            . "### Heading 3 after list\n"
            . "Paragraph with [Portal](https://portal.example.com/docs) link\n"
            . "- Trailing list item";

        $rendered = $control->renderMicroMarkdown($markdown);

        $this->assertStringContainsString('<h2 style="margin: 0.5em 0;">Heading 1</h2>', $rendered);
        $this->assertStringContainsString('<ul>', $rendered);
        $this->assertStringContainsString('<li>First item</li>', $rendered);
        $this->assertStringContainsString('<li>Second <em>italic</em> and <strong>bold</strong> item</li>', $rendered);
        $this->assertStringContainsString('<h4 style="margin: 0.5em 0;">Heading 3 after list</h4>', $rendered);
        $this->assertStringContainsString(
            '<a href="https://portal.example.com/docs" target="_blank" rel="noopener noreferrer">Portal</a>',
            $rendered
        );
        $this->assertStringContainsString('<li>Trailing list item</li>', $rendered);
        $this->assertStringEndsWith('</ul>', $rendered);

        $listThenParagraph = "- Single item\nPlain text line";
        $renderedParagraph = $control->renderMicroMarkdown($listThenParagraph);
        $this->assertStringContainsString(
            "<ul><br>\n<li>Single item</li><br>\n</ul><br>\nPlain text line",
            $renderedParagraph
        );
    }

    public function testDefaultFallbacksWithoutCallbacks(): void
    {
        \putenv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE=welcome-panel,dashboard_quick_press');
        \putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TITLE=Fallback Widget');
        \putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TARGET=editor');
        \putenv('WP_ADMIN_DASHBOARD_WIDGET_1_CONTENT=Hello World');

        try {
            $control = new DashboardControl(
                ruleParser: static fn(string $raw): array => [[
                    'targets' => ['welcome-panel', 'dashboard_quick_press'],
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

            $control->handleDashboardSetup();
            $this->assertTrue($control->shouldHideWelcomePanel());
        } finally {
            \putenv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE');
            \putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TITLE');
            \putenv('WP_ADMIN_DASHBOARD_WIDGET_1_TARGET');
            \putenv('WP_ADMIN_DASHBOARD_WIDGET_1_CONTENT');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHandleDashboardSetupWithGlobalWordPressFunctionStubs(): void
    {
        require_once \dirname(__DIR__, 2) . '/stubs/WP_Widgets_Stub.php';

        $envMap = [
            'WP_ADMIN_DASHBOARD_WIDGETS_REMOVE' => 'welcome-panel,dashboard_primary',
            'WP_ADMIN_DASHBOARD_WIDGET_1_TITLE' => 'Stubbed Widget',
            'WP_ADMIN_DASHBOARD_WIDGET_1_CONTENT' => 'Rendered via echo fallback',
        ];

        $control = new DashboardControl(
            envGetter: static fn(string $name): string|false => $envMap[$name] ?? false,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['welcome-panel', 'dashboard_primary'],
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

        \ob_start();
        $control->handleDashboardSetup();
        $output = \ob_get_clean();

        $this->assertSame('Rendered via echo fallback', $output);
    }

    public function testHandleAdminHeadRendersStyleWhenWelcomePanelHidden(): void
    {
        $echoed = [];
        $control = new DashboardControl(
            envGetter: static fn(string $name): string => 'welcome-panel',
            currentUserGetter: static fn(): object => (object) ['ID' => 1],
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['welcome-panel'],
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
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed[] = $html;
            }
        );

        $control->handleAdminHead();

        $this->assertCount(1, $echoed);
        $this->assertStringContainsString('wp-admin-control-hide-welcome-panel', $echoed[0]);
    }

    public function testHandleAdminHeadDoesNothingWhenNotHidden(): void
    {
        $echoed = [];
        $control = new DashboardControl(
            envGetter: static fn(string $name): false => false,
            htmlEchoer: static function (string $html) use (&$echoed): void {
                $echoed[] = $html;
            }
        );

        $control->handleAdminHead();

        $this->assertSame([], $echoed);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testGetCurrentUserFallbackBranches(): void
    {
        ensure_admin_control_stubs();

        $backupCurrentUser = $GLOBALS['test_current_user'] ?? null;
        try {
            $GLOBALS['test_current_user'] = (object) ['ID' => 42];
            $control = new DashboardControl();
            $control->handleAdminHead();
            $this->assertFalse($control->shouldHideWelcomePanel());
        } finally {
            $GLOBALS['test_current_user'] = $backupCurrentUser;
        }
    }

    public function testShouldHideWelcomePanelWithNullUserAndCurrentUserGetterFalse(): void
    {
        $user = (object) ['ID' => 10, 'roles' => ['editor']];
        $control = new DashboardControl(
            envGetter: static fn(string $name): string => 'welcome-panel',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['welcome-panel'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user
        );

        $this->assertTrue($control->shouldHideWelcomePanel());
        $this->assertTrue($control->shouldHideWelcomePanel(null));

        $controlFalse = new DashboardControl(
            currentUserGetter: static fn(): mixed => false
        );
        $reflectionCurrentUser = new \ReflectionMethod($controlFalse, 'getCurrentUser');
        $this->assertNull($reflectionCurrentUser->invoke($controlFalse));
    }

    public function testShouldHideScreenOptionsAndHelpScenarios(): void
    {
        $controlEmpty = new DashboardControl(
            envGetter: static fn(string $name): false => false
        );
        $this->assertFalse($controlEmpty->shouldHideScreenOptionsAndHelp());

        $controlWhitespace = new DashboardControl(
            envGetter: static fn(string $name): string => "   "
        );
        $this->assertFalse($controlWhitespace->shouldHideScreenOptionsAndHelp());

        $controlNoParser = new DashboardControl(
            envGetter: static fn(string $name): string => 'true'
        );
        $this->assertFalse($controlNoParser->shouldHideScreenOptionsAndHelp());

        $controlEmptyRules = new DashboardControl(
            envGetter: static fn(string $name): string => 'true',
            ruleParser: static fn(string $raw): array => [],
            userMatcher: static fn(array $r, ?object $u): bool => true
        );
        $this->assertFalse($controlEmptyRules->shouldHideScreenOptionsAndHelp());

        $user = (object) ['ID' => 7, 'roles' => ['editor']];
        $controlMatch = new DashboardControl(
            envGetter: static fn(string $name): string => 'editor',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['screen_options'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $u): bool => $u === $user
        );

        $this->assertTrue($controlMatch->shouldHideScreenOptionsAndHelp());
        $this->assertTrue($controlMatch->shouldHideScreenOptionsAndHelp($user));
        $this->assertFalse($controlMatch->shouldHideScreenOptionsAndHelp((object) ['ID' => 99]));
    }

    public function testHasActiveDashboardWidgetsBranches(): void
    {
        $control = new DashboardControl();

        $backupMetaBoxes = $GLOBALS['wp_meta_boxes'] ?? null;
        try {
            unset($GLOBALS['wp_meta_boxes']['dashboard']);
            $this->assertFalse($control->hasActiveDashboardWidgets());

            $GLOBALS['wp_meta_boxes']['dashboard'] = 'invalid_not_array';
            $this->assertFalse($control->hasActiveDashboardWidgets());

            $GLOBALS['wp_meta_boxes']['dashboard'] = [
                'normal' => 'not_array_context',
                'side' => [
                    'core' => 'not_array_priority',
                    'default' => [
                        'empty_widget' => [],
                        'removed_widget' => false,
                    ],
                ],
            ];
            $this->assertFalse($control->hasActiveDashboardWidgets());

            $GLOBALS['wp_meta_boxes']['dashboard']['side']['default']['active_widget'] = [
                'id' => 'active_widget',
                'title' => 'Active',
            ];
            $this->assertTrue($control->hasActiveDashboardWidgets());
        } finally {
            $GLOBALS['wp_meta_boxes'] = $backupMetaBoxes;
        }
    }

    public function testBufferingLifecycleAndCustomBufferStarter(): void
    {
        $controlNative = new DashboardControl();
        $this->assertFalse($controlNative->isBufferingStarted());

        $controlNative->startOutputBuffering();
        $this->assertTrue($controlNative->isBufferingStarted());

        $controlNative->startOutputBuffering();
        $this->assertTrue($controlNative->isBufferingStarted());

        $startedCustom = false;
        $controlCustom = new DashboardControl(
            bufferStarter: static function (callable $handler) use (&$startedCustom): bool {
                $startedCustom = true;
                return true;
            }
        );
        $this->assertFalse($controlCustom->isBufferingStarted());
        $controlCustom->startOutputBuffering();
        $this->assertTrue($controlCustom->isBufferingStarted());
        $this->assertTrue($startedCustom);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testHandleAdminInitAndHandleAdminEnqueueScripts(): void
    {
        ensure_admin_control_stubs();

        $user = (object) ['ID' => 1];
        $dequeued = [];
        $currentPage = 'edit.php';

        $control = new DashboardControl(
            envGetter: static fn(string $name): string => 'true',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['screen_options'],
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
            pageNowGetter: function () use (&$currentPage): string {
                return $currentPage;
            },
            scriptDequeuer: static function (string $handle) use (&$dequeued): void {
                $dequeued[] = $handle;
            }
        );

        $control->handleAdminInit();
        $this->assertFalse($control->isBufferingStarted());

        $control->handleAdminEnqueueScripts();
        $this->assertSame([], $dequeued);

        $currentPage = 'index.php';

        $backupMetaBoxes = $GLOBALS['wp_meta_boxes'] ?? null;
        try {
            $GLOBALS['wp_meta_boxes']['dashboard'] = [
                'normal' => ['core' => ['test_widget' => ['id' => 'test_widget']]],
            ];
            $control->handleAdminEnqueueScripts();
            $this->assertSame([], $dequeued);

            $GLOBALS['wp_meta_boxes']['dashboard'] = [];
            $control->handleAdminEnqueueScripts();
            $this->assertSame(['dashboard'], $dequeued);
        } finally {
            $GLOBALS['wp_meta_boxes'] = $backupMetaBoxes;
        }

        $control->handleAdminInit();
        $this->assertTrue($control->isBufferingStarted());

        $controlNoOptions = new DashboardControl(
            envGetter: static fn(string $name): false => false,
            pageNowGetter: static fn(): string => 'index.php'
        );
        $controlNoOptions->handleAdminInit();
        $this->assertFalse($controlNoOptions->isBufferingStarted());

        $GLOBALS['test_dequeued_scripts'] = [];
        $controlFallback = new DashboardControl(
            envGetter: static fn(string $name): string => 'true',
            ruleParser: static fn(string $raw): array => [['targets' => ['screen_options']]],
            userMatcher: static fn(array $r, ?object $u): bool => true,
            pageNowGetter: static fn(): string => 'index.php',
            scriptDequeuer: null
        );
        $reflectionDequeue = new \ReflectionMethod($controlFallback, 'dequeueScript');
        $reflectionDequeue->invoke($controlFallback, 'dashboard');
        $this->assertContains('dashboard', $GLOBALS['test_dequeued_scripts'] ?? []);

        $reflectionPageNowHelper = new \ReflectionMethod($controlFallback, 'getPageNow');
        $this->assertSame('index.php', $reflectionPageNowHelper->invoke($controlFallback));

        $backupPageNow = $GLOBALS['pagenow'] ?? null;
        try {
            unset($GLOBALS['pagenow']);
            $controlNoPageNow = new DashboardControl();
            $reflectionNoPageNow = new \ReflectionMethod($controlNoPageNow, 'getPageNow');
            $this->assertSame('', $reflectionNoPageNow->invoke($controlNoPageNow));
            $GLOBALS['pagenow'] = 'tools.php';
            $this->assertSame('tools.php', $reflectionNoPageNow->invoke($controlNoPageNow));
        } finally {
            $GLOBALS['pagenow'] = $backupPageNow;
        }
    }

    public function testFilterBufferAndCleanDashboardWidgetsContainer(): void
    {
        $controlDisabled = new DashboardControl(
            envGetter: static fn(string $name): false => false
        );
        $sampleHtml = '<div class="wrap"><h1>Dashboard</h1><div id="dashboard-widgets-wrap"></div></div>';
        $this->assertSame($sampleHtml, $controlDisabled->filterBuffer($sampleHtml));

        $controlEnabled = new DashboardControl(
            envGetter: static fn(string $name): string => 'true',
            ruleParser: static fn(string $raw): array => [['targets' => ['screen_options']]],
            userMatcher: static fn(array $r, ?object $u): bool => true
        );

        $noContainerHtml = '<div class="wrap"><h1>Dashboard</h1><p>Notices</p></div>';
        $this->assertSame($noContainerHtml, $controlEnabled->filterBuffer($noContainerHtml));

        $withWidgetHtml = '<div class="wrap"><h1>Dashboard</h1>'
            . '<div id="dashboard-widgets-wrap">'
            . '<div id="postbox-container-1" class="postbox-container">'
            . '<div id="widget_1" class="postbox closed"><p>Widget Content</p></div>'
            . '</div>'
            . '</div><!-- dashboard-widgets-wrap -->'
            . '</div>';
        $this->assertSame($withWidgetHtml, $controlEnabled->filterBuffer($withWidgetHtml));

        $emptyHtml = "<div class=\"wrap\">\n\t<h1>Dashboard</h1>\n\t"
            . "<div id=\"dashboard-widgets-wrap\">\n"
            . "\t<div id=\"dashboard-widgets\" class=\"metabox-holder\">\n"
            . "\t<div id=\"postbox-container-1\" class=\"postbox-container\">\n"
            . "\t<div class=\"meta-box-sortables ui-sortable empty-container\"></div></div>\n"
            . "</div>\n"
            . "\t<input type=\"hidden\" id=\"closedpostboxesnonce\" value=\"1\">\n"
            . "\t</div><!-- dashboard-widgets-wrap -->\n</div>";
        $cleanedHtml = $controlEnabled->filterBuffer($emptyHtml);
        $this->assertStringNotContainsString('dashboard-widgets-wrap', $cleanedHtml);
        $this->assertStringNotContainsString('closedpostboxesnonce', $cleanedHtml);
        $this->assertStringContainsString('<h1>Dashboard</h1>', $cleanedHtml);

        $noCommentEmptyHtml = '<div class="wrap"><h1>Dashboard</h1>'
            . '<div class="holder" id="dashboard-widgets-wrap">'
            . '<div class="postbox-container"><div></div></div>'
            . '</div>'
            . '</div>';
        $cleanedNoComment = $controlEnabled->filterBuffer($noCommentEmptyHtml);
        $this->assertStringNotContainsString('dashboard-widgets-wrap', $cleanedNoComment);
        $this->assertStringContainsString('<h1>Dashboard</h1>', $cleanedNoComment);

        $unclosedHtml = '<div class="wrap"><div id="dashboard-widgets-wrap"><div class="nested">';
        $this->assertSame($unclosedHtml, $controlEnabled->filterBuffer($unclosedHtml));
    }
}
