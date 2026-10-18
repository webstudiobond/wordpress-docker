<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use stdClass;
use WordPressDocker\AdminControl\Modules\ContentAccessControl;
use WordPressDocker\Tests\WpCoreCleanupTest;

/**
 * Validates granular content access restrictions, capability interception,
 * post query filtering, view count adjustments, and REST protections.
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
final class ContentAccessControlTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_REQUEST['post'], $_GET['post'], $_POST['post'], $_POST['post_ID']);
        parent::tearDown();
    }

    public function testGetContentAccessRulesAndTargetResolution(): void
    {
        /** @var list<AdminRule> $mockRules */
        $mockRules = [[
            'targets' => ['42', 'sample-page'],
            'isAll' => false,
            'superadmins' => false,
            'invertedSuperadmins' => false,
            'roles' => ['editor'],
            'invertedRoles' => [],
            'users' => [],
            'invertedUsers' => [],
            'action' => 'block',
        ]];

        $control = new ContentAccessControl(
            envGetter: static fn(string $name): string => '42,sample-page|block:editor',
            ruleParser: static fn(string $raw): array => $mockRules,
            postResolver: static fn(string $target): ?object => $target === 'sample-page' ? (object) ['ID' => 99] : null
        );

        $this->assertSame($mockRules, $control->getContentAccessRules());
        $this->assertSame(42, $control->resolveContentTargetToPostId('42'));
        $this->assertSame(99, $control->resolveContentTargetToPostId('sample-page'));
        $this->assertNull($control->resolveContentTargetToPostId('non-existent'));
        $this->assertNull($control->resolveContentTargetToPostId(''));
    }

    public function testGetRestrictedPostIdsAndRestrictionChecks(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];
        /** @var list<AdminRule> $rules */
        $rules = [[
            'targets' => ['101', '102'],
            'isAll' => false,
            'superadmins' => false,
            'invertedSuperadmins' => false,
            'roles' => ['editor'],
            'invertedRoles' => [],
            'users' => [],
            'invertedUsers' => [],
            'action' => 'block',
        ]];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userRestrictedChecker: static fn(array $rule, ?object $u): bool => $u === $user
        );

        $reflection = new \ReflectionProperty($control, 'cachedContentAccessRules');
        $reflection->setValue($control, $rules);

        $this->assertSame([101, 102], $control->getRestrictedPostIdsForUser($user));
        $this->assertSame([101, 102], $control->getRestrictedPostIdsForCurrentUser());
        $this->assertTrue($control->isPostRestrictedForUser(101, $user));
        $this->assertTrue($control->isPostRestrictedForCurrentUser(102));
        $this->assertFalse($control->isPostRestrictedForCurrentUser(999));
    }

    public function testEnforcePostAccessRestrictionsBlocksUnauthorizedRequests(): void
    {
        $died = false;
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            dieForbiddenHandler: static function (string $msg) use (&$died): void {
                $died = true;
            }
        );

        $reflection = new \ReflectionProperty($control, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($control, [5 => [101]]);

        $_GET['post'] = 101;
        $control->enforcePostAccessRestrictions('post.php');
        $this->assertTrue($died);

        $died = false;
        $_GET['post'] = 200;
        $control->enforcePostAccessRestrictions('post.php');
        $this->assertFalse($died);
    }

    public function testFilterMapMetaCapDeniesRestrictedPosts(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            userDataGetter: static fn(int $id): ?object => $id === 5 ? $user : null
        );

        $reflection = new \ReflectionProperty($control, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($control, [5 => [101]]);

        $this->assertSame(['do_not_allow'], $control->filterMapMetaCap(['edit_posts'], 'edit_post', 5, [101]));
        $this->assertSame(['do_not_allow'], $control->filterMapMetaCap(['delete_posts'], 'delete_post', 5, [101]));
        $this->assertSame(['edit_posts'], $control->filterMapMetaCap(['edit_posts'], 'edit_post', 5, [200]));
        $this->assertSame(['publish_posts'], $control->filterMapMetaCap(['publish_posts'], 'publish_posts', 5, []));
    }

    public function testFilterPreGetPostsExcludesRestrictedPostsAndSuperadmins(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1],
            adminChecker: static fn(): bool => true,
            pageNowGetter: static fn(): string => 'edit.php'
        );

        $reflectionRestricted = new \ReflectionProperty($control, 'cachedRestrictedPostIdsByUser');
        $reflectionRestricted->setValue($control, [5 => [101, 102]]);

        $mockQuery = new class {
            /** @var array<string, mixed> */
            public array $vars = ['post__not_in' => [50], 'author__not_in' => []];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                return null;
            }
        };

        $control->filterPreGetPosts($mockQuery);
        $this->assertSame([50, 101, 102], $mockQuery->get('post__not_in'));
    }

    public function testFilterCountPostsAdjustsNumbers(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            adminChecker: static fn(): bool => true,
            postGetter: static fn(int $id): ?object => (object) [
                'ID' => $id,
                'post_type' => 'post',
                'post_status' => 'publish',
            ]
        );

        $reflection = new \ReflectionProperty($control, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($control, [5 => [101, 102]]);

        $counts = (object) ['publish' => 10, 'draft' => 2];
        $adjusted = $control->filterCountPosts($counts, 'post', 'readable');

        $this->assertSame('8', $adjusted->publish);
        $this->assertSame(2, $adjusted->draft);
    }

    public function testFilterViewsEditPostsUpdatesCountsInBadges(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            currentScreenGetter: static fn(): object => (object) ['post_type' => 'post'],
            postGetter: static fn(int $id): ?object => (object) [
                'ID' => $id,
                'post_type' => 'post',
                'post_author' => 5,
                'post_status' => 'publish',
            ],
            userPostsCountGetter: static fn(int $u, string $p): int => 10
        );

        $reflection = new \ReflectionProperty($control, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($control, [5 => [101]]);

        $views = [
            'all' => '<a href="edit.php">All <span class="count">(10)</span></a>',
            'mine' => '<a href="edit.php?author=5">Mine <span class="count">(10)</span></a>',
        ];

        $adjusted = $control->filterViewsEditPosts($views);
        $this->assertStringContainsString('(9)', $adjusted['mine']);
    }

    public function testFilterAuthorLinkMasksSuperAdminNicename(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 5, 'user_login' => 'editor'];

        $control = new ContentAccessControl(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            superAdminIdsResolver: static fn(): array => [1],
            userDataGetter: static fn(int $id): ?object => $id === 1 ? $superAdmin : $normalUser,
            currentUserGetter: static fn(): object => $normalUser
        );

        $link = 'https://example.com/author/super';
        $expected = \function_exists('home_url') ? 'https://example.com/' : '/';
        $this->assertSame($expected, $control->filterAuthorLink($link, 1, 'super'));
        $this->assertSame(
            'https://example.com/author/editor',
            $control->filterAuthorLink('https://example.com/author/editor', 5, 'editor')
        );
    }

    public function testFilterRestPostQueryAppendsExclusions(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1]
        );

        $reflection = new \ReflectionProperty($control, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($control, [5 => [101]]);

        $filtered = $control->filterRestPostQuery(['exclude' => [20]]);
        $this->assertSame([20, 101], $filtered['exclude']);
    }

    public function testFilterRestPreparePostMasksRestrictedContent(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1]
        );

        $postObj = (object) ['ID' => 10, 'post_author' => 1];
        $mockResponse = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1];

            public bool $linkRemoved = false;

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->linkRemoved = true;
                    return null;
                }
                return null;
            }
        };

        $result = $control->filterRestPreparePost($mockResponse, $postObj, null);
        $this->assertIsObject($result);
        $this->assertSame(0, $mockResponse->data['author']);
        $this->assertTrue($mockResponse->linkRemoved);
    }

    public function testFilterRestCommentQueryAndPrepareMasksComments(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1]
        );

        $query = $control->filterRestCommentQuery(['author' => [1, 2], 'author_exclude' => [9]]);
        $this->assertSame([2], $query['author']);
        $this->assertSame([9, 1], $query['author_exclude']);

        $commentObj = (object) ['user_id' => 1];
        $mockResponse = new class {
            /** @var array<string, mixed> */
            public array $data = ['author' => 1];

            public bool $linkRemoved = false;

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'remove_link') {
                    $this->linkRemoved = true;
                    return null;
                }
                return null;
            }
        };

        $result = $control->filterRestPrepareComment($mockResponse, $commentObj, null);
        $this->assertSame(0, $mockResponse->data['author']);
        $this->assertTrue($mockResponse->linkRemoved);
    }

    public function testFilterInsertPostDataPreventsImpersonation(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $normalUser = (object) ['ID' => 5, 'user_login' => 'editor'];

        $control = new ContentAccessControl(
            superAdminChecker: static fn(?object $u): bool => $u === $superAdmin,
            currentUserGetter: static fn(): object => $normalUser,
            hiddenTargetsGetter: static fn(string $k): array => ['authordiv']
        );

        $data = ['post_author' => 1, 'post_title' => 'Test Post'];
        $postarr = ['ID' => 0];

        $filtered = $control->filterInsertPostData($data, $postarr);
        $this->assertSame('5', $filtered['post_author']);
    }

    public function testGetUserPostsCountDelegation(): void
    {
        $control = new ContentAccessControl(
            userPostsCountGetter: static fn(int $userId, string $postType): int => 42
        );

        $this->assertSame(42, $control->getUserPostsCount(5, 'post'));

        $defaultControl = new ContentAccessControl();
        $this->assertSame(0, $defaultControl->getUserPostsCount(5, 'post'));
    }

    public function testGetContentAccessRulesEmptyBranches(): void
    {
        $emptyControl = new ContentAccessControl(
            envGetter: static fn(string $name): false => false
        );
        $this->assertSame([], $emptyControl->getContentAccessRules());

        $nullParserControl = new ContentAccessControl(
            envGetter: static fn(string $name): string => '42|block:editor',
            ruleParser: null
        );
        $this->assertSame([], $nullParserControl->getContentAccessRules());
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testResolveContentTargetIdPrefixAndSlugResolution(): void
    {
        ensure_admin_control_stubs();

        $pageObj = (object) ['ID' => 77];
        $GLOBALS['test_page_by_path']['sample-page'] = $pageObj;
        $GLOBALS['test_get_posts'] = [(object) ['ID' => 88]];

        $control = new ContentAccessControl();

        $this->assertSame(42, $control->resolveContentTargetToPostId('id:42'));
        $this->assertSame(42, $control->resolveContentTargetToPostId('id:42'));
        $this->assertNull($control->resolveContentTargetToPostId('id:abc'));

        $resolverNumeric = new ContentAccessControl(
            postResolver: static fn(string $target): int => 55
        );
        $this->assertSame(55, $resolverNumeric->resolveContentTargetToPostId('target'));

        $resolverObjInvalid = new ContentAccessControl(
            postResolver: static fn(string $target): object => (object) ['ID' => 'invalid']
        );
        $this->assertNull($resolverObjInvalid->resolveContentTargetToPostId('target'));

        $slugControl = new ContentAccessControl();
        $this->assertSame(88, $slugControl->resolveContentTargetToPostId('slug:custom-slug'));
        $this->assertSame(88, $slugControl->resolveContentTargetToPostId('post:custom-slug'));

        $GLOBALS['test_get_posts'] = [];
        $pageByPathControl = new ContentAccessControl();
        $this->assertSame(77, $pageByPathControl->resolveContentTargetToPostId('sample-page'));
    }

    public function testSuperAdminBypassesContentRestrictions(): void
    {
        $superAdmin = (object) ['ID' => 1, 'roles' => ['administrator']];
        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $superAdmin,
            superAdminChecker: static fn(?object $u): bool => true
        );

        $this->assertSame([], $control->getRestrictedPostIdsForCurrentUser());
        $this->assertSame([], $control->getRestrictedPostIdsForUser($superAdmin));
        $this->assertFalse($control->isPostRestrictedForCurrentUser(10));
        $this->assertFalse($control->isPostRestrictedForUser(10, $superAdmin));

        $_GET['post'] = 10;
        $control->enforcePostAccessRestrictions('post.php');

        $this->assertSame(['edit_posts'], $control->filterMapMetaCap(['edit_posts'], 'edit_post', 1, [10]));
        $this->assertSame('https://example.com/author/super', $control->filterAuthorLink(
            'https://example.com/author/super',
            1,
            'super'
        ));
        $this->assertSame(['author' => 1], $control->filterRestPostQuery(['author' => 1]));
        $mockResp = (object) ['data' => []];
        $this->assertSame($mockResp, $control->filterRestPreparePost($mockResp, null, null));
        $this->assertSame(['author' => 1], $control->filterRestCommentQuery(['author' => 1]));
        $this->assertSame($mockResp, $control->filterRestPrepareComment($mockResp, null, null));
        $this->assertSame(['post_title' => 'Test'], $control->filterInsertPostData(['post_title' => 'Test'], []));
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testDieForbiddenFallbackToWpDie(): void
    {
        ensure_admin_control_stubs();

        $GLOBALS['test_wp_die_calls'] = [];
        $control = new ContentAccessControl();
        $control->dieForbidden('Access forbidden test message.');

        $this->assertNotEmpty($GLOBALS['test_wp_die_calls'] ?? []);
        $this->assertSame('Access forbidden test message.', $GLOBALS['test_wp_die_calls'][0]['message'] ?? '');
    }

    public function testShouldHideSuperadminPostsOptionParsing(): void
    {
        $trueControl = new ContentAccessControl(
            envGetter: static fn(string $n): string => 'true'
        );
        $this->assertTrue($trueControl->shouldHideSuperadminPosts());

        $falseControl = new ContentAccessControl(
            envGetter: static fn(string $n): false => false
        );
        $this->assertFalse($falseControl->shouldHideSuperadminPosts());
    }

    public function testFilterMapMetaCapEdgeCases(): void
    {
        $control = new ContentAccessControl();
        $this->assertSame(['cap'], $control->filterMapMetaCap(['cap'], 123, 1, [10]));
        $this->assertSame(['cap'], $control->filterMapMetaCap(['cap'], '', 1, [10]));
        $this->assertSame(['cap'], $control->filterMapMetaCap(['cap'], 'unrelated_cap', 1, [10]));
        $this->assertSame(['cap'], $control->filterMapMetaCap(['cap'], 'edit_post', 1, [0]));
        $this->assertSame(['cap'], $control->filterMapMetaCap(['cap'], 'edit_post', 1, []));

        $nullUserControl = new ContentAccessControl(
            userDataGetter: static fn(int $id): ?object => null
        );
        $this->assertSame(['cap'], $nullUserControl->filterMapMetaCap(['cap'], 'edit_post', 1, [10]));
    }

    public function testFilterPreGetPostsFrontendAuthorBranches(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
        $mockQuery = new class {
            /** @var array<string, mixed> */
            public array $vars = ['author' => 1, 'author_name' => '', 'author__not_in' => [9]];

            public bool $is404 = false;

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }

            /**
             * @param list<mixed> $arguments
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

        $frontendControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1],
            adminChecker: static fn(): bool => false
        );

        $frontendControl->filterPreGetPosts($mockQuery);
        $this->assertTrue($mockQuery->is404);

        $mockQuerySlug = new class {
            /** @var array<string, mixed> */
            public array $vars = ['author' => 0, 'author_name' => 'super'];

            public bool $is404 = false;

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }

            /**
             * @param list<mixed> $arguments
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

        $slugControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1],
            userByGetter: static fn(string $f, string|int $v): ?object => $v === 'super' ? $superAdmin : null,
            adminChecker: static fn(): bool => false
        );
        $slugControl->filterPreGetPosts($mockQuerySlug);
        $this->assertTrue($mockQuerySlug->is404);

        $mockQueryHide = new class {
            /** @var array<string, mixed> */
            public array $vars = ['author' => 0, 'author_name' => '', 'author__not_in' => [8]];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }
        };

        $hideControl = new ContentAccessControl(
            envGetter: static fn(string $n): string => 'true',
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            superAdminChecker: static fn(?object $u): bool => false,
            superAdminIdsResolver: static fn(): array => [1],
            adminChecker: static fn(): bool => false
        );
        $hideControl->filterPreGetPosts($mockQueryHide);
        $this->assertSame([8, 1], $mockQueryHide->get('author__not_in'));
    }

    public function testFilterPreGetPostsAdminEarlyReturns(): void
    {
        $mockQueryNotMain = new class {
            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return false;
                }
                return null;
            }
        };

        $control = new ContentAccessControl(
            adminChecker: static fn(): bool => true
        );
        $control->filterPreGetPosts($mockQueryNotMain);

        $mockQueryOtherPage = new class {
            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                return null;
            }
        };
        $controlOtherPage = new ContentAccessControl(
            adminChecker: static fn(): bool => true,
            pageNowGetter: static fn(): string => 'index.php'
        );
        $controlOtherPage->filterPreGetPosts($mockQueryOtherPage);

        $mockQueryNoRestricted = new class {
            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                return null;
            }
        };
        $controlNoRestricted = new ContentAccessControl(
            adminChecker: static fn(): bool => true,
            pageNowGetter: static fn(): string => 'edit.php',
            currentUserGetter: static fn(): object => (object) ['ID' => 5]
        );
        $controlNoRestricted->filterPreGetPosts($mockQueryNoRestricted);
        $this->assertTrue(true);
    }

    public function testFilterCountPostsEarlyReturnsAndMissingPosts(): void
    {
        $counts = (object) ['publish' => 5];
        $superAdminControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 1],
            superAdminChecker: static fn(?object $u): bool => true
        );
        $this->assertSame($counts, $superAdminControl->filterCountPosts($counts, 'post', 'readable'));

        $frontendControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            adminChecker: static fn(): bool => false
        );
        $this->assertSame($counts, $frontendControl->filterCountPosts($counts, 'post', 'readable'));

        $emptyRestrictedControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            adminChecker: static fn(): bool => true
        );
        $this->assertSame($counts, $emptyRestrictedControl->filterCountPosts($counts, 'post', 'readable'));

        $missingPostControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            adminChecker: static fn(): bool => true,
            postGetter: static fn(int $id): ?object => null
        );
        $reflection = new \ReflectionProperty($missingPostControl, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($missingPostControl, [5 => [101]]);
        $adjusted = $missingPostControl->filterCountPosts($counts, 'post', 'readable');
        $this->assertSame('4', $adjusted->publish);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testFilterViewsEditPostsEarlyReturnsAndInsertion(): void
    {
        ensure_admin_control_stubs();

        $views = ['all' => '<a href="edit.php">All <span class="count">(10)</span></a>'];

        $nullUserControl = new ContentAccessControl(
            currentUserGetter: static fn(): ?object => null
        );
        $this->assertSame($views, $nullUserControl->filterViewsEditPosts($views));

        $invalidUserControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 0]
        );
        $this->assertSame($views, $invalidUserControl->filterViewsEditPosts($views));

        $emptyRestrictedControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5]
        );
        $this->assertSame($views, $emptyRestrictedControl->filterViewsEditPosts($views));

        $user = (object) ['ID' => 5];
        $noRestrictedMineControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            currentScreenGetter: static fn(): ?object => null,
            postGetter: static fn(int $id): ?object => null
        );
        $reflection = new \ReflectionProperty($noRestrictedMineControl, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($noRestrictedMineControl, [5 => [101]]);
        $this->assertSame($views, $noRestrictedMineControl->filterViewsEditPosts($views));

        $insertControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            currentScreenGetter: static fn(): object => (object) ['post_type' => 'post'],
            postGetter: static fn(int $id): object => (object) [
                'ID' => $id,
                'post_type' => 'post',
                'post_author' => 5,
                'post_status' => 'publish',
            ],
            userPostsCountGetter: static fn(int $u, string $p): int => 10
        );
        $reflectionInsert = new \ReflectionProperty($insertControl, 'cachedRestrictedPostIdsByUser');
        $reflectionInsert->setValue($insertControl, [5 => [101]]);

        $_GET['author'] = 5;
        $_GET['post_status'] = 'trash';
        $adjustedViews = $insertControl->filterViewsEditPosts($views);
        $this->assertArrayHasKey('mine', $adjustedViews);
        $this->assertStringContainsString('class="current"', $adjustedViews['mine']);
        $this->assertStringContainsString('post_status=trash', $adjustedViews['mine']);

        $viewsNoAll = ['other' => '<a href="edit.php">Other</a>'];
        $adjustedNoAll = $insertControl->filterViewsEditPosts($viewsNoAll);
        $this->assertArrayHasKey('mine', $adjustedNoAll);

        $viewsUnsetMine = [
            'all' => '<a href="edit.php">All <span class="count">(5)</span></a>',
            'mine' => '<a href="edit.php?author=5">Mine <span class="count">(1)</span></a>',
        ];
        $adjustedUnset = $insertControl->filterViewsEditPosts($viewsUnsetMine);
        $this->assertArrayNotHasKey('mine', $adjustedUnset);
    }

    public function testFilterRestPostAndCommentQueryAuthorArrayAndNumeric(): void
    {
        $user = (object) ['ID' => 5];
        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1]
        );

        $queryNumeric = $control->filterRestPostQuery(['author' => 1]);
        $this->assertSame([0], $queryNumeric['author']);

        $queryAllSuper = $control->filterRestPostQuery(['author' => [1]]);
        $this->assertSame([0], $queryAllSuper['author']);

        $commentNumeric = $control->filterRestCommentQuery(['author' => 1]);
        $this->assertSame([0], $commentNumeric['author']);

        $commentAllSuper = $control->filterRestCommentQuery(['author' => [1]]);
        $this->assertSame([0], $commentAllSuper['author']);
    }

    public function testFilterRestPreparePostAndCommentNonObjectAndDataAuthor(): void
    {
        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1]
        );

        $this->assertSame('string_resp', $control->filterRestPreparePost('string_resp', null, null));
        $this->assertSame('string_resp', $control->filterRestPrepareComment('string_resp', null, null));

        $mockPostResp = (object) ['data' => ['author' => 1]];
        $control->filterRestPreparePost($mockPostResp, null, null);
        $this->assertSame(0, $mockPostResp->data['author']);

        $mockCommentResp = (object) ['data' => ['author' => 1]];
        $control->filterRestPrepareComment($mockCommentResp, null, null);
        $this->assertSame(0, $mockCommentResp->data['author']);
    }

    public function testFilterInsertPostDataUpdatePreservesExistingAuthor(): void
    {
        $user = (object) ['ID' => 5];
        $existingPost = (object) ['ID' => 20, 'post_author' => 9];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => false,
            hiddenTargetsGetter: static fn(string $k): array => ['author'],
            postGetter: static fn(int $id): ?object => $id === 20 ? $existingPost : null
        );

        $data = ['post_author' => 1];
        $postarr = ['ID' => 20];
        $filtered = $control->filterInsertPostData($data, $postarr, [], true);
        $this->assertSame('9', $filtered['post_author']);

        $nullUserControl = new ContentAccessControl(
            currentUserGetter: static fn(): ?object => null
        );
        $this->assertSame($data, $nullUserControl->filterInsertPostData($data, $postarr));

        $emptyTargetsControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            hiddenTargetsGetter: static fn(string $k): array => []
        );
        $this->assertSame($data, $emptyTargetsControl->filterInsertPostData($data, $postarr));

        $unrelatedTargetsControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            hiddenTargetsGetter: static fn(string $k): array => ['other_box']
        );
        $this->assertSame($data, $unrelatedTargetsControl->filterInsertPostData($data, $postarr));
    }

    public function testGetUserPostsCountWithGlobalWpdb(): void
    {
        $backupWpdb = $GLOBALS['wpdb'] ?? null;

        try {
            $GLOBALS['wpdb'] = new class {
                public string $posts = 'wp_posts';

                /**
                 * @param list<mixed> $arguments
                 */
                public function __call(string $name, array $arguments): mixed
                {
                    if ($name === 'prepare') {
                        return 'SELECT COUNT(1) FROM wp_posts';
                    }
                    if ($name === 'get_var') {
                        return '7';
                    }
                    return null;
                }
            };

            $control = new ContentAccessControl();
            $this->assertSame(7, $control->getUserPostsCount(5, 'post'));
        } finally {
            $GLOBALS['wpdb'] = $backupWpdb;
        }
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testGlobalFallbacksForContentAccessControl(): void
    {
        ensure_admin_control_stubs();

        $GLOBALS['test_current_user'] = (object) ['ID' => 10, 'roles' => ['editor']];
        $GLOBALS['test_userdata'][10] = (object) ['ID' => 10, 'roles' => ['editor']];
        $GLOBALS['test_user_by']['slug']['editor'] = (object) ['ID' => 10, 'roles' => ['editor']];
        $GLOBALS['test_posts'][101] = (object) ['ID' => 101, 'post_type' => 'post', 'post_status' => 'publish'];
        $GLOBALS['test_current_screen'] = (object) ['id' => 'edit-post', 'post_type' => 'post'];
        $GLOBALS['pagenow'] = 'edit.php';
        $GLOBALS['test_is_admin'] = false;

        $control = new ContentAccessControl();
        $this->assertSame([], $control->getRestrictedPostIdsForCurrentUser());

        $mockQuerySlug = new class {
            /** @var array<string, mixed> */
            public array $vars = ['author' => 0, 'author_name' => 'editor'];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }
        };
        $control->filterPreGetPosts($mockQuerySlug);

        $GLOBALS['test_is_admin'] = true;
        $counts = (object) ['publish' => 5];
        $reflection = new \ReflectionProperty($control, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($control, [10 => [101]]);
        $this->assertSame('4', $control->filterCountPosts($counts, 'post', 'readable')->publish);

        $GLOBALS['test_userdata'][999] = null;
        $this->assertSame(['edit_posts'], $control->filterMapMetaCap(['edit_posts'], 'edit_post', 999, [101]));

        $GLOBALS['test_user_by']['slug']['unknown'] = null;
        $mockQueryUnknown = new class {
            /** @var array<string, mixed> */
            public array $vars = ['author' => 0, 'author_name' => 'unknown'];

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }
        };
        $control->filterPreGetPosts($mockQueryUnknown);

        $GLOBALS['test_posts'][999] = null;
        $reflection->setValue($control, [10 => [999]]);
        $this->assertSame('3', $control->filterCountPosts($counts, 'post', 'readable')->publish);

        $GLOBALS['test_current_screen'] = null;
        $this->assertSame([], $control->filterViewsEditPosts([]));
    }

    public function testRemainingContentAccessEdgeCases(): void
    {
        $controlCheckerFalse = new ContentAccessControl(
            userRestrictedChecker: static fn(array $r, ?object $u): bool => false
        );
        $reflectionRules = new \ReflectionProperty($controlCheckerFalse, 'cachedContentAccessRules');
        $reflectionRules->setValue($controlCheckerFalse, [['targets' => ['101']]]);
        $this->assertSame([], $controlCheckerFalse->getRestrictedPostIdsForUser((object) ['ID' => 5]));

        $superAdmin = (object) ['ID' => 1];
        $superControl = new ContentAccessControl(
            currentUserGetter: static fn(): object => $superAdmin,
            superAdminChecker: static fn(?object $u): bool => true
        );
        $mockQueryEmpty = new class {
            public function get(string $key): mixed
            {
                return null;
            }

            public function set(string $key, mixed $val): void
            {
            }
        };
        $superControl->filterPreGetPosts($mockQueryEmpty);

        $controlTypeMismatch = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            adminChecker: static fn(): bool => true,
            postGetter: static fn(int $id): ?object => (object) [
                'ID' => $id,
                'post_type' => 'page',
                'post_status' => 'publish',
            ]
        );
        $reflection = new \ReflectionProperty($controlTypeMismatch, 'cachedRestrictedPostIdsByUser');
        $reflection->setValue($controlTypeMismatch, [5 => [101]]);
        $countsMismatch = (object) ['publish' => 10];
        $this->assertSame('10', (string) $controlTypeMismatch->filterCountPosts(
            $countsMismatch,
            'post',
            'readable'
        )->publish);

        $controlRest = new ContentAccessControl(
            envGetter: static fn(string $name): string => 'true',
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            superAdminIdsResolver: static fn(): array => [1]
        );
        $restQuery = $controlRest->filterRestPostQuery([
            'author' => [1, 2],
            'author_exclude' => [5],
        ]);
        $this->assertSame([2], $restQuery['author']);
        $this->assertSame([5, 1], $restQuery['author_exclude']);

        $controlNoSuper = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            superAdminIdsResolver: null
        );
        $this->assertSame(['author' => 2], $controlNoSuper->filterRestCommentQuery(['author' => 2]));

        $backupWpdb = $GLOBALS['wpdb'] ?? null;
        try {
            $GLOBALS['wpdb'] = (object) ['posts' => 'wp_posts'];
            $controlNoMethods = new ContentAccessControl();
            $this->assertSame(0, $controlNoMethods->getUserPostsCount(5, 'post'));
            $reflectionUserBy = new \ReflectionMethod($controlNoMethods, 'getUserBy');
            $this->assertNull($reflectionUserBy->invoke($controlNoMethods, 'login', 'nonexistent'));
        } finally {
            $GLOBALS['wpdb'] = $backupWpdb;
        }

        $controlNullHidden = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            hiddenTargetsGetter: null
        );
        $this->assertSame(
            ['post_author' => 1],
            $controlNullHidden->filterInsertPostData(['post_author' => 1], [])
        );
    }

    public function testIsPostRestrictedForUserFallbackMatchingAndInvalidIds(): void
    {
        $user = (object) ['ID' => 5, 'roles' => ['editor']];
        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => ['author-only'],
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
                'targets' => ['id:42', '99', 'slug:my-post', 'page:about', 'sample'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['editor'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'block',
            ],
        ];

        $posts = [
            42 => (object) ['ID' => 42, 'post_name' => 'post-42', 'post_type' => 'post'],
            99 => (object) ['ID' => 99, 'post_name' => 'post-99', 'post_type' => 'post'],
            100 => (object) ['ID' => 100, 'post_name' => 'my-post', 'post_type' => 'post'],
            101 => (object) ['ID' => 101, 'post_name' => 'about', 'post_type' => 'page'],
            102 => (object) ['ID' => 102, 'post_name' => 'sample', 'post_type' => 'post'],
            103 => (object) ['ID' => 103, 'post_name' => 'other', 'post_type' => 'post'],
        ];

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userRestrictedChecker: static fn(array $rule, ?object $u): bool => \in_array(
                'editor',
                $rule['roles'],
                true
            ),
            postGetter: static fn(int $id): ?object => $posts[$id] ?? null
        );

        $reflection = new \ReflectionProperty($control, 'cachedContentAccessRules');
        $reflection->setValue($control, $rules);

        $this->assertFalse($control->isPostRestrictedForUser(0, $user));
        $this->assertFalse($control->isPostRestrictedForUser(-5, $user));
        $this->assertTrue($control->isPostRestrictedForUser(42, $user));
        $this->assertTrue($control->isPostRestrictedForUser(99, $user));
        $this->assertTrue($control->isPostRestrictedForUser(100, $user));
        $this->assertTrue($control->isPostRestrictedForUser(101, $user));
        $this->assertTrue($control->isPostRestrictedForUser(102, $user));
        $this->assertFalse($control->isPostRestrictedForUser(103, $user));
        $this->assertFalse($control->isPostRestrictedForUser(999, $user));
    }

    public function testGettersReturnNullSafelyWhenExternalFunctionsReturnFalse(): void
    {
        $control = new ContentAccessControl(
            currentUserGetter: static fn(): mixed => false,
            userDataGetter: static fn(int $id): mixed => false,
            userByGetter: static fn(string $f, string|int $v): mixed => false,
            postGetter: static fn(int $id): mixed => false
        );

        $this->assertFalse($control->isPostRestrictedForCurrentUser(1));

        $reflectionUserData = new \ReflectionMethod($control, 'getUserData');
        $this->assertNull($reflectionUserData->invoke($control, 999999));

        $reflectionUserBy = new \ReflectionMethod($control, 'getUserBy');
        $this->assertNull($reflectionUserBy->invoke($control, 'login', 'nonexistent'));

        $reflectionPost = new \ReflectionMethod($control, 'getPost');
        $this->assertNull($reflectionPost->invoke($control, 0));
        $this->assertNull($reflectionPost->invoke($control, -1));
        $this->assertNull($reflectionPost->invoke($control, 999999));
    }

    public function testFilterRestPostQueryPostInAndIncludeSanitization(): void
    {
        $user = (object) ['ID' => 5];
        $rules = [
            [
                'targets' => ['42', '99'],
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

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => $rules,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true
        );

        $reflection = new \ReflectionProperty($control, 'cachedContentAccessRules');
        $reflection->setValue($control, $rules);

        $res1 = $control->filterRestPostQuery(['include' => [42, 100]]);
        $this->assertSame([100], $res1['include']);

        $res2 = $control->filterRestPostQuery(['include' => [42, 99]]);
        $this->assertSame([0], $res2['include']);

        $res3 = $control->filterRestPostQuery(['include' => '42,100']);
        $this->assertSame([100], $res3['include']);

        $res4 = $control->filterRestPostQuery(['post__in' => 42]);
        $this->assertSame([0], $res4['post__in']);

        $res5 = $control->filterRestPostQuery(['post__not_in' => [10]]);
        $this->assertSame([10, 42, 99], $res5['post__not_in']);
        $this->assertSame([10, 42, 99], $res5['exclude']);
    }

    public function testFilterRestPostQueryAuthorListsAndAuthorIn(): void
    {
        $user = (object) ['ID' => 5];
        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => $user,
            superAdminChecker: static fn(?object $u): bool => false,
            superAdminIdsResolver: static fn(): array => [1]
        );

        $res1 = $control->filterRestPostQuery(['author' => '2,1']);
        $this->assertSame([2], $res1['author']);

        $res2 = $control->filterRestPostQuery(['author' => '1']);
        $this->assertSame([0], $res2['author']);

        $res3 = $control->filterRestPostQuery(['author__in' => [1, 3]]);
        $this->assertSame([3], $res3['author__in']);

        $res4 = $control->filterRestPostQuery(['author__in' => [1]]);
        $this->assertSame([0], $res4['author__in']);

        $res5 = $control->filterRestPostQuery(['author__in' => ['test']]);
        $this->assertSame(['test'], $res5['author__in']);
    }

    public function testFilterPreGetPostsCommaSeparatedAuthorAndAuthorInFrontend(): void
    {
        $mockQuery = new class {
            /** @var array<string, mixed> */
            public array $vars = [];

            public bool $is404 = false;

            public function get(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function set(string $key, mixed $val): void
            {
                $this->vars[$key] = $val;
            }

            /**
             * @param list<mixed> $arguments
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

        $control = new ContentAccessControl(
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            superAdminChecker: static fn(?object $u): bool => false,
            superAdminIdsResolver: static fn(): array => [1],
            adminChecker: static fn(): bool => false
        );

        $mockQuery->vars = ['author' => '2,1'];
        $mockQuery->is404 = false;
        $control->filterPreGetPosts($mockQuery);
        $this->assertFalse($mockQuery->is404);
        $this->assertSame('2', $mockQuery->vars['author']);

        $mockQuery->vars = ['author' => '1'];
        $mockQuery->is404 = false;
        $control->filterPreGetPosts($mockQuery);
        $this->assertTrue($mockQuery->is404);

        $mockQuery->vars = ['author__in' => [1, 3]];
        $mockQuery->is404 = false;
        $control->filterPreGetPosts($mockQuery);
        $this->assertFalse($mockQuery->is404);
        $this->assertSame([3], $mockQuery->vars['author__in']);

        $mockQuery->vars = ['author__in' => [1]];
        $mockQuery->is404 = false;
        $control->filterPreGetPosts($mockQuery);
        $this->assertTrue($mockQuery->is404);

        $mockQuery->vars = ['author' => [1, 2, ['invalid']]];
        $mockQuery->is404 = false;
        $control->filterPreGetPosts($mockQuery);
        $this->assertFalse($mockQuery->is404);
        $this->assertSame('2', $mockQuery->vars['author']);
    }

    public function testFilterPreGetPostsAdminEditAuthorCloakingAndPostIn(): void
    {
        $superAdmin = (object) ['ID' => 1, 'user_login' => 'super'];
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

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'is_main_query') {
                    return true;
                }
                return null;
            }
        };

        $rules = [
            [
                'targets' => ['42', '99'],
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

        $control = new ContentAccessControl(
            envGetter: static fn(string $n): string => 'true',
            currentUserGetter: static fn(): object => (object) ['ID' => 5],
            ruleParser: static fn(string $raw): array => $rules,
            userRestrictedChecker: static fn(array $r, ?object $u): bool => true,
            superAdminChecker: static fn(?object $u): bool => isset($u->ID) && $u->ID === 1,
            superAdminIdsResolver: static fn(): array => [1],
            userByGetter: static fn(string $f, string|int $v): ?object => $v === 'super' ? $superAdmin : null,
            adminChecker: static fn(): bool => true,
            pageNowGetter: static fn(): string => 'edit.php'
        );

        $reflection = new \ReflectionProperty($control, 'cachedContentAccessRules');
        $reflection->setValue($control, $rules);

        $mockQuery->vars = ['author_name' => 'super'];
        $control->filterPreGetPosts($mockQuery);
        $this->assertSame([0], $mockQuery->vars['post__in']);

        $mockQuery->vars = ['author' => '1'];
        $control->filterPreGetPosts($mockQuery);
        $this->assertSame([0], $mockQuery->vars['post__in']);

        $mockQuery->vars = ['author' => '2,1'];
        $control->filterPreGetPosts($mockQuery);
        $this->assertSame('2', $mockQuery->vars['author']);

        $mockQuery->vars = ['author__in' => [1]];
        $control->filterPreGetPosts($mockQuery);
        $this->assertSame([0], $mockQuery->vars['post__in']);

        $mockQuery->vars = ['author__in' => [1, 5]];
        $control->filterPreGetPosts($mockQuery);
        $this->assertSame([5], $mockQuery->vars['author__in']);

        $this->assertSame([1], $mockQuery->vars['author__not_in'] ?? []);

        $mockQuery->vars = ['post__in' => [42, 100]];
        $control->filterPreGetPosts($mockQuery);
        $this->assertSame([100], $mockQuery->vars['post__in']);

        $mockQuery->vars = ['post__in' => [42, 99]];
        $control->filterPreGetPosts($mockQuery);
        $this->assertSame([0], $mockQuery->vars['post__in']);
    }
}
