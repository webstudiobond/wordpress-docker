<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Modules\SidebarWidgetsControl;

/**
 * Validates sidebar widget unregistration and role-based widget removal rules.
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
final class SidebarWidgetsControlTest extends TestCase
{
    public function testGetWidgetsToRemoveWhenEnvNotSetOrEmpty(): void
    {
        $scenarios = [
            'false' => false,
            'empty' => '',
            'whitespace' => '   ',
        ];

        foreach ($scenarios as $val) {
            $control = new SidebarWidgetsControl(
                envGetter: static fn(string $name): string|false => $val
            );
            $this->assertSame([], $control->getWidgetsToRemoveForUser());
        }
    }

    public function testGetWidgetsToRemoveWhenParserOrMatcherMissing(): void
    {
        $controlNoParser = new SidebarWidgetsControl(
            envGetter: static fn(string $name): string => 'pages,calendar'
        );
        $this->assertSame([], $controlNoParser->getWidgetsToRemoveForUser());

        $controlNoMatcher = new SidebarWidgetsControl(
            envGetter: static fn(string $name): string => 'pages,calendar',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['pages'],
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
        $this->assertSame([], $controlNoMatcher->getWidgetsToRemoveForUser());
    }

    public function testGetWidgetsToRemoveMapsStandardAliasesAndDeduplicates(): void
    {
        $currentUser = (object) ['ID' => 7, 'user_login' => 'author_user'];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => ['pages', 'recent-posts', 'custom_widget', '   ', 'pages'],
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
                'targets' => ['calendar'],
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

        $control = new SidebarWidgetsControl(
            envGetter: static fn(string $name): string => 'pages,recent-posts,custom_widget|all; '
                . 'calendar|administrator',
            currentUserGetter: static fn(): object => $currentUser,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static fn(array $rule, ?object $user): bool => $rule['isAll'] && $user === $currentUser
        );

        $this->assertSame(
            ['pages', 'WP_Widget_Pages', 'recent-posts', 'WP_Widget_Recent_Posts', 'custom_widget'],
            $control->getWidgetsToRemoveForUser()
        );
    }

    public function testHandleWidgetsInitUnregistersMatchedWidgetsViaCallback(): void
    {
        $unregistered = [];
        $currentUser = (object) ['ID' => 12, 'user_login' => 'editor_user'];

        $control = new SidebarWidgetsControl(
            envGetter: static fn(string $name): string => 'search,tag-cloud',
            currentUserGetter: static fn(): object => $currentUser,
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['search', 'tag-cloud'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $user): bool => $user === $currentUser,
            widgetUnregisterer: static function (string $widgetClass) use (&$unregistered): void {
                $unregistered[] = $widgetClass;
            }
        );

        $control->handleWidgetsInit();

        $this->assertSame(
            ['search', 'WP_Widget_Search', 'tag-cloud', 'WP_Widget_Tag_Cloud'],
            $unregistered
        );
    }

    public function testDefaultEnvAndUserFallbacks(): void
    {
        \putenv('WP_ADMIN_SIDEBAR_WIDGETS_REMOVE=meta');

        try {
            $control = new SidebarWidgetsControl(
                ruleParser: static fn(string $raw): array => [[
                    'targets' => ['meta'],
                    'isAll' => true,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => [],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'hide',
                ]],
                userMatcher: static fn(array $rule, ?object $user): bool => $user === null
            );

            $this->assertSame(['meta', 'WP_Widget_Meta'], $control->getWidgetsToRemoveForUser());
            $control->handleWidgetsInit();
        } finally {
            \putenv('WP_ADMIN_SIDEBAR_WIDGETS_REMOVE');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHandleWidgetsInitWithGlobalUnregisterWidgetStub(): void
    {
        require_once \dirname(__DIR__, 2) . '/stubs/WP_Widgets_Stub.php';

        $control = new SidebarWidgetsControl(
            envGetter: static fn(string $name): string => 'archives',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['archives'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static fn(array $rule, ?object $user): bool => true
        );

        $control->handleWidgetsInit();
        $this->assertTrue(\function_exists('unregister_widget'));
    }
}
