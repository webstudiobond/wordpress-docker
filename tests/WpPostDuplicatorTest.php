<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use stdClass;
use WpPostDuplicator;

final class WpPostDuplicatorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WP_DUPLICATOR_POST_TYPES');
        putenv('WP_DUPLICATOR_STATUS');
        putenv('WP_DUPLICATOR_KEEP_AUTHOR');
        putenv('WP_DUPLICATOR_CAPABILITY');
        unset(
            $_GET['post'],
            $_POST['post'],
            $GLOBALS['test_user_caps'],
            $GLOBALS['test_valid_nonce'],
            $GLOBALS['test_posts'],
            $GLOBALS['test_inserted_posts'],
            $GLOBALS['test_next_insert_id'],
            $GLOBALS['test_current_user_id'],
            $GLOBALS['test_object_taxonomies'],
            $GLOBALS['test_object_terms'],
            $GLOBALS['test_set_terms'],
            $GLOBALS['test_post_meta'],
            $GLOBALS['test_added_post_meta'],
            $GLOBALS['test_redirect_location'],
            $GLOBALS['test_wp_die_calls']
        );
        parent::tearDown();
    }

    public function testDefaultConfigurationAndFallbacksWhenWordPressFunctionsUnavailable(): void
    {
        $duplicator = new WpPostDuplicator();

        $this->assertSame(['post', 'page'], $duplicator->getAllowedPostTypes());
        $this->assertTrue($duplicator->isPostTypeAllowed('post'));
        $this->assertTrue($duplicator->isPostTypeAllowed('page'));
        $this->assertFalse($duplicator->isPostTypeAllowed('portfolio'));
        $this->assertFalse($duplicator->isPostTypeAllowed('attachment'));
        $this->assertFalse($duplicator->isPostTypeAllowed(''));
        $this->assertSame('draft', $duplicator->getDuplicateStatus());
        $this->assertTrue($duplicator->shouldKeepAuthor());
        $this->assertSame('edit_posts', $duplicator->getRequiredCapability());

        $post = (object) ['ID' => 15, 'post_type' => 'post'];
        $this->assertSame([], $duplicator->addDuplicateRowAction([], $post));
        $this->assertSame(0, $duplicator->duplicatePost($post));
        $this->assertSame(0, $duplicator->handleDuplicateAction(15));
    }

    #[Depends('testDefaultConfigurationAndFallbacksWhenWordPressFunctionsUnavailable')]
    public function testFallbacksWithPartialInjectedCallbacksBeforeStubsLoaded(): void
    {
        $duplicator = new WpPostDuplicator(
            envGetter: static fn(string $name): string => $name === 'WP_DUPLICATOR_KEEP_AUTHOR' ? 'false' : '',
            capabilityChecker: static fn(): bool => true,
            nonceVerifier: static fn(): bool => true,
            postGetter: static fn(int $id): object => (object) ['ID' => $id, 'post_type' => 'post'],
            postInserter: static fn(): int => 77
        );

        $post = (object) ['ID' => 15, 'post_type' => 'post', 'post_author' => 5];
        $actions = $duplicator->addDuplicateRowAction([], $post);
        $this->assertArrayHasKey('duplicate', $actions);
        $this->assertStringContainsString('admin.php?action=wp_duplicate_post&amp;post=15', $actions['duplicate']);

        $this->assertSame(77, $duplicator->handleDuplicateAction(15));

        $failInsertDuplicator = new WpPostDuplicator(
            capabilityChecker: static fn(): bool => true,
            nonceVerifier: static fn(): bool => true
        );
        $this->assertSame(0, $failInsertDuplicator->handleDuplicateAction(15));
    }

    #[Depends('testFallbacksWithPartialInjectedCallbacksBeforeStubsLoaded')]
    public function testGlobalWordPressFunctionFallbacks(): void
    {
        ensure_duplicator_stubs();

        $GLOBALS['test_user_caps'] = [
            'edit_posts' => true,
            'edit_post:25' => true,
        ];
        $GLOBALS['test_valid_nonce'] = [
            'wp_duplicate_post_25' => true,
        ];
        $GLOBALS['test_current_user_id'] = 9;
        $GLOBALS['test_next_insert_id'] = 125;

        $sourcePost = (object) [
            'ID' => 25,
            'post_type' => 'post',
            'post_author' => 0,
            'post_title' => 'Math Formula \\frac{1}{2}',
            'post_content' => 'Content with \\backslash',
            'post_excerpt' => 'Excerpt',
            'comment_status' => 'open',
            'ping_status' => 'closed',
            'post_parent' => 0,
            'post_password' => '',
            'to_ping' => '',
            'menu_order' => 3,
        ];
        $GLOBALS['test_posts'] = [25 => $sourcePost];
        $GLOBALS['test_object_taxonomies'] = [
            'post' => ['category', '', 123, 'post_translations', 'post_tag'],
        ];
        $GLOBALS['test_object_terms'] = [
            25 => [
                'category' => ['news', 'updates'],
                'post_translations' => ['pll_65a1b2c3'],
                'post_tag' => 'not_an_array_error',
            ],
        ];
        $GLOBALS['test_post_meta'] = [
            25 => [
                '_edit_lock' => ['12345:1'],
                '_edit_last' => ['1'],
                '_wp_old_slug' => ['old-post-slug'],
                '_wp_old_date' => ['2025-01-01'],
                '' => ['ignored'],
                'custom_key' => ['plain_val', serialize(['nested' => 'val\\1'])],
                'invalid_not_array' => 'scalar',
            ],
        ];

        $duplicator = new WpPostDuplicator();

        $actions = $duplicator->addDuplicateRowAction([], $sourcePost);
        $this->assertArrayHasKey('duplicate', $actions);
        $this->assertStringContainsString(
            'https://example.com/wp-admin/admin.php?action=wp_duplicate_post'
            . '&amp;post=25&amp;_wpnonce=nonce_wp_duplicate_post_25',
            $actions['duplicate']
        );

        $_GET['post'] = '25';
        $newId = $duplicator->handleDuplicateAction();
        $this->assertSame(125, $newId);
        $this->assertSame(
            'https://example.com/wp-admin/post.php?action=edit&post=125',
            $GLOBALS['test_redirect_location'] ?? null
        );

        $this->assertCount(1, $GLOBALS['test_inserted_posts']);
        $inserted = $GLOBALS['test_inserted_posts'][0];
        $this->assertSame(9, $inserted['post_author']);
        $this->assertSame('Math Formula \\\\frac{1}{2}', $inserted['post_title']);

        $this->assertCount(1, $GLOBALS['test_set_terms']);
        $this->assertSame('category', $GLOBALS['test_set_terms'][0]['taxonomy']);
        $this->assertSame(['news', 'updates'], $GLOBALS['test_set_terms'][0]['terms']);

        $this->assertCount(2, $GLOBALS['test_added_post_meta']);
        $this->assertSame('custom_key', $GLOBALS['test_added_post_meta'][0]['meta_key']);
        $this->assertSame('plain_val', $GLOBALS['test_added_post_meta'][0]['meta_value']);
        $this->assertSame(['nested' => 'val\\\\1'], $GLOBALS['test_added_post_meta'][1]['meta_value']);

        $GLOBALS['test_object_taxonomies']['post'] = 'not_an_array';
        $GLOBALS['test_post_meta'][25] = 'not_an_array';
        $this->assertSame(125, $duplicator->duplicatePost($sourcePost));

        unset($_GET['post']);
        $this->assertSame(0, $duplicator->handleDuplicateAction());
        $this->assertNotEmpty($GLOBALS['test_wp_die_calls']);
    }

    public function testAllowedPostTypesAndStatusAndAuthorEnvConfigurations(): void
    {
        $customTypesDuplicator = new WpPostDuplicator(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_DUPLICATOR_POST_TYPES' => ' post , bbb-room , , post ',
                'WP_DUPLICATOR_STATUS' => '  PENDING ',
                'WP_DUPLICATOR_KEEP_AUTHOR' => '0',
                'WP_DUPLICATOR_CAPABILITY' => ' manage_options ',
                default => '',
            }
        );

        $this->assertSame(['post', 'bbb-room'], $customTypesDuplicator->getAllowedPostTypes());
        $this->assertTrue($customTypesDuplicator->isPostTypeAllowed('bbb-room'));
        $this->assertFalse($customTypesDuplicator->isPostTypeAllowed('page'));
        $this->assertSame('pending', $customTypesDuplicator->getDuplicateStatus());
        $this->assertFalse($customTypesDuplicator->shouldKeepAuthor());
        $this->assertSame('manage_options', $customTypesDuplicator->getRequiredCapability());

        $wildcardDuplicator = new WpPostDuplicator(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_DUPLICATOR_POST_TYPES' => '*',
                'WP_DUPLICATOR_STATUS' => 'invalid_status',
                default => '',
            }
        );

        $this->assertTrue($wildcardDuplicator->isPostTypeAllowed('custom_portfolio'));
        $this->assertFalse($wildcardDuplicator->isPostTypeAllowed('revision'));
        $this->assertFalse($wildcardDuplicator->isPostTypeAllowed('acf-field-group'));
        $this->assertSame('draft', $wildcardDuplicator->getDuplicateStatus());

        $disabledDuplicator = new WpPostDuplicator(
            envGetter: static fn(string $name): string => $name === 'WP_DUPLICATOR_POST_TYPES' ? 'none' : ''
        );

        $this->assertSame([], $disabledDuplicator->getAllowedPostTypes());
        $this->assertFalse($disabledDuplicator->isPostTypeAllowed('post'));
    }

    public function testAddDuplicateRowActionValidationAndCapabilityChecks(): void
    {
        $duplicator = new WpPostDuplicator(
            capabilityChecker: static fn(string $cap, ?int $id = null): bool => $cap === 'edit_posts' && $id === null
                ? true
                : ($cap === 'edit_post' && $id === 10),
            nonceUrlGenerator: static fn(string $path, string $action): string => $path . '&nonce=' . $action
        );

        $this->assertSame(['edit' => 'Edit'], $duplicator->addDuplicateRowAction(['edit' => 'Edit'], 'not_an_object'));
        $this->assertSame(
            ['edit' => 'Edit'],
            $duplicator->addDuplicateRowAction(['edit' => 'Edit'], (object) ['ID' => 0, 'post_type' => 'post'])
        );
        $this->assertSame(
            ['edit' => 'Edit'],
            $duplicator->addDuplicateRowAction(['edit' => 'Edit'], (object) ['ID' => 10, 'post_type' => 'attachment'])
        );
        $this->assertSame(
            ['edit' => 'Edit'],
            $duplicator->addDuplicateRowAction(['edit' => 'Edit'], (object) ['ID' => 99, 'post_type' => 'post'])
        );

        $allowedPost = (object) ['ID' => 10, 'post_type' => 'post'];
        $result = $duplicator->addDuplicateRowAction(['edit' => 'Edit'], $allowedPost);
        $this->assertArrayHasKey('duplicate', $result);
        $this->assertStringContainsString('nonce=wp_duplicate_post_10', $result['duplicate']);
    }

    public function testDuplicatePostWithInjectedDependenciesAndAuthorModes(): void
    {
        /** @var array<string, mixed> $capturedInsert */
        $capturedInsert = [];
        /** @var list<array{int, int, string}> $capturedTax */
        $capturedTax = [];
        /** @var list<array{int, int}> $capturedMeta */
        $capturedMeta = [];

        $duplicator = new WpPostDuplicator(
            postInserter: static function (array $args) use (&$capturedInsert): int {
                $capturedInsert = $args;
                return 55;
            },
            taxonomyCopier: static function (int $src, int $dst, string $type) use (&$capturedTax): void {
                $capturedTax[] = [$src, $dst, $type];
            },
            metaCopier: static function (int $src, int $dst) use (&$capturedMeta): void {
                $capturedMeta[] = [$src, $dst];
            },
            currentUserIdGetter: static fn(): int => 99
        );

        $this->assertSame(0, $duplicator->duplicatePost((object) ['ID' => 0, 'post_type' => 'post']));

        $post = (object) [
            'ID' => '12',
            'post_type' => 'page',
            'post_author' => '7',
            'post_parent' => '3',
            'menu_order' => '5',
            'post_title' => 'Sample Page',
        ];

        $newId = $duplicator->duplicatePost($post);
        $this->assertSame(55, $newId);
        $this->assertSame(7, $capturedInsert['post_author']);
        $this->assertSame(3, $capturedInsert['post_parent']);
        $this->assertSame(5, $capturedInsert['menu_order']);
        $this->assertSame([12, 55, 'page'], $capturedTax[0]);
        $this->assertSame([12, 55], $capturedMeta[0]);

        $postWithoutAuthor = (object) [
            'ID' => 13,
            'post_type' => 'page',
            'post_author' => 0,
        ];
        $this->assertSame(55, $duplicator->duplicatePost($postWithoutAuthor));
        $this->assertSame(99, $capturedInsert['post_author']);

        $failingDuplicator = new WpPostDuplicator(
            postInserter: static fn(): int => 0
        );
        $this->assertSame(0, $failingDuplicator->duplicatePost($post));
    }

    public function testHandleDuplicateActionErrorPathsAndSuccessRedirect(): void
    {
        /** @var list<array{string, int}> $errors */
        $errors = [];
        $redirectedTo = '';

        $errorHandler = static function (string $msg, int $code) use (&$errors): void {
            $errors[] = [$msg, $code];
        };

        $duplicator = new WpPostDuplicator(
            capabilityChecker: static fn(string $cap, ?int $id = null): bool => $id !== 403,
            nonceVerifier: static fn(string $action): bool => $action !== 'wp_duplicate_post_401',
            postGetter: static function (int $id): ?object {
                return match ($id) {
                    404 => null,
                    405 => (object) ['ID' => 405, 'post_type' => 'revision'],
                    500 => (object) ['ID' => 500, 'post_type' => 'post'],
                    200 => (object) ['ID' => 200, 'post_type' => 'post', 'post_author' => 2],
                    default => null,
                };
            },
            postInserter: static fn(array $args): int => $args['post_author'] === 2 ? 201 : 0,
            redirector: static function (string $url) use (&$redirectedTo): void {
                $redirectedTo = $url;
            },
            errorHandler: $errorHandler
        );

        $_GET['post'] = '42 OR 1=1';
        $this->assertSame(0, $duplicator->handleDuplicateAction());
        $this->assertSame(400, $errors[0][1]);

        unset($_GET['post']);
        $_POST['post'] = -5;
        $this->assertSame(0, $duplicator->handleDuplicateAction());
        $this->assertSame(400, $errors[1][1]);

        $_POST['post'] = 401;
        $this->assertSame(0, $duplicator->handleDuplicateAction());
        $this->assertSame(403, $errors[2][1]);

        $this->assertSame(0, $duplicator->handleDuplicateAction(403));
        $this->assertSame(403, $errors[3][1]);

        $this->assertSame(0, $duplicator->handleDuplicateAction(404));
        $this->assertSame(404, $errors[4][1]);

        $this->assertSame(0, $duplicator->handleDuplicateAction(405));
        $this->assertSame(403, $errors[5][1]);

        $this->assertSame(0, $duplicator->handleDuplicateAction(500));
        $this->assertSame(500, $errors[6][1]);

        unset($_POST['post']);
        $_GET['post'] = 200;
        $this->assertSame(201, $duplicator->handleDuplicateAction());
        $this->assertStringContainsString('post.php?action=edit&post=201', $redirectedTo);
    }

    public function testRegisterHooksWithInjectedRegistrars(): void
    {
        /** @var list<array{string, callable, int, int}> $actions */
        $actions = [];
        /** @var list<array{string, callable, int, int}> $filters */
        $filters = [];

        $duplicator = new WpPostDuplicator();
        $duplicator->registerHooks(
            static function (string $tag, callable $cb, int $prio, int $args) use (&$actions): void {
                $actions[] = [$tag, $cb, $prio, $args];
            },
            static function (string $tag, callable $cb, int $prio, int $args) use (&$filters): void {
                $filters[] = [$tag, $cb, $prio, $args];
            }
        );

        $this->assertCount(1, $actions);
        $this->assertSame('admin_action_wp_duplicate_post', $actions[0][0]);

        $this->assertCount(2, $filters);
        $this->assertSame('post_row_actions', $filters[0][0]);
        $this->assertSame('page_row_actions', $filters[1][0]);
    }

    #[DependsExternal(WpPerformanceTest::class, 'testFinishFastcgiReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testOnUpgraderProcessCompleteReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(WpTelemetryBlockerTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(
        WpTranslationUpdatesDisablerTest::class,
        'testRegisterHooksReturnsEarlyWhenAddFilterDoesNotExist'
    )]
    public function testRegisterHooksWithoutRegistrarsUsesGlobalFunctions(): void
    {
        ensure_wordpress_stubs();
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];

        $duplicator = new WpPostDuplicator();
        $duplicator->registerHooks();

        $this->assertArrayHasKey('admin_action_wp_duplicate_post', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('post_row_actions', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('page_row_actions', $GLOBALS['test_registered_filters']);
        unset($GLOBALS['test_registered_actions'], $GLOBALS['test_registered_filters']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenWordPressFunctionsExist(): void
    {
        ensure_wordpress_stubs();
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];

        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-post-duplicator.php.example';

        $this->assertArrayHasKey('admin_action_wp_duplicate_post', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('post_row_actions', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('page_row_actions', $GLOBALS['test_registered_filters']);
    }
}
