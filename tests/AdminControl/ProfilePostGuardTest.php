<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Security\ProfilePostGuard;
use WordPressDocker\Tests\WpCoreCleanupTest;

final class ProfilePostGuardTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $savedPost = [];

    /**
     * @var array<string, mixed>
     */
    private array $savedGet = [];

    /**
     * @var array<string, mixed>
     */
    private array $savedRequest = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedPost = $_POST;
        $this->savedGet = $_GET;
        $this->savedRequest = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_GET = $this->savedGet;
        $_REQUEST = $this->savedRequest;
        parent::tearDown();
    }

    public function testHandleProfilePostProtectionWithNullOrInvalidUser(): void
    {
        $guard = new ProfilePostGuard();

        $cases = [
            null,
            (object) ['ID' => 0],
            (object) ['ID' => -1],
        ];

        foreach ($cases as $user) {
            $_POST = ['email' => 'hacker@example.org'];
            $_REQUEST = $_POST;
            $guard->handleProfilePostProtection($user, 'profile.php', ['email']);
            $this->assertSame('hacker@example.org', $_POST['email']);
        }
    }

    public function testHandleProfilePostProtectionOnNonTargetPage(): void
    {
        $guard = new ProfilePostGuard();
        $user = (object) ['ID' => 10, 'user_email' => 'user10@example.org'];

        $_POST = ['email' => 'hacker@example.org'];
        $_REQUEST = $_POST;

        $guard->handleProfilePostProtection($user, 'index.php', ['email']);
        $this->assertSame('hacker@example.org', $_POST['email']);
    }

    public function testHandleProfilePostProtectionOnUserEditPage(): void
    {
        $guard = new ProfilePostGuard();
        $user = (object) ['ID' => 10, 'user_email' => 'user10@example.org'];

        $invalidEditCases = [
            ['post' => ['email' => 'hacker@example.org'], 'get' => []],
            ['post' => ['user_id' => 'invalid_id', 'email' => 'hacker@example.org'], 'get' => []],
            ['post' => ['user_id' => '99', 'email' => 'hacker@example.org'], 'get' => []],
        ];

        foreach ($invalidEditCases as $case) {
            $_POST = $case['post'];
            $_GET = $case['get'];
            $_REQUEST = $_POST;
            $guard->handleProfilePostProtection($user, 'user-edit.php', ['email']);
            $this->assertSame('hacker@example.org', $_POST['email']);
        }

        $_POST = ['email' => 'hacker@example.org'];
        $_GET = ['user_id' => '10'];
        $_REQUEST = $_POST;
        $guard->handleProfilePostProtection($user, 'user-edit.php', ['email']);
        $this->assertSame('user10@example.org', $_POST['email']);
    }

    public function testHandleProfilePostProtectionWithEmptyFieldsOrResolver(): void
    {
        $user = (object) ['ID' => 10, 'user_email' => 'user10@example.org'];

        $scenarios = [
            [new ProfilePostGuard(), []],
            [new ProfilePostGuard(), null],
            [new ProfilePostGuard(fn(?object $u): array => []), null],
        ];

        foreach ($scenarios as [$guard, $fields]) {
            $_POST = ['email' => 'hacker@example.org'];
            $_REQUEST = $_POST;
            $guard->handleProfilePostProtection($user, 'profile.php', $fields);
            $this->assertSame('hacker@example.org', $_POST['email']);
        }

        $activeResolverGuard = new ProfilePostGuard(fn(?object $u): array => ['email']);
        $_POST = ['email' => 'hacker@example.org'];
        $_REQUEST = $_POST;
        $activeResolverGuard->handleProfilePostProtection($user, 'profile.php', null);
        $this->assertSame('user10@example.org', $_POST['email']);
    }

    public function testEnforceProfilePostProtectionEmailVariations(): void
    {
        $guard = new ProfilePostGuard();
        $user = (object) ['ID' => 10, 'user_email' => 'original@example.org'];

        $_POST = ['email' => 'modified@example.org'];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($user, ['email']);
        $this->assertSame('original@example.org', $_POST['email']);
        $this->assertSame('original@example.org', $_REQUEST['email']);

        $_POST = ['email' => 'modified2@example.org'];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($user, ['user_email']);
        $this->assertSame('original@example.org', $_POST['email']);
        $this->assertSame('original@example.org', $_REQUEST['email']);
    }

    public function testEnforceProfilePostProtectionNicknameFallbacks(): void
    {
        $guard = new ProfilePostGuard();

        $userWithNick = (object) [
            'ID' => 10,
            'nickname' => 'ActualNick',
            'display_name' => 'DisplayName',
            'user_login' => 'login_name',
        ];
        $_POST = ['nickname' => 'HackerNick'];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($userWithNick, ['nickname']);
        $this->assertSame('ActualNick', $_POST['nickname']);
        $this->assertSame('ActualNick', $_REQUEST['nickname']);

        $userWithDisplay = (object) [
            'ID' => 10,
            'display_name' => 'DisplayName',
            'user_login' => 'login_name',
        ];
        $_POST = ['nickname' => 'HackerNick'];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($userWithDisplay, ['nickname']);
        $this->assertSame('DisplayName', $_POST['nickname']);
        $this->assertSame('DisplayName', $_REQUEST['nickname']);

        $userWithLoginOnly = (object) [
            'ID' => 10,
            'nickname' => '',
            'display_name' => '',
            'user_login' => 'login_name',
        ];
        $_POST = ['nickname' => 'HackerNick'];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($userWithLoginOnly, ['nickname']);
        $this->assertSame('login_name', $_POST['nickname']);
        $this->assertSame('login_name', $_REQUEST['nickname']);
    }

    public function testEnforceProfilePostProtectionToolbarVariations(): void
    {
        $guard = new ProfilePostGuard();
        $user = (object) ['ID' => 10];

        foreach (['toolbar', 'admin_bar', 'admin_bar_front'] as $target) {
            $_POST = ['admin_bar_front' => '1'];
            $_REQUEST = $_POST;
            $guard->enforceProfilePostProtection($user, [$target]);
            $this->assertSame('0', $_POST['admin_bar_front']);
            $this->assertSame('0', $_REQUEST['admin_bar_front']);
        }
    }

    public function testEnforceProfilePostProtectionUnsetsAliasesAndCustomFields(): void
    {
        $guard = new ProfilePostGuard();
        $user = (object) ['ID' => 10];

        $_POST = [
            'website' => 'https://example.org/my-site',
            'url' => 'https://example.org/my-url',
            'safe_field' => 'preserved',
        ];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($user, ['website']);
        $this->assertArrayNotHasKey('website', $_POST);
        $this->assertArrayNotHasKey('url', $_POST);
        $this->assertArrayNotHasKey('website', $_REQUEST);
        $this->assertArrayNotHasKey('url', $_REQUEST);
        $this->assertSame('preserved', $_POST['safe_field']);

        $_POST = [
            'website' => 'https://example.org/site2',
            'url' => 'https://example.org/url2',
        ];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($user, ['url']);
        $this->assertArrayNotHasKey('website', $_POST);
        $this->assertArrayNotHasKey('url', $_POST);

        $_POST = [
            'bio' => 'Sample biography',
            'description' => 'Sample description',
        ];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($user, ['bio']);
        $this->assertArrayNotHasKey('bio', $_POST);
        $this->assertArrayNotHasKey('description', $_POST);
        $this->assertArrayNotHasKey('bio', $_REQUEST);
        $this->assertArrayNotHasKey('description', $_REQUEST);

        $_POST = [
            'custom_meta_key' => 'secret_val',
        ];
        $_REQUEST = $_POST;
        $guard->enforceProfilePostProtection($user, ['custom_meta_key', '!!!@@@###']);
        $this->assertArrayNotHasKey('custom_meta_key', $_POST);
        $this->assertArrayNotHasKey('custom_meta_key', $_REQUEST);
    }

    public function testEnforceProfilePostProtectionAdminColorVariations(): void
    {
        $guard = new ProfilePostGuard();

        $cases = [
            [
                'target' => 'admin_color',
                'user' => (object) ['ID' => 10, 'admin_color' => 'ocean'],
                'expected' => 'ocean',
            ],
            [
                'target' => 'color_scheme',
                'user' => (object) ['ID' => 10, 'admin_color' => 'coffee'],
                'expected' => 'coffee',
            ],
            [
                'target' => 'admin-color',
                'user' => (object) ['ID' => 10, 'admin_color' => ''],
                'expected' => 'fresh',
            ],
            [
                'target' => 'admin_color',
                'user' => (object) ['ID' => 10],
                'expected' => 'fresh',
            ],
        ];

        foreach ($cases as $case) {
            $_POST = ['admin_color' => 'tampered'];
            $_REQUEST = $_POST;
            $guard->enforceProfilePostProtection($case['user'], [$case['target']]);
            $this->assertSame($case['expected'], $_POST['admin_color']);
            $this->assertSame($case['expected'], $_REQUEST['admin_color']);
        }
    }

    public function testEnforceProfilePostProtectionSyntaxHighlightingVariations(): void
    {
        $guard = new ProfilePostGuard();

        $cases = [
            [
                'target' => 'syntax_highlighting',
                'user' => (object) ['ID' => 10, 'syntax_highlighting' => 'false'],
                'expected' => 'false',
            ],
            [
                'target' => 'syntax-highlighting',
                'user' => (object) ['ID' => 10, 'syntax_highlighting' => ''],
                'expected' => 'true',
            ],
            [
                'target' => 'syntax_highlighting',
                'user' => (object) ['ID' => 10],
                'expected' => 'true',
            ],
        ];

        foreach ($cases as $case) {
            $_POST = ['syntax_highlighting' => 'tampered'];
            $_REQUEST = $_POST;
            $guard->enforceProfilePostProtection($case['user'], [$case['target']]);
            $this->assertSame($case['expected'], $_POST['syntax_highlighting']);
            $this->assertSame($case['expected'], $_REQUEST['syntax_highlighting']);
        }
    }

    public function testFilterRestPreInsertUserNonObjectAndInvalidTargets(): void
    {
        $guard = new ProfilePostGuard();

        $nonObjects = [
            'not an object',
            123,
            null,
            false,
        ];
        foreach ($nonObjects as $item) {
            $this->assertSame($item, $guard->filterRestPreInsertUser($item, null));
        }

        $guardNoUser = new ProfilePostGuard(currentUserGetter: static fn(): ?object => null);
        $prepared = (object) ['ID' => 10, 'user_email' => 'mod@example.org'];
        $this->assertSame($prepared, $guardNoUser->filterRestPreInsertUser($prepared, null));

        $guardUserZero = new ProfilePostGuard(currentUserGetter: static fn(): object => (object) ['ID' => 0]);
        $this->assertSame($prepared, $guardUserZero->filterRestPreInsertUser($prepared, null));

        $currentUser = (object) ['ID' => 5, 'user_email' => 'orig@example.org'];
        $guardNormal = new ProfilePostGuard(
            fieldsResolver: static fn(?object $u): array => ['email'],
            currentUserGetter: static fn(): object => $currentUser,
        );

        $preparedZeroId = (object) ['ID' => 0, 'user_email' => 'mod@example.org'];
        $this->assertSame($preparedZeroId, $guardNormal->filterRestPreInsertUser($preparedZeroId, null));

        $preparedDifferentUser = (object) ['ID' => 99, 'user_email' => 'other@example.org'];
        $this->assertSame($preparedDifferentUser, $guardNormal->filterRestPreInsertUser($preparedDifferentUser, null));
    }

    public function testFilterRestPreInsertUserEmptyResolvedFields(): void
    {
        $currentUser = (object) ['ID' => 5, 'user_email' => 'orig@example.org'];
        $guardEmptyResolver = new ProfilePostGuard(
            fieldsResolver: static fn(?object $u): array => [],
            currentUserGetter: static fn(): object => $currentUser,
        );
        $prepared = (object) ['ID' => 5, 'user_email' => 'mod@example.org'];
        $this->assertSame($prepared, $guardEmptyResolver->filterRestPreInsertUser($prepared, null));

        $guardNullResolver = new ProfilePostGuard(
            fieldsResolver: null,
            currentUserGetter: static fn(): object => $currentUser,
        );
        $this->assertSame($prepared, $guardNullResolver->filterRestPreInsertUser($prepared, null));
    }

    public function testFilterRestPreInsertUserRestoresAllProtectedFieldsOnSelfEdit(): void
    {
        $currentUser = (object) [
            'ID' => 7,
            'user_email' => 'original7@example.org',
            'nickname' => 'NickSeven',
            'first_name' => 'FirstSeven',
            'last_name' => 'LastSeven',
            'display_name' => 'DisplaySeven',
            'user_url' => 'https://example.org/seven',
            'description' => 'Original bio of user 7',
            'locale' => 'en_GB',
        ];

        $guard = new ProfilePostGuard(
            fieldsResolver: static fn(?object $u): array => [
                'email',
                'nickname',
                'first_name',
                'last_name',
                'display_name',
                'url',
                'description',
                'locale',
                'custom_setting',
                'unknown_field',
                '!!!@@@',
            ],
            currentUserGetter: static fn(): object => $currentUser,
        );

        $prepared = (object) [
            'ID' => 7,
            'user_email' => 'tampered@example.org',
            'email' => 'tampered2@example.org',
            'nickname' => 'TamperedNick',
            'first_name' => 'TamperedFirst',
            'last_name' => 'TamperedLast',
            'display_name' => 'TamperedDisplay',
            'user_url' => 'https://example.org/hacker',
            'url' => 'https://example.org/hacker2',
            'description' => 'Tampered bio',
            'locale' => 'de_DE',
            'custom_setting' => 'tampered_setting',
        ];

        $result = $guard->filterRestPreInsertUser($prepared, null);
        $this->assertSame($prepared, $result);
        $this->assertSame('original7@example.org', $result->user_email);
        $this->assertSame('original7@example.org', $result->email);
        $this->assertSame('NickSeven', $result->nickname);
        $this->assertSame('FirstSeven', $result->first_name);
        $this->assertSame('LastSeven', $result->last_name);
        $this->assertSame('DisplaySeven', $result->display_name);
        $this->assertSame('https://example.org/seven', $result->user_url);
        $this->assertSame('https://example.org/seven', $result->url);
        $this->assertSame('Original bio of user 7', $result->description);
        $this->assertSame('en_GB', $result->locale);
        $this->assertFalse(property_exists($result, 'custom_setting'));
    }

    public function testFilterRestPreInsertUserAliasesAndNicknameFallbacks(): void
    {
        $userWithDisplay = (object) [
            'ID' => 12,
            'nickname' => '',
            'display_name' => 'DisplayOnly',
            'user_login' => 'login_name',
            'first_name' => 'AliasedFirst',
            'last_name' => 'AliasedLast',
            'user_url' => 'https://example.org/aliased',
            'description' => 'Aliased bio text',
            'locale' => 'fr_FR',
        ];

        $guard = new ProfilePostGuard(
            fieldsResolver: static fn(?object $u): array => [
                'user_email',
                'first-name',
                'last-name',
                'display-name',
                'website',
                'bio',
                'language',
                'nickname',
            ],
            currentUserGetter: static fn(): object => $userWithDisplay,
        );

        $prepared = (object) [
            'ID' => 12,
            'nickname' => 'Hacker',
            'first_name' => 'Hacker',
            'last_name' => 'Hacker',
            'display_name' => 'Hacker',
            'user_url' => 'https://example.org/bad',
            'description' => 'Bad bio',
            'locale' => 'es_ES',
        ];

        $result = $guard->filterRestPreInsertUser($prepared, null);
        $this->assertSame('DisplayOnly', $result->nickname);
        $this->assertSame('AliasedFirst', $result->first_name);
        $this->assertSame('AliasedLast', $result->last_name);
        $this->assertSame('DisplayOnly', $result->display_name);
        $this->assertSame('https://example.org/aliased', $result->user_url);
        $this->assertSame('Aliased bio text', $result->description);
        $this->assertSame('fr_FR', $result->locale);

        $userWithLoginOnly = (object) [
            'ID' => 12,
            'nickname' => '',
            'display_name' => '',
            'user_login' => 'pure_login',
            'description' => 'Bio for about target',
        ];
        $guardLoginOnly = new ProfilePostGuard(
            fieldsResolver: static fn(?object $u): array => [
                'nickname',
                'about',
                'about_yourself',
                'about-yourself',
            ],
            currentUserGetter: static fn(): object => $userWithLoginOnly,
        );

        $preparedLogin = (object) [
            'ID' => 12,
            'nickname' => 'Hacker',
            'description' => 'Bad bio',
        ];
        $resultLogin = $guardLoginOnly->filterRestPreInsertUser($preparedLogin, null);
        $this->assertSame('pure_login', $resultLogin->nickname);
        $this->assertSame('Bio for about target', $resultLogin->description);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testGetCurrentUserFallbackAndNonObjectGetter(): void
    {
        $guardWithInvalidGetter = new ProfilePostGuard(
            currentUserGetter: static fn(): mixed => 'not_an_object'
        );
        $reflection = new \ReflectionMethod($guardWithInvalidGetter, 'getCurrentUser');
        $this->assertNull($reflection->invoke($guardWithInvalidGetter));

        ensure_admin_control_stubs();
        $backupCurrentUser = $GLOBALS['test_current_user'] ?? null;
        try {
            $GLOBALS['test_current_user'] = (object) ['ID' => 42];
            $guardDefault = new ProfilePostGuard();
            $this->assertSame(42, $reflection->invoke($guardDefault)?->ID);
        } finally {
            $GLOBALS['test_current_user'] = $backupCurrentUser;
        }
    }
}
