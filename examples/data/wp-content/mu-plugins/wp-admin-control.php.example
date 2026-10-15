<?php

/**
 * Plugin Name: WP Admin Control
 * Description: Hardens and sanitizes WordPress administrative dashboard via role-based access control and Docker secrets.
 * Version: 1.0.0
 * Author: underhax
 */

declare(strict_types=1);

/**
 * Provides centralized administrative hardening, role-based access control,
 * zero-knowledge super administrator protection, and dashboard interface sanitization.
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
final class WpAdminControl
{
    public const string DEFAULT_SUPER_ADMIN_SECRETS_PATH = '/run/secrets/wp_super_admins';
    public const string DEFAULT_SALT_SECRETS_PATH = '/run/secrets/auth_salt';

    /**
     * @var list<string>
     */
    private const array PROTECTED_POST_CAPABILITIES = [
        'edit_post',
        'delete_post',
        'edit_page',
        'delete_page',
    ];

    /**
     * @var list<string>
     */
    private const array PROTECTED_USER_CAPABILITIES = [
        'edit_user',
        'delete_user',
        'promote_user',
        'remove_user',
        'switch_to_user',
    ];

    /**
     * @var array<string, string>
     */
    private const array PROFILE_FIELD_SELECTORS_MAP = [
        'syntax_highlighting' => 'tr.user-syntax-highlighting-wrap',
        'syntax-highlighting' => 'tr.user-syntax-highlighting-wrap',
        'color_scheme' => 'tr.user-admin-color-wrap',
        'admin_color' => 'tr.user-admin-color-wrap',
        'admin-color' => 'tr.user-admin-color-wrap',
        'shortcuts' => 'tr.user-comment-shortcuts-wrap',
        'comment_shortcuts' => 'tr.user-comment-shortcuts-wrap',
        'comment-shortcuts' => 'tr.user-comment-shortcuts-wrap',
        'toolbar' => 'tr.user-admin-bar-front-wrap',
        'admin_bar' => 'tr.user-admin-bar-front-wrap',
        'admin-bar' => 'tr.user-admin-bar-front-wrap',
        'admin_bar_front' => 'tr.user-admin-bar-front-wrap',
        'admin-bar-front' => 'tr.user-admin-bar-front-wrap',
        'infinite_scrolling' => 'tr.user-infinite-scrolling-wrap',
        'infinite-scrolling' => 'tr.user-infinite-scrolling-wrap',
        'user_login' => 'tr.user-user-login-wrap, tr.user-login-wrap, input#user_login, label[for="user_login"]',
        'user-login' => 'tr.user-user-login-wrap, tr.user-login-wrap, input#user_login, label[for="user_login"]',
        'username' => 'tr.user-user-login-wrap, tr.user-login-wrap, input#user_login, label[for="user_login"]',
        'first_name' => 'tr.user-first-name-wrap, input#first_name',
        'first-name' => 'tr.user-first-name-wrap, input#first_name',
        'last_name' => 'tr.user-last-name-wrap, input#last_name',
        'last-name' => 'tr.user-last-name-wrap, input#last_name',
        'nickname' => 'tr.user-nickname-wrap, input#nickname',
        'display_name' => 'tr.user-display-name-wrap, select#display_name',
        'display-name' => 'tr.user-display-name-wrap, select#display_name',
        'website' => 'tr.user-url-wrap, input#url, label[for="url"]',
        'url' => 'tr.user-url-wrap, input#url, label[for="url"]',
        'description' => 'tr.user-description-wrap, tr[class*="user-description-"], textarea[name^="description"], label[for^="description"]',
        'bio' => 'tr.user-description-wrap, tr[class*="user-description-"], textarea[name^="description"], label[for^="description"]',
        'profile_picture' => 'tr.user-profile-picture-wrap',
        'profile-picture' => 'tr.user-profile-picture-wrap',
        'avatar' => 'tr.user-profile-picture-wrap',
        'about' => 'tr.user-description-wrap, tr[class*="user-description-"], textarea[name^="description"], tr.user-profile-picture-wrap',
        'about_yourself' => 'tr.user-description-wrap, tr[class*="user-description-"], textarea[name^="description"], tr.user-profile-picture-wrap',
        'about-yourself' => 'tr.user-description-wrap, tr[class*="user-description-"], textarea[name^="description"], tr.user-profile-picture-wrap',
        'rich_editing' => 'tr.user-rich-editing-wrap',
        'rich-editing' => 'tr.user-rich-editing-wrap',
        'visual_editor' => 'tr.user-rich-editing-wrap',
        'visual-editor' => 'tr.user-rich-editing-wrap',
        'language' => 'tr.user-language-wrap, select#locale',
        'sessions' => 'tr.user-sessions-wrap',
        'application_passwords' => 'tr.user-application-passwords-wrap, div.application-passwords',
        'application-passwords' => 'tr.user-application-passwords-wrap, div.application-passwords',
        'elementor_ai' => 'tr.user-elementor_enable_ai-wrap, input[name*="elementor_enable_ai"]',
        'elementor-ai' => 'tr.user-elementor_enable_ai-wrap, input[name*="elementor_enable_ai"]',
        'elementor_enable_ai' => 'tr.user-elementor_enable_ai-wrap, input[name*="elementor_enable_ai"]',
        'elementor_notes' => 'tr.user-elementor_pro_enable_notes_notifications-wrap, input[name*="notes"]',
        'elementor-notes' => 'tr.user-elementor_pro_enable_notes_notifications-wrap, input[name*="notes"]',
        'elementor_pro_enable_notes_notifications' => 'tr.user-elementor_pro_enable_notes_notifications-wrap, input[name*="notes"]',
        'elementor' => 'tr[class*="elementor"], input[name*="elementor"], label[for*="elementor"]',
        'wordfence' => '#wfls-user-settings, tr#wfls-user-2fa-status, tr#wfls-user-passkey-status, tr.user-wordfence-ls-wrap, tr.user-wordfence-ls-passkey-wrap',
        'wfls' => '#wfls-user-settings, tr#wfls-user-2fa-status, tr#wfls-user-passkey-status, tr.user-wordfence-ls-wrap, tr.user-wordfence-ls-passkey-wrap',
        'wordfence_2fa' => 'tr#wfls-user-2fa-status, tr.user-wordfence-ls-wrap',
        'wordfence-2fa' => 'tr#wfls-user-2fa-status, tr.user-wordfence-ls-wrap',
        'wordfence-ls' => 'tr#wfls-user-2fa-status, tr.user-wordfence-ls-wrap',
        'wordfence_passkeys' => 'tr#wfls-user-passkey-status, tr.user-wordfence-ls-passkey-wrap',
        'wordfence-passkeys' => 'tr#wfls-user-passkey-status, tr.user-wordfence-ls-passkey-wrap',
        'wordfence-ls-passkey' => 'tr#wfls-user-passkey-status, tr.user-wordfence-ls-passkey-wrap',
        'yoast' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
        'yoast_seo' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
        'yoast-seo' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
        'wpseo' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
    ];

    /**
     * @var array<string, string>
     */
    private const array STANDARD_WIDGETS_MAP = [
        'pages' => 'WP_Widget_Pages',
        'calendar' => 'WP_Widget_Calendar',
        'archives' => 'WP_Widget_Archives',
        'links' => 'WP_Widget_Links',
        'media_audio' => 'WP_Widget_Media_Audio',
        'media_image' => 'WP_Widget_Media_Image',
        'media_video' => 'WP_Widget_Media_Video',
        'media_gallery' => 'WP_Widget_Media_Gallery',
        'meta' => 'WP_Widget_Meta',
        'search' => 'WP_Widget_Search',
        'text' => 'WP_Widget_Text',
        'categories' => 'WP_Widget_Categories',
        'recent_posts' => 'WP_Widget_Recent_Posts',
        'recent_comments' => 'WP_Widget_Recent_Comments',
        'rss' => 'WP_Widget_RSS',
        'tag_cloud' => 'WP_Widget_Tag_Cloud',
        'nav_menu' => 'WP_Nav_Menu_Widget',
        'custom_html' => 'WP_Widget_Custom_HTML',
        'block' => 'WP_Widget_Block',
    ];

    /**
     * @var array<string, string>
     */
    private const array STANDARD_GUTENBERG_BLOCK_ALIASES = [
        'loginout' => 'core/loginout',
        'login-out' => 'core/loginout',
        'query' => 'core/query',
        'query-loop' => 'core/query',
        'site-logo' => 'core/site-logo',
        'site-title' => 'core/site-title',
        'site-tagline' => 'core/site-tagline',
        'template-part' => 'core/template-part',
        'time-to-read' => 'core/post-time-to-read',
        'post-time-to-read' => 'core/post-time-to-read',
        'post-title' => 'core/post-title',
        'post-content' => 'core/post-content',
        'post-excerpt' => 'core/post-excerpt',
        'post-featured-image' => 'core/post-featured-image',
        'featured-image' => 'core/post-featured-image',
        'post-author' => 'core/post-author',
        'post-author-name' => 'core/post-author-name',
        'post-author-biography' => 'core/post-author-biography',
        'post-date' => 'core/post-date',
        'post-terms' => 'core/post-terms',
        'post-comments-count' => 'core/post-comments-count',
        'navigation' => 'core/navigation',
        'archives' => 'core/archives',
        'calendar' => 'core/calendar',
        'categories' => 'core/categories',
        'html' => 'core/html',
        'custom-html' => 'core/html',
        'latest-comments' => 'core/latest-comments',
        'latest-posts' => 'core/latest-posts',
        'page-list' => 'core/page-list',
        'rss' => 'core/rss',
        'search' => 'core/search',
        'shortcode' => 'core/shortcode',
        'social-links' => 'core/social-links',
        'social-icons' => 'core/social-links',
        'tag-cloud' => 'core/tag-cloud',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array STANDARD_GUTENBERG_CATEGORIES = [
        'theme' => [
            'core/navigation',
            'core/site-logo',
            'core/site-title',
            'core/site-tagline',
            'core/header',
            'core/footer',
            'core/template-part',
            'core/loginout',
            'core/query',
            'core/post-title',
            'core/post-content',
            'core/post-date',
            'core/post-excerpt',
            'core/post-featured-image',
            'core/post-terms',
            'core/post-author',
            'core/post-author-name',
            'core/post-author-biography',
            'core/post-comments-count',
            'core/post-time-to-read',
            'core/comments',
            'core/comments-title',
            'core/comments-pagination',
            'core/comment-template',
            'core/query-title',
            'core/query-pagination',
            'core/query-no-results',
            'core/post-navigation-link',
            'core/read-more',
            'core/avatar',
            'core/term-description',
        ],
        'widgets' => [
            'core/archives',
            'core/calendar',
            'core/categories',
            'core/html',
            'core/latest-comments',
            'core/latest-posts',
            'core/page-list',
            'core/rss',
            'core/search',
            'core/shortcode',
            'core/social-links',
            'core/tag-cloud',
        ],
        'embed' => [
            'core/embed',
        ],
    ];

    private ?string $cachedSalt = null;

    /**
     * @var list<string>|null
     */
    private ?array $cachedSuperAdminHashes = null;

    /**
     * @var array<int, bool>
     */
    private array $superAdminCheckCache = [];

    /**
     * @var list<int>|null
     */
    private ?array $resolvedSuperAdminIds = null;

    private bool $isResolvingSuperAdmins = false;
    private bool $isProfileBufferingStarted = false;

    /**
     * @var list<AdminRule>|null
     */
    private ?array $cachedSidebarMenuControlRules = null;

    /**
     * @var list<AdminRule>|null
     */
    private ?array $cachedContentAccessRules = null;

    /**
     * @var array<int|string, int|null>
     */
    private array $resolvedContentTargetIds = [];

    /**
     * @var array<int, list<int>>
     */
    private array $cachedRestrictedPostIdsByUser = [];

    /**
     * @var array<string, string>
     */
    private array $separatorAfterMap = [];

    /** @var \Closure(): never */
    private readonly \Closure $terminator;

    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(string): (string|false))|null $fileReader
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(int): (?object))|null $userDataGetter
     * @param (callable(string, string|int): (?object))|null $userByGetter
     * @param (callable(array<string, mixed>): list<object>)|null $usersGetter
     * @param (callable(string, string|int, array<string, mixed>): void)|null $dieHandler
     * @param (callable(): bool)|null $adminChecker
     * @param (callable(): bool)|null $ajaxChecker
     * @param (callable(): bool)|null $cronChecker
     * @param (callable(string, callable|string, int, int): void)|null $actionAdder
     * @param (callable(string, callable|string, int, int): void)|null $filterAdder
     * @param (callable(string): bool)|null $menuRemover
     * @param (callable(string, string): (array<int, mixed>|false))|null $submenuRemover
     * @param (callable(string, string, callable, (callable|null), (array<string, mixed>|null), string, string): void)|null $dashboardWidgetAdder
     * @param (callable(string, string|object, string): void)|null $metaBoxRemover
     * @param (callable(): (?object))|null $currentScreenGetter
     * @param (callable(string): void)|null $headerEchoer
     * @param string|null $customSuperAdminFile
     * @param string|null $customSaltFile
     * @param (callable(string, callable|string, int): void)|null $actionRemover
     * @param (callable(string): void)|null $styleDequeuer
     * @param (callable(string): void)|null $styleDeregisterer
     * @param (callable(int): (?object))|null $postGetter
     * @param (callable(string): (int|object|null))|null $postResolver
     * @param (callable(): int)|null $queriedObjectIdGetter
     * @param (callable(int, string): int)|null $userPostsCountGetter
     * @param (callable(string, string, string): bool)|null $inlineScriptAdder
     * @param (callable(string, string): void)|null $postTypeSupportRemover
     * @param (callable(string): void)|null $scriptDequeuer
     * @param (callable(string, int|false): void)|null $allActionsRemover
     * @param (callable(string): void)|null $widgetUnregisterer
     * @param (callable(): list<string>)|null $registeredBlockTypesGetter
     * @param (callable(int, string, bool): mixed)|null $userMetaGetter
     * @param (\Closure(): never)|null $terminator
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $fileReader = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $userDataGetter = null,
        private readonly mixed $userByGetter = null,
        private readonly mixed $usersGetter = null,
        private readonly mixed $dieHandler = null,
        private readonly mixed $adminChecker = null,
        private readonly mixed $ajaxChecker = null,
        private readonly mixed $cronChecker = null,
        private readonly mixed $actionAdder = null,
        private readonly mixed $filterAdder = null,
        private readonly mixed $menuRemover = null,
        private readonly mixed $submenuRemover = null,
        private readonly mixed $dashboardWidgetAdder = null,
        private readonly mixed $metaBoxRemover = null,
        private readonly mixed $currentScreenGetter = null,
        private readonly mixed $headerEchoer = null,
        private readonly ?string $customSuperAdminFile = null,
        private readonly ?string $customSaltFile = null,
        private readonly mixed $actionRemover = null,
        private readonly mixed $styleDequeuer = null,
        private readonly mixed $styleDeregisterer = null,
        private readonly mixed $postGetter = null,
        private readonly mixed $postResolver = null,
        private readonly mixed $queriedObjectIdGetter = null,
        private readonly mixed $userPostsCountGetter = null,
        private readonly mixed $inlineScriptAdder = null,
        private readonly mixed $postTypeSupportRemover = null,
        private readonly mixed $scriptDequeuer = null,
        private readonly mixed $allActionsRemover = null,
        private readonly mixed $widgetUnregisterer = null,
        private readonly mixed $registeredBlockTypesGetter = null,
        private readonly mixed $userMetaGetter = null,
        ?\Closure $terminator = null
    ) {
        $this->terminator = $terminator ?? static function (): never { exit; };
    }

    public function registerHooks(): void
    {
        $this->addAction('admin_init', [$this, 'handleAdminInit'], 1, 0);
        $this->addAction('admin_menu', [$this, 'handleAdminMenu'], 999, 0);
        $this->addAction('admin_bar_menu', [$this, 'handleAdminBarMenu'], 999, 1);
        $this->addAction('admin_bar_menu', [$this, 'handleTopbarMenuOrder'], 9999, 1);
        $this->addAction('wp_before_admin_bar_render', [$this, 'handleBeforeAdminBarRender'], 9999, 0);
        $this->addAction('wp_dashboard_setup', [$this, 'handleDashboardSetup'], 999, 0);
        $this->addAction('admin_head', [$this, 'handleAdminHead'], 999, 0);
        $this->addAction('admin_print_scripts', [$this, 'handleAdminPrintScripts'], 999, 0);
        $this->addAction('admin_enqueue_scripts', [$this, 'handleAdminEnqueueScripts'], 999, 0);
        $this->addAction('admin_print_footer_scripts', [$this, 'handleAdminPrintFooterScripts'], 999, 0);
        $this->addAction('wp_head', [$this, 'handleWpHead'], 999, 0);
        $this->addAction('current_screen', [$this, 'handleCurrentScreen'], 10, 1);
        $this->addAction('add_meta_boxes', [$this, 'handleMetaBoxesSetup'], 999, 2);
        $this->addAction('enqueue_block_editor_assets', [$this, 'handleBlockEditorAssets'], 999, 0);
        $this->addAction('rest_api_init', [$this, 'handleRestApiInit'], 999, 0);
        $this->addAction('template_redirect', [$this, 'handleTemplateRedirect'], 1, 0);
        $this->addAction('widgets_init', [$this, 'handleWidgetsInit'], 99, 0);

        if ($this->isGoogleFontsDisabled()) {
            $this->addAction('admin_init', [$this, 'handleDisableGoogleFontsInit'], 1, 0);
            $this->addAction('admin_head', [$this, 'handleDisableGoogleFontsHead'], 1, 0);
            $this->addAction('admin_print_styles', [$this, 'handleDisableGoogleFontsStyles'], 1, 0);
            $this->addAction('admin_print_styles', [$this, 'handleDisableGoogleFontsStyles'], 9999, 0);
            $this->addFilter('wp_resource_hints', [$this, 'filterResourceHints'], 999, 2);
        }

        $this->addFilter('pre_get_users', [$this, 'filterPreGetUsers'], 10, 1);
        $this->addFilter('views_users', [$this, 'filterViewsUsers'], 999, 1);
        $this->addFilter('user_row_actions', [$this, 'filterUserRowActions'], 999, 2);
        $this->addFilter('manage_users_custom_column', [$this, 'filterManageUsersCustomColumn'], 999, 3);
        $this->addFilter('rest_user_query', [$this, 'filterRestUserQuery'], 10, 2);
        $this->addFilter('rest_pre_dispatch', [$this, 'filterRestPreDispatch'], 10, 3);
        $this->addFilter('rest_post_query', [$this, 'filterRestPostQuery'], 10, 2);
        $this->addFilter('rest_prepare_post', [$this, 'filterRestPreparePost'], 10, 3);
        $this->addFilter('rest_prepare_page', [$this, 'filterRestPreparePost'], 10, 3);
        $this->addFilter('rest_comment_query', [$this, 'filterRestCommentQuery'], 10, 2);
        $this->addFilter('rest_prepare_comment', [$this, 'filterRestPrepareComment'], 10, 3);

        $this->addFilter('custom_menu_order', [$this, 'filterCustomMenuOrder'], 10, 1);
        $this->addFilter('menu_order', [$this, 'filterMenuOrder'], 10, 1);

        $this->addFilter('screen_options_show_screen', [$this, 'filterScreenOptionsShowScreen'], 10, 1);
        $this->addFilter('hidden_meta_boxes', [$this, 'filterHiddenMetaBoxes'], 10, 2);
        $this->addFilter('hidden_columns', [$this, 'filterHiddenColumns'], 10, 2);
        $this->addFilter('default_hidden_columns', [$this, 'filterDefaultHiddenColumns'], 10, 2);
        $this->addFilter('manage_posts_columns', [$this, 'filterScreenColumns'], 999, 1);
        $this->addFilter('manage_pages_columns', [$this, 'filterScreenColumns'], 999, 1);

        $this->addFilter('map_meta_cap', [$this, 'filterMapMetaCap'], 10, 4);
        $this->addFilter('pre_get_posts', [$this, 'filterPreGetPosts'], 10, 1);
        $this->addFilter('wp_count_posts', [$this, 'filterCountPosts'], 10, 3);
        $this->addFilter('views_edit-post', [$this, 'filterViewsEditPosts'], 10, 1);
        $this->addFilter('views_edit-page', [$this, 'filterViewsEditPosts'], 10, 1);
        $this->addFilter('author_link', [$this, 'filterAuthorLink'], 10, 3);
        $this->addFilter('wp_die_handler', [$this, 'filterWpDieHandler'], 999, 1);
        $this->addFilter('admin_footer_text', [$this, 'filterAdminFooterText'], 999, 1);
        $this->addFilter('update_footer', [$this, 'filterUpdateFooter'], 999, 1);
        $this->addFilter('block_editor_settings_all', [$this, 'filterBlockEditorSettingsAll'], 999, 2);
        $this->addFilter('wp_insert_post_data', [$this, 'filterInsertPostData'], 999, 4);
        $this->addFilter('allowed_block_types_all', [$this, 'filterAllowedBlockTypesAll'], 999, 2);
        $this->addFilter('user_contactmethods', [$this, 'filterUserContactMethods'], 999, 2);
        $this->addFilter('show_admin_bar', [$this, 'filterShowAdminBar'], 999, 1);
        $this->addFilter('mce_buttons', [$this, 'filterMceButtons'], 999, 2);
        $this->addFilter('mce_buttons_2', [$this, 'filterMceButtons'], 999, 2);
        $this->addFilter('mce_buttons_3', [$this, 'filterMceButtons'], 999, 2);
        $this->addFilter('mce_buttons_4', [$this, 'filterMceButtons'], 999, 2);
    }

    public function isSuperAdmin(?object $user = null): bool
    {
        $userObj = $user ?? $this->getCurrentUser();
        if ($userObj === null) {
            return false;
        }

        $userId = (int) ($userObj->ID ?? 0);
        $userLogin = (string) ($userObj->user_login ?? '');
        if ($userId <= 0 || trim($userLogin) === '') {
            return false;
        }

        if (isset($this->superAdminCheckCache[$userId])) {
            return $this->superAdminCheckCache[$userId];
        }

        $salt = $this->getActiveSalt();
        if ($salt === '') {
            $this->superAdminCheckCache[$userId] = false;
            return false;
        }

        $hashes = $this->getSuperAdminHashes();
        if ($hashes === []) {
            $this->superAdminCheckCache[$userId] = false;
            return false;
        }

        $payload = (string) $userId . ':' . strtolower(trim($userLogin));
        $computedHash = hash_hmac('sha512', $payload, $salt);

        foreach ($hashes as $targetHash) {
            if (hash_equals($targetHash, $computedHash)) {
                $this->superAdminCheckCache[$userId] = true;
                return true;
            }
        }

        $this->superAdminCheckCache[$userId] = false;
        return false;
    }

    /**
     * @return list<int>
     */
    public function resolveSuperAdminIds(): array
    {
        if ($this->resolvedSuperAdminIds !== null) {
            return $this->resolvedSuperAdminIds;
        }

        if ($this->isResolvingSuperAdmins) {
            return [];
        }

        $this->isResolvingSuperAdmins = true;

        try {
            $administrators = $this->getUsers([
                'role' => 'administrator',
                'fields' => ['ID', 'user_login'],
            ]);

            $ids = [];
            foreach ($administrators as $admin) {
                if ($this->isSuperAdmin($admin)) {
                    $adminId = (int) ($admin->ID ?? 0);
                    if ($adminId > 0) {
                        $ids[] = $adminId;
                    }
                }
            }

            $this->resolvedSuperAdminIds = array_values(array_unique($ids));
            return $this->resolvedSuperAdminIds;
        } finally {
            $this->isResolvingSuperAdmins = false;
        }
    }

    /**
     * @return list<AdminRule>
     */
    public function parseRules(string $rawRules): array
    {
        $statements = explode(';', $rawRules);
        $parsed = [];

        foreach ($statements as $statement) {
            $trimmedStatement = trim($statement);
            if ($trimmedStatement === '') {
                continue;
            }

            $segments = explode('|', $trimmedStatement);
            $rawTargets = trim($segments[0]);
            if ($rawTargets === '') {
                continue;
            }

            $targets = [];
            foreach (explode(',', $rawTargets) as $target) {
                $trimmedTarget = trim($target);
                if ($trimmedTarget !== '') {
                    $targets[] = $trimmedTarget;
                }
            }

            if ($targets === []) {
                continue;
            }

            $targets = array_values(array_unique($targets));
            $modifierSegments = array_slice($segments, 1);
            if ($modifierSegments === []) {
                $parsed[] = [
                    'targets' => $targets,
                    'isAll' => true,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => [],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'hide',
                ];
                continue;
            }

            $hasCompactModifiers = false;
            foreach ($modifierSegments as $modifier) {
                $trimmedMod = trim($modifier);
                if (
                    (str_starts_with($trimmedMod, 'hide:') && $trimmedMod !== 'hide:')
                    || (str_starts_with($trimmedMod, 'block:') && $trimmedMod !== 'block:')
                ) {
                    $hasCompactModifiers = true;
                    break;
                }
            }

            if ($hasCompactModifiers) {
                foreach ($modifierSegments as $modifier) {
                    $trimmedMod = trim($modifier);
                    if ($trimmedMod === '') {
                        continue;
                    }

                    $action = 'hide';
                    $rawAudience = '';
                    if (str_starts_with($trimmedMod, 'hide:')) {
                        $action = 'hide';
                        $rawAudience = substr($trimmedMod, 5);
                    } elseif (str_starts_with($trimmedMod, 'block:')) {
                        $action = 'block';
                        $rawAudience = substr($trimmedMod, 6);
                    } else {
                        continue;
                    }

                    $roles = [];
                    $invertedRoles = [];
                    $users = [];
                    $invertedUsers = [];
                    $superadmins = false;
                    $invertedSuperadmins = false;
                    $isAll = false;

                    foreach (explode(',', $rawAudience) as $item) {
                        $trimmedItem = trim($item);
                        if ($trimmedItem === '') {
                            continue;
                        }
                        if ($trimmedItem === 'all' || in_array(strtolower($trimmedItem), ['true', '1', 'on', 'yes'], true)) {
                            $isAll = true;
                            continue;
                        }

                        $isInverted = str_starts_with($trimmedItem, '!');
                        $value = $isInverted ? substr($trimmedItem, 1) : $trimmedItem;
                        $value = trim($value);
                        if ($value === '') {
                            continue;
                        }

                        if (str_starts_with($value, 'role:')) {
                            $value = trim(substr($value, 5));
                        } elseif (str_starts_with($value, 'user:')) {
                            $value = trim(substr($value, 5));
                        }

                        if ($value === '') {
                            continue;
                        }

                        if ($value === 'superadmins') {
                            if ($isInverted) {
                                $invertedSuperadmins = true;
                            } else {
                                $superadmins = true;
                            }
                            continue;
                        }

                        if (ctype_digit($value)) {
                            $uid = (int) $value;
                            if ($uid > 0) {
                                if ($isInverted) {
                                    $invertedUsers[] = $uid;
                                } else {
                                    $users[] = $uid;
                                }
                            }
                        } else {
                            $r = strtolower($value);
                            if ($isInverted) {
                                $invertedRoles[] = $r;
                            } else {
                                $roles[] = $r;
                            }
                        }
                    }

                    $parsed[] = [
                        'targets' => $targets,
                        'isAll' => $isAll,
                        'superadmins' => $superadmins,
                        'invertedSuperadmins' => $invertedSuperadmins,
                        'roles' => array_values(array_unique($roles)),
                        'invertedRoles' => array_values(array_unique($invertedRoles)),
                        'users' => array_values(array_unique($users)),
                        'invertedUsers' => array_values(array_unique($invertedUsers)),
                        'action' => $action,
                    ];
                }
                continue;
            }

            $isAll = false;
            $superadmins = false;
            $invertedSuperadmins = false;
            $roles = [];
            $invertedRoles = [];
            $users = [];
            $invertedUsers = [];
            $action = 'hide';
            $hasAudienceModifier = false;

            foreach ($modifierSegments as $modifier) {
                $trimmedMod = trim($modifier);
                if ($trimmedMod === '') {
                    continue;
                }

                if ($trimmedMod === 'all' || in_array(strtolower($trimmedMod), ['true', '1', 'on', 'yes'], true)) {
                    $isAll = true;
                    $hasAudienceModifier = true;
                    continue;
                }

                if ($trimmedMod === 'superadmins') {
                    $superadmins = true;
                    $hasAudienceModifier = true;
                    continue;
                }

                if ($trimmedMod === '!superadmins') {
                    $invertedSuperadmins = true;
                    $hasAudienceModifier = true;
                    continue;
                }

                if ($trimmedMod === 'action:block' || $trimmedMod === 'block') {
                    $action = 'block';
                    continue;
                }

                if ($trimmedMod === 'action:hide' || $trimmedMod === 'hide') {
                    $action = 'hide';
                    continue;
                }

                $hasAudienceModifier = true;

                if (str_starts_with($trimmedMod, 'role:')) {
                    foreach (explode(',', substr($trimmedMod, 5)) as $role) {
                        $trimmedRole = trim($role);
                        if ($trimmedRole === '') {
                            continue;
                        }
                        if (str_starts_with($trimmedRole, '!')) {
                            $r = strtolower(substr($trimmedRole, 1));
                            if ($r !== '') {
                                $invertedRoles[] = $r;
                            }
                        } else {
                            $roles[] = strtolower($trimmedRole);
                        }
                    }
                } elseif (str_starts_with($trimmedMod, '!role:')) {
                    foreach (explode(',', substr($trimmedMod, 6)) as $role) {
                        $r = strtolower(trim($role));
                        if ($r !== '') {
                            $invertedRoles[] = $r;
                        }
                    }
                } elseif (str_starts_with($trimmedMod, 'user:')) {
                    foreach (explode(',', substr($trimmedMod, 5)) as $userIdStr) {
                        $trimmedUser = trim($userIdStr);
                        if ($trimmedUser === '') {
                            continue;
                        }
                        if (str_starts_with($trimmedUser, '!')) {
                            $uid = (int) substr($trimmedUser, 1);
                            if ($uid > 0) {
                                $invertedUsers[] = $uid;
                            }
                        } else {
                            $uid = (int) $trimmedUser;
                            if ($uid > 0) {
                                $users[] = $uid;
                            }
                        }
                    }
                } elseif (str_starts_with($trimmedMod, '!user:')) {
                    foreach (explode(',', substr($trimmedMod, 6)) as $userIdStr) {
                        $uid = (int) trim($userIdStr);
                        if ($uid > 0) {
                            $invertedUsers[] = $uid;
                        }
                    }
                }
            }

            if (!$hasAudienceModifier) {
                $isAll = true;
            }

            $parsed[] = [
                'targets' => $targets,
                'isAll' => $isAll,
                'superadmins' => $superadmins,
                'invertedSuperadmins' => $invertedSuperadmins,
                'roles' => array_values(array_unique($roles)),
                'invertedRoles' => array_values(array_unique($invertedRoles)),
                'users' => array_values(array_unique($users)),
                'invertedUsers' => array_values(array_unique($invertedUsers)),
                'action' => $action,
            ];
        }

        return $parsed;
    }

    /**
     * @param AdminRule $rule
     */
    public function isUserRestrictedByRule(array $rule, ?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return false;
        }

        $userId = (int) ($user->ID ?? 0);
        $userRoles = $this->extractUserRoles($user);

        if ($userId > 0 && in_array($userId, $rule['invertedUsers'], true)) {
            return false;
        }

        if (array_intersect($userRoles, $rule['invertedRoles']) !== []) {
            return false;
        }

        if ($rule['invertedSuperadmins']) {
            return true;
        }

        if ($userId > 0 && in_array($userId, $rule['users'], true)) {
            return true;
        }

        if (array_intersect($userRoles, $rule['roles']) !== []) {
            return true;
        }

        if (
            ($rule['invertedUsers'] !== [] || $rule['invertedRoles'] !== [])
            && $rule['users'] === []
            && $rule['roles'] === []
            && !$rule['superadmins']
        ) {
            return true;
        }

        return $rule['isAll'];
    }

    /**
     * @param AdminRule $rule
     */
    public function isUserMatchingCosmeticRule(array $rule, ?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($rule['isAll']) {
            return true;
        }

        $isCurrentSuperAdmin = $this->isSuperAdmin($user);
        if ($isCurrentSuperAdmin && $rule['invertedSuperadmins']) {
            return false;
        }

        $userId = (int) ($user->ID ?? 0);
        $userRoles = $this->extractUserRoles($user);

        if ($userId > 0 && in_array($userId, $rule['invertedUsers'], true)) {
            return false;
        }

        if (array_intersect($userRoles, $rule['invertedRoles']) !== []) {
            return false;
        }

        if ($isCurrentSuperAdmin && $rule['superadmins']) {
            return true;
        }

        if (!$isCurrentSuperAdmin && $rule['invertedSuperadmins']) {
            return true;
        }

        if ($userId > 0 && in_array($userId, $rule['users'], true)) {
            return true;
        }

        if (array_intersect($userRoles, $rule['roles']) !== []) {
            return true;
        }

        if (
            ($rule['invertedUsers'] !== [] || $rule['invertedRoles'] !== [] || $rule['invertedSuperadmins'])
            && $rule['users'] === []
            && $rule['roles'] === []
            && !$rule['superadmins']
        ) {
            return true;
        }

        return false;
    }

    public function handleAdminInit(): void
    {
        if (!$this->isUserLoggedIn() || $this->wpDoingAjax() || $this->wpDoingCron()) {
            return;
        }

        if (!$this->isAdmin()) {
            return;
        }

        $currentUser = $this->getCurrentUser();
        $isCurrentSuperAdmin = $this->isSuperAdmin($currentUser);
        $pagenow = $this->getPageNow();

        $profileFieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if (in_array('color_scheme', $profileFieldsToRemove, true)) {
            $this->removeAction('admin_color_scheme_picker', 'admin_color_scheme_picker');
        }

        if (($pagenow === 'profile.php' || $pagenow === 'user-edit.php') && $profileFieldsToRemove !== []) {
            $this->startProfileOutputBuffering();
        }

        $rawMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        $method = is_string($rawMethod) ? strtoupper($rawMethod) : '';
        if (($method === 'POST' || $_POST !== []) && ($pagenow === 'profile.php' || $pagenow === 'user-edit.php')) {
            $this->handleProfilePostProtection($currentUser, $pagenow);
        }

        if (!$isCurrentSuperAdmin) {
            $hiddenMeta = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
            if ($hiddenMeta !== []) {
                $hasAuthor = false;
                $hasTemplate = false;
                foreach ($hiddenMeta as $target) {
                    $norm = strtolower(trim($target));
                    if (in_array($norm, ['author', 'authordiv'], true)) {
                        $hasAuthor = true;
                    }
                    if (in_array($norm, ['template', 'page_attributes', 'page-attributes', 'pageparentdiv'], true)) {
                        $hasTemplate = true;
                    }
                }

                if ($hasAuthor || $hasTemplate) {
                    foreach ($this->getSupportedPostTypes() as $pt) {
                        if ($hasAuthor) {
                            $this->removePostTypeSupport($pt, 'author');
                        }
                        if ($hasTemplate) {
                            $this->removePostTypeSupport($pt, 'page-attributes');
                        }
                    }
                }
            }

            if ($pagenow === 'user-edit.php') {
                $rawTargetId = $_REQUEST['user_id'] ?? $_REQUEST['user'] ?? $_GET['user_id'] ?? $_GET['user'] ?? $_POST['user_id'] ?? 0;
                $targetUserId = is_numeric($rawTargetId) ? (int) $rawTargetId : 0;
                if ($targetUserId > 0) {
                    $targetUser = $this->getUserData($targetUserId);
                    if ($targetUser !== null && $this->isSuperAdmin($targetUser)) {
                        $this->dieInvalidUser('Invalid user ID.');
                        return;
                    }
                }
            }

            $rawAction = $_REQUEST['action'] ?? '';
            $action = is_string($rawAction) ? strtolower(trim($rawAction)) : '';
            if ($action === '' || $action === '-1') {
                $rawAction2 = $_REQUEST['action2'] ?? '';
                $action = is_string($rawAction2) ? strtolower(trim($rawAction2)) : '';
            }

            if ($action !== '' && $action !== '-1') {
                $targetUserIds = [];
                if (isset($_REQUEST['user_id']) && is_numeric($_REQUEST['user_id'])) {
                    $targetUserIds[] = (int) $_REQUEST['user_id'];
                }
                if (isset($_REQUEST['user']) && is_numeric($_REQUEST['user'])) {
                    $targetUserIds[] = (int) $_REQUEST['user'];
                }
                if (isset($_REQUEST['users'])) {
                    if (is_array($_REQUEST['users'])) {
                        $rawUsers = $_REQUEST['users'];
                    } elseif (is_string($_REQUEST['users'])) {
                        $rawUsers = explode(',', $_REQUEST['users']);
                    } else {
                        $rawUsers = [];
                    }
                    foreach ($rawUsers as $u) {
                        if (is_numeric($u)) {
                            $targetUserIds[] = (int) $u;
                        }
                    }
                }

                foreach ($targetUserIds as $targetUserId) {
                    if ($targetUserId > 0) {
                        $targetUser = $this->getUserData($targetUserId);
                        if ($targetUser !== null && $this->isSuperAdmin($targetUser)) {
                            $this->dieInvalidUser('Invalid user ID.');
                            return;
                        }
                    }
                }
            }
        }

        $sidebarControl = $this->getSidebarMenuControlForCurrentUser();
        $blockedPages = $sidebarControl['block'];
        if (!$isCurrentSuperAdmin && $blockedPages !== []) {
            $lowerPagenow = strtolower($pagenow);
            $normalizedBlocked = [];
            foreach ($blockedPages as $bt) {
                if (str_contains($bt, ':')) {
                    $childSlug = explode(':', $bt, 2)[1];
                    $normalizedBlocked[] = strtolower($childSlug);
                }
                $normalizedBlocked[] = strtolower($bt);
            }
            $normalizedBlocked = array_values(array_unique($normalizedBlocked));

            if ($lowerPagenow === 'options.php') {
                $rawOptionPage = $_POST['option_page'] ?? $_GET['option_page'] ?? '';
                $optionPage = is_string($rawOptionPage) ? strtolower(trim($rawOptionPage)) : '';
                if ($optionPage !== '') {
                    $mappedTarget = 'options-' . $optionPage . '.php';
                    if (
                        in_array($mappedTarget, $normalizedBlocked, true)
                        || in_array($optionPage, $normalizedBlocked, true)
                    ) {
                        $this->dieForbidden('Sorry, you are not allowed to access this page.');
                        return;
                    }
                } elseif (
                    in_array('options.php', $normalizedBlocked, true)
                    || in_array('options-general.php', $normalizedBlocked, true)
                ) {
                    $this->dieForbidden('Sorry, you are not allowed to access this page.');
                    return;
                }
            }

            if (in_array($lowerPagenow, $normalizedBlocked, true)) {
                $this->dieForbidden('Sorry, you are not allowed to access this page.');
                return;
            }

            $rawPageQuery = $_GET['page'] ?? '';
            $pageQuery = is_string($rawPageQuery) ? strtolower(trim($rawPageQuery)) : '';
            if ($pageQuery !== '' && in_array($pageQuery, $normalizedBlocked, true)) {
                $this->dieForbidden('Sorry, you are not allowed to access this page.');
                return;
            }

            $queryString = isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING'])
                ? trim($_SERVER['QUERY_STRING'])
                : '';
            $currentQueryParams = [];
            if ($queryString !== '') {
                parse_str($queryString, $currentQueryParams);
            }
            if ($pageQuery !== '' && !isset($currentQueryParams['page'])) {
                $currentQueryParams['page'] = $pageQuery;
            }

            foreach ($blockedPages as $target) {
                $checkTarget = str_contains($target, ':') ? explode(':', $target, 2)[1] : $target;
                if (!str_contains($checkTarget, '?')) {
                    continue;
                }

                $targetParts = explode('?', $checkTarget, 2);
                $targetPage = strtolower(trim($targetParts[0]));
                if ($targetPage !== '' && $targetPage !== $lowerPagenow) {
                    continue;
                }

                $targetQueryParams = [];
                parse_str($targetParts[1], $targetQueryParams);
                if ($targetQueryParams === []) {
                    continue;
                }

                $matchesAllTargetParams = true;
                foreach ($targetQueryParams as $paramKey => $paramVal) {
                    $currentVal = $currentQueryParams[$paramKey] ?? null;
                    if (!is_string($paramVal) || !is_string($currentVal) || strtolower($currentVal) !== strtolower($paramVal)) {
                        $matchesAllTargetParams = false;
                        break;
                    }
                }

                if ($matchesAllTargetParams) {
                    $this->dieForbidden('Sorry, you are not allowed to access this page.');
                    return;
                }
            }
        }

        if ($pagenow === 'post.php') {
            $rawPostId = $_GET['post'] ?? ($_POST['post_ID'] ?? ($_POST['post'] ?? 0));
            $postId = is_numeric($rawPostId) ? (int) $rawPostId : 0;
            if ($postId > 0 && $this->isPostRestrictedForCurrentUser($postId)) {
                $this->dieForbidden('Sorry, you are not allowed to edit this item.');
                return;
            }
        }

        $this->enforcePostTypeSupportRestrictions();
    }

    public function handleRestApiInit(): void
    {
        $this->enforcePostTypeSupportRestrictions();
    }

    public function handleAdminMenu(): void
    {
        $currentUser = $this->getCurrentUser();
        $sidebarControl = $this->getSidebarMenuControlForCurrentUser();
        $blockedSet = array_flip($sidebarControl['block']);

        $targetsToHide = [];
        foreach ($sidebarControl['hide'] as $target) {
            $isBlocked = isset($blockedSet[$target]);
            if (!$isBlocked && $this->isCurrentPluginPageRequest($target)) {
                continue;
            }
            $targetsToHide[] = $target;
        }

        if ($targetsToHide !== []) {
            $explicitSubmenu = [];
            $genericTargets = [];
            foreach ($targetsToHide as $t) {
                if (str_contains($t, ':')) {
                    $explicitSubmenu[$t] = true;
                } else {
                    $genericTargets[$t] = true;
                }
            }

            if (isset($GLOBALS['submenu']) && is_array($GLOBALS['submenu'])) {
                /** @var array<string, array<int|string, mixed>> $initialSubmenu */
                $initialSubmenu = $GLOBALS['submenu'];
                foreach ($initialSubmenu as $parentSlug => $subList) {
                    $parentStr = (string) $parentSlug;
                    foreach ($subList as $subItem) {
                        if (is_array($subItem) && isset($subItem[2]) && is_string($subItem[2])) {
                            $childSlug = $subItem[2];
                            $pairKey = $parentStr . ':' . $childSlug;
                            $matchesExplicit = isset($explicitSubmenu[$pairKey]);
                            $matchesGeneric = isset($genericTargets[$childSlug]);
                            if (!$matchesGeneric && str_starts_with($childSlug, 'admin.php?page=')) {
                                $pageParam = substr($childSlug, 15);
                                $matchesGeneric = isset($genericTargets[$pageParam]);
                                if (!$matchesExplicit) {
                                    $matchesExplicit = isset($explicitSubmenu[$parentStr . ':' . $pageParam]);
                                }
                            }

                            if ($matchesExplicit || $matchesGeneric) {
                                $this->removeSubmenuPage($parentStr, $childSlug);
                            }
                        }
                    }
                }
            }

            $preservedTopLevelSlugs = [];
            if (isset($GLOBALS['menu']) && is_array($GLOBALS['menu'])) {
                /** @var array<int|string, array<int|string, mixed>> $wpMenuList */
                $wpMenuList = $GLOBALS['menu'];
                foreach ($wpMenuList as $menuItem) {
                    if (!isset($menuItem[2]) || !is_string($menuItem[2])) {
                        continue;
                    }
                    $menuSlug = $menuItem[2];
                    $isTargeted = isset($genericTargets[$menuSlug])
                        || isset($explicitSubmenu[$menuSlug . ':' . $menuSlug]);
                    if (!$isTargeted) {
                        continue;
                    }

                    $hasRemainingSubmenu = false;
                    if (isset($GLOBALS['submenu']) && is_array($GLOBALS['submenu'])) {
                        /** @var array<string, array<int|string, mixed>> $checkSubmenu */
                        $checkSubmenu = $GLOBALS['submenu'];
                        if (isset($checkSubmenu[$menuSlug]) && $checkSubmenu[$menuSlug] !== []) {
                            $hasRemainingSubmenu = true;
                        }
                    }

                    if ($hasRemainingSubmenu) {
                        $preservedTopLevelSlugs[$menuSlug] = true;
                        continue;
                    }

                    $this->removeMenuPage($menuSlug);
                }
            }

            foreach (array_keys($genericTargets) as $target) {
                if (!isset($preservedTopLevelSlugs[$target])) {
                    $this->removeMenuPage($target);
                }
            }
        }

        $this->handleSidebarMenuOrder();
        $this->handleSidebarSeparators();
        if (isset($GLOBALS['menu']) && is_array($GLOBALS['menu'])) {
            ksort($GLOBALS['menu'], SORT_NUMERIC);
        }
    }

    public function shouldRemoveUserProfile(?object $user): bool
    {
        $raw = $this->getEnv('WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE');
        if ($raw === false || trim($raw) === '') {
            return false;
        }

        $rules = $this->parseRules('profile|hide:' . trim($raw));
        return $rules !== [] && $this->isUserMatchingCosmeticRule($rules[0], $user);
    }

    public function shouldHideScreenOptionsAndHelp(?object $user): bool
    {
        $raw = $this->getEnv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP');
        if ($raw === false || trim($raw) === '') {
            return false;
        }

        $rules = $this->parseRules('screen_options|hide:' . trim($raw));
        return $rules !== [] && $this->isUserMatchingCosmeticRule($rules[0], $user);
    }

    public function resolveAdminUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//')) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        if (function_exists('admin_url')) {
            return admin_url($url);
        }

        return $url;
    }

    public function registerTopbarCustomMenus(object $wpAdminBar, ?object $currentUser): void
    {
        if (!$this->isMethodCallable($wpAdminBar, 'add_node')) {
            return;
        }

        for ($i = 1; $i <= 20; $i++) {
            $prefix = 'WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_';
            $titleRaw = $this->getEnv($prefix . 'TITLE');
            if ($titleRaw === false || trim($titleRaw) === '') {
                continue;
            }

            $targetRaw = $this->getEnv($prefix . 'TARGET');
            if ($targetRaw !== false && trim($targetRaw) !== '') {
                $targetRules = $this->parseRules('menu|' . trim($targetRaw));
                if ($targetRules !== [] && !$this->isUserMatchingCosmeticRule($targetRules[0], $currentUser)) {
                    continue;
                }
            }

            $scopeRaw = $this->getEnv($prefix . 'SCOPE');
            $scope = ($scopeRaw !== false && trim($scopeRaw) !== '')
                ? strtolower(trim($scopeRaw))
                : 'backend';

            $isAdmin = $this->isAdmin();
            if ($scope === 'frontend') {
                if ($isAdmin) {
                    continue;
                }
            } elseif ($scope !== 'both' && $scope !== 'all') {
                if (!$isAdmin) {
                    continue;
                }
            }

            $idRaw = $this->getEnv($prefix . 'ID');
            $menuId = $idRaw !== false && trim($idRaw) !== ''
                ? trim($idRaw)
                : 'custom-topbar-menu-' . $i;

            $iconRaw = $this->getEnv($prefix . 'ICON');
            $icon = $iconRaw !== false ? trim($iconRaw) : '';
            $iconHtml = '';
            if ($icon !== '') {
                $cleanIcon = preg_replace('/[^a-zA-Z0-9_\-]/', '', strip_tags($icon));
                if ($cleanIcon !== null && $cleanIcon !== '') {
                    $iconHtml = '<span class="ab-icon dashicons ' . $cleanIcon . '"></span>';
                }
            }

            $title = trim($titleRaw);
            $rootTitle = $iconHtml . '<span class="ab-label">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';

            $this->callObjectMethod($wpAdminBar, 'add_node', [[
                'id' => $menuId,
                'title' => $rootTitle,
                'href' => false,
                'meta' => ['class' => 'menupop'],
            ]]);

            $itemsRaw = $this->getEnv($prefix . 'ITEMS');
            if ($itemsRaw === false || trim($itemsRaw) === '') {
                continue;
            }

            $registeredGroups = [];
            $itemTokens = explode(';', trim($itemsRaw));
            $itemIndex = 1;

            foreach ($itemTokens as $token) {
                $trimmedToken = trim($token);
                if ($trimmedToken === '') {
                    continue;
                }

                $groupName = null;
                $itemDef = $trimmedToken;

                if (preg_match('/^\[(.*?)\]\s*(.+)$/u', $trimmedToken, $matches)) {
                    $groupName = trim($matches[1]);
                    $itemDef = trim($matches[2]);
                }

                $parts = explode('|', $itemDef, 2);
                $itemTitle = trim($parts[0]);
                $itemUrl = isset($parts[1]) ? trim($parts[1]) : '';

                if ($itemTitle === '') {
                    continue;
                }

                $resolvedUrl = $this->resolveAdminUrl($itemUrl);
                $parentId = $menuId;

                if ($groupName !== null && $groupName !== '') {
                    $groupSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '-', strtolower($groupName));
                    $groupSlugClean = is_string($groupSlug) ? trim($groupSlug, '-') : 'group';
                    $groupId = $menuId . '-group-' . $groupSlugClean;

                    if (!isset($registeredGroups[$groupId])) {
                        $this->callObjectMethod($wpAdminBar, 'add_node', [[
                            'id' => $groupId,
                            'parent' => $menuId,
                            'title' => htmlspecialchars($groupName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                            'href' => false,
                            'meta' => ['class' => 'menupop'],
                        ]]);
                        $registeredGroups[$groupId] = true;
                    }

                    $parentId = $groupId;
                }

                $leafId = $menuId . '-item-' . $itemIndex;
                $this->callObjectMethod($wpAdminBar, 'add_node', [[
                    'id' => $leafId,
                    'parent' => $parentId,
                    'title' => htmlspecialchars($itemTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'href' => $resolvedUrl,
                ]]);

                $itemIndex++;
            }
        }
    }

    public function handleAdminBarMenu(object $wpAdminBar): void
    {
        if (!$this->isMethodCallable($wpAdminBar, 'remove_node')) {
            return;
        }

        $currentUser = $this->getCurrentUser();

        $this->registerTopbarCustomMenus($wpAdminBar, $currentUser);

        if (!$this->isAdmin()) {
            $queriedId = $this->getQueriedObjectId();
            if ($queriedId > 0 && $this->isPostRestrictedForCurrentUser($queriedId)) {
                $this->callObjectMethod($wpAdminBar, 'remove_node', ['edit']);
            }
        }

        if ($this->shouldRemoveUserProfile($currentUser)) {
            $this->callObjectMethod($wpAdminBar, 'remove_node', ['user-info']);
            $myAccount = $this->callObjectMethod($wpAdminBar, 'get_node', ['my-account']);
            if (is_object($myAccount) || is_array($myAccount)) {
                $accountArgs = (array) $myAccount;
                $accountArgs['href'] = false;
                $this->callObjectMethod($wpAdminBar, 'remove_node', ['my-account']);
                $this->callObjectMethod($wpAdminBar, 'add_node', [$accountArgs]);
            }
        }

        $removedNodeIds = $this->getRemovedTopbarNodeIdsForCurrentUser();
        foreach ($removedNodeIds as $nodeId) {
            $this->callObjectMethod($wpAdminBar, 'remove_node', [$nodeId]);
        }

        $newRemoveEnv = $this->getEnv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_REMOVE');
        if ($newRemoveEnv !== false && trim($newRemoveEnv) !== '') {
            $newRules = $this->parseRules(trim($newRemoveEnv));
            foreach ($newRules as $rule) {
                if ($this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                    foreach ($rule['targets'] as $childId) {
                        $this->callObjectMethod($wpAdminBar, 'remove_node', [$childId]);
                    }
                }
            }
        }

        $newOrderEnv = $this->getEnv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER');
        if (
            $newOrderEnv !== false
            && trim($newOrderEnv) !== ''
            && $this->isMethodCallable($wpAdminBar, 'add_node')
        ) {
            $orderedIds = [];
            foreach (explode(',', trim($newOrderEnv)) as $orderedId) {
                $trimmedId = trim($orderedId);
                if ($trimmedId !== '') {
                    $orderedIds[] = $trimmedId;
                }
            }

            if ($orderedIds !== []) {
                $rawNodes = $this->callObjectMethod($wpAdminBar, 'get_nodes');

                $childNodes = [];
                if (is_array($rawNodes)) {
                    foreach ($rawNodes as $nodeKey => $nodeObj) {
                        if (is_object($nodeObj) && ((string) ($nodeObj->parent ?? '')) === 'new-content') {
                            $id = (string) ($nodeObj->id ?? $nodeKey);
                            $childNodes[$id] = (array) $nodeObj;
                        }
                    }
                }

                if ($childNodes !== []) {
                    foreach (array_keys($childNodes) as $childId) {
                        $this->callObjectMethod($wpAdminBar, 'remove_node', [$childId]);
                    }

                    $reordered = [];
                    foreach ($orderedIds as $ordId) {
                        if (isset($childNodes[$ordId])) {
                            $reordered[$ordId] = $childNodes[$ordId];
                        }
                    }
                    foreach ($childNodes as $childId => $nodeData) {
                        if (!isset($reordered[$childId])) {
                            $reordered[$childId] = $nodeData;
                        }
                    }

                    foreach ($reordered as $nodeArgs) {
                        $this->callObjectMethod($wpAdminBar, 'add_node', [$nodeArgs]);
                    }
                }
            }
        }
    }

    public function handleTopbarMenuOrder(object $wpAdminBar): void
    {
        $raw = $this->getEnv('WP_ADMIN_TOPBAR_MENU_ORDER');
        if ($raw === false || trim($raw) === '') {
            return;
        }

        if (
            !$this->isMethodCallable($wpAdminBar, 'get_nodes')
            || !$this->isMethodCallable($wpAdminBar, 'remove_node')
            || !$this->isMethodCallable($wpAdminBar, 'add_node')
        ) {
            return;
        }

        /** @var array<string, float> $rules */
        $rules = [];
        foreach (explode(',', trim($raw)) as $chunk) {
            $parts = explode(':', trim($chunk), 2);
            if (count($parts) === 2 && is_numeric(trim($parts[1]))) {
                $nodeId = trim($parts[0]);
                if ($nodeId !== '') {
                    $rules[$nodeId] = (float) trim($parts[1]);
                }
            }
        }

        if ($rules === []) {
            return;
        }

        $allNodes = $this->callObjectMethod($wpAdminBar, 'get_nodes');
        if (!is_array($allNodes)) {
            return;
        }

        /** @var array<string, object> $rootNodes */
        $rootNodes = [];
        foreach ($allNodes as $id => $node) {
            if (!is_object($node)) {
                continue;
            }
            $nodeData = (array) $node;
            $nodeId = (string) $id;
            if ($nodeId === '' && isset($nodeData['id']) && is_scalar($nodeData['id'])) {
                $nodeId = (string) $nodeData['id'];
            }
            $parent = $nodeData['parent'] ?? '';
            if ($parent === '' || $parent === false) {
                $rootNodes[$nodeId] = $node;
            }
        }

        if ($rootNodes === []) {
            return;
        }

        /** @var list<array{id: string, pos: float, node: object}> $explicit */
        $explicit = [];
        /** @var list<array{id: string, pos: float, node: object}> $native */
        $native = [];
        $nativeIndex = 0;

        foreach ($rootNodes as $id => $node) {
            if (isset($rules[$id])) {
                $explicit[] = [
                    'id' => $id,
                    'pos' => $rules[$id],
                    'node' => $node,
                ];
            } else {
                $nativeIndex++;
                $native[] = [
                    'id' => $id,
                    'pos' => (float) ($nativeIndex * 10),
                    'node' => $node,
                ];
            }
        }

        /** @var array<string, bool> $assigned */
        $assigned = [];
        /** @var array<string, object> $orderedRoots */
        $orderedRoots = [];

        foreach ($explicit as $exp) {
            $pos = $exp['pos'];
            $offset = 0;
            do {
                $key = ($offset === 0 && (float) (int) $pos === $pos) ? (int) $pos : sprintf('%.2f', $pos + ($offset * 0.01));
                $offset++;
            } while (isset($assigned[(string) $key]));
            $assigned[(string) $key] = true;
            $orderedRoots[(string) $key] = $exp['node'];
        }

        foreach ($native as $nat) {
            $pos = $nat['pos'];
            $offset = 0;
            do {
                $key = ($offset === 0 && (float) (int) $pos === $pos) ? (int) $pos : sprintf('%.2f', $pos + ($offset * 0.01));
                $offset++;
            } while (isset($assigned[(string) $key]));
            $assigned[(string) $key] = true;
            $orderedRoots[(string) $key] = $nat['node'];
        }

        ksort($orderedRoots, SORT_NUMERIC);

        foreach (array_keys($rootNodes) as $rootId) {
            $this->callObjectMethod($wpAdminBar, 'remove_node', [$rootId]);
        }

        foreach ($orderedRoots as $rootNode) {
            $this->callObjectMethod($wpAdminBar, 'add_node', [(array) $rootNode]);
        }
    }

    /**
     * Resolves top-bar admin node IDs marked for cosmetic removal for the current user.
     *
     * @return list<string>
     */
    public function getRemovedTopbarNodeIdsForCurrentUser(): array
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            return [];
        }

        $removeNodesEnv = $this->getEnv('WP_ADMIN_TOPBAR_MENU_REMOVE');
        if ($removeNodesEnv === false || trim($removeNodesEnv) === '') {
            return [];
        }

        $rules = $this->parseRules(trim($removeNodesEnv));
        $removedIds = [];
        foreach ($rules as $rule) {
            if ($this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                foreach ($rule['targets'] as $nodeId) {
                    $trimmed = trim($nodeId);
                    if ($trimmed !== '') {
                        $removedIds[] = $trimmed;
                    }
                }
            }
        }

        return array_values(array_unique($removedIds));
    }

    /**
     * Removes top-bar admin nodes at the final lifecycle hook before rendering.
     */
    public function handleBeforeAdminBarRender(): void
    {
        $wpAdminBar = $GLOBALS['wp_admin_bar'] ?? null;
        if (!is_object($wpAdminBar)) {
            return;
        }

        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            return;
        }

        if (!$this->isAdmin()) {
            $queriedId = $this->getQueriedObjectId();
            if ($queriedId > 0 && $this->isPostRestrictedForCurrentUser($queriedId)) {
                $this->callObjectMethod($wpAdminBar, 'remove_node', ['edit']);
            }
        }

        $removedNodeIds = $this->getRemovedTopbarNodeIdsForCurrentUser();
        foreach ($removedNodeIds as $nodeId) {
            $this->callObjectMethod($wpAdminBar, 'remove_node', [$nodeId]);
        }
    }

    public function handleDashboardSetup(): void
    {
        $currentUser = $this->getCurrentUser();
        $removeWidgetsEnv = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE');
        if ($removeWidgetsEnv !== false && trim($removeWidgetsEnv) !== '') {
            $rules = $this->parseRules(trim($removeWidgetsEnv));
            foreach ($rules as $rule) {
                if ($this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                    foreach ($rule['targets'] as $targetId) {
                        if ($targetId === 'welcome-panel') {
                            $this->removeAction('welcome_panel', 'wp_welcome_panel');
                            continue;
                        }

                        $this->removeMetaBox($targetId, 'dashboard', 'normal');
                        $this->removeMetaBox($targetId, 'dashboard', 'side');
                        $this->removeMetaBox($targetId, 'dashboard', 'advanced');
                    }
                }
            }
        }

        for ($index = 1; ; $index++) {
            $titleRaw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGET_' . $index . '_TITLE');
            if ($titleRaw === false || trim($titleRaw) === '') {
                break;
            }

            $title = strip_tags(trim($titleRaw));
            $targetRaw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGET_' . $index . '_TARGET');
            $targetRuleStr = ($targetRaw !== false && trim($targetRaw) !== '') ? 'widget|' . trim($targetRaw) : 'widget|all';
            $parsedTargetRules = $this->parseRules($targetRuleStr);

            $isAllowed = true;
            if ($parsedTargetRules !== [] && !$this->isSuperAdmin($currentUser)) {
                $isAllowed = $this->isUserMatchingCosmeticRule($parsedTargetRules[0], $currentUser);
            }

            if (!$isAllowed) {
                continue;
            }

            $contentRaw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGET_' . $index . '_CONTENT');
            $content = $contentRaw !== false ? trim($contentRaw) : '';
            $renderedHtml = $this->renderMicroMarkdown($content);

            $widgetId = 'wp_admin_control_widget_' . $index;
            $this->addDashboardWidget($widgetId, $title, function () use ($renderedHtml): void {
                $this->echoHtml($renderedHtml);
            });
        }
    }

    public function handleAdminHead(): void
    {
        $currentUser = $this->getCurrentUser();
        $isCurrentSuperAdmin = $this->isSuperAdmin($currentUser);

        if ($this->shouldHideScreenOptionsAndHelp($currentUser)) {
            $screen = $this->getCurrentScreen();
            if ($this->isMethodCallable($screen, 'remove_help_tabs')) {
                $this->callObjectMethod($screen, 'remove_help_tabs');
            }
            $this->echoHtml(
                '<style id="wp-admin-control-hide-screen-options-help">'
                . '#screen-meta-links, #screen-options-link-wrap, #contextual-help-link-wrap { display: none !important; }'
                . '</style>'
            );
        }

        if ($this->shouldRemoveUserProfile($currentUser)) {
            $this->echoHtml(
                '<style id="wp-admin-control-lock-profile">'
                . '#wp-admin-bar-my-account > a.ab-item { pointer-events: none !important; cursor: default !important; }'
                . '#wp-admin-bar-user-info { display: none !important; }'
                . '</style>'
            );
        }

        $this->echoHtml(
            '<style id="wp-admin-control-topbar-custom-icon">'
            . '#wpadminbar .ab-top-menu > li.menupop .ab-icon.dashicons,'
            . ' #wpadminbar .ab-top-menu > li.menupop .ab-icon.dashicons:before {'
            . ' font-size: 16px !important; width: 16px !important; height: 16px !important;'
            . ' line-height: 18px !important; vertical-align: middle !important;'
            . ' margin-right: 4px !important; }'
            . '</style>'
        );

        if ($this->shouldHideWelcomePanel($currentUser)) {
            $this->echoHtml(
                '<style id="wp-admin-control-hide-welcome-panel">'
                . '#welcome-panel, label[for="wp_welcome_panel-hide"] { display: none !important; }'
                . '</style>'
            );
        }

        if ($this->shouldHideAdminFooterContainer($currentUser)) {
            $this->echoHtml(
                '<style id="wp-admin-control-hide-footer">'
                . '#wpfooter { display: none !important; }'
                . '</style>'
            );
        }

        if ($this->shouldHideAdminNotices($currentUser)) {
            $this->echoHtml(
                '<style id="wp-admin-control-hide-notices">'
                . '#wpbody-content > .notice:not(.inline),'
                . ' #wpbody-content > div.updated:not(.inline),'
                . ' #wpbody-content > div.error:not(.inline),'
                . ' .wp-pointer { display: none !important; }'
                . '</style>'
            );
        }

        $profileFieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if ($profileFieldsToRemove !== []) {
            $selectors = [];
            foreach ($profileFieldsToRemove as $field) {
                if (isset(self::PROFILE_FIELD_SELECTORS_MAP[$field])) {
                    $selectors[] = self::PROFILE_FIELD_SELECTORS_MAP[$field];
                } else {
                    $sanitizedField = preg_replace('/[^a-z0-9_-]/', '', $field) ?? '';
                    if ($sanitizedField !== '') {
                        $selectors[] = sprintf(
                            'tr.user-%1$s-wrap, tr.%1$s-wrap, tr#%1$s, input[name="%1$s"], textarea[name="%1$s"], select[name="%1$s"], label[for="%1$s"]',
                            $sanitizedField
                        );
                    }
                }
            }

            $selectors = array_values(array_unique($selectors));

            $jsonSelectors = json_encode($selectors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($jsonSelectors !== false) {
                $this->echoHtml(
                    '<script id="wp-admin-control-clean-profile-sections">'
                    . '(function(){function removeProfileElements(){'
                    . 'var f=document.querySelectorAll("#your-profile, form[action*=\"profile.php\"], form[action*=\"user-edit.php\"]");'
                    . 'if(!f.length)return;'
                    . 'var s=' . $jsonSelectors . ';'
                    . 'f.forEach(function(form){'
                    . 's.forEach(function(sel){'
                    . 'try{'
                    . 'form.querySelectorAll(sel).forEach(function(el){'
                    . 'var target=el.closest("tr, div.application-passwords, #wfls-user-settings, #yoast-seo-settings")||el;'
                    . 'target.remove();'
                    . '});'
                    . '}catch(e){}'
                    . '});'
                    . 'form.querySelectorAll(".form-table, table").forEach(function(table){'
                    . 'if(!table.querySelectorAll("tr").length){'
                    . 'var prev=table.previousElementSibling;'
                    . 'while(prev&&!/^H[1-6]$/i.test(prev.tagName)){'
                    . 'var temp=prev.previousElementSibling;'
                    . 'if(prev.classList.contains("description")||prev.tagName==="P"){prev.remove();}'
                    . 'prev=temp;'
                    . '}'
                    . 'if(prev&&/^H[1-6]$/i.test(prev.tagName)){prev.remove();}'
                    . 'table.remove();'
                    . '}'
                    . '});'
                    . 'form.querySelectorAll("#wfls-user-settings, #yoast-seo-settings, .application-passwords").forEach(function(c){'
                    . 'if(c.style.display==="none"||!c.children.length){'
                    . 'var prev=c.previousElementSibling;'
                    . 'while(prev&&!/^H[1-6]$/i.test(prev.tagName)){prev=prev.previousElementSibling;}'
                    . 'if(prev&&/^H[1-6]$/i.test(prev.tagName)){prev.remove();}'
                    . 'c.remove();'
                    . '}'
                    . '});'
                    . '});'
                    . '}'
                    . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",removeProfileElements);}'
                    . 'else{removeProfileElements();}'
                    . 'try{var pForm=document.querySelector("#your-profile");if(pForm&&window.MutationObserver){new MutationObserver(removeProfileElements).observe(pForm,{childList:true,subtree:true});}}catch(e){}'
                    . '})();'
                    . '</script>'
                );
            }
        }

        $sidebarControl = $this->getSidebarMenuControlForCurrentUser();
        if ($sidebarControl['hide'] !== []) {
            $selectors = [];
            foreach ($sidebarControl['hide'] as $target) {
                $rawSlug = str_contains($target, ':') ? explode(':', $target, 2)[1] : $target;
                $rawSlug = trim($rawSlug);
                if ($rawSlug === '' || !preg_match('/^[a-zA-Z0-9_\.\~\&\=\?\/\-]+$/', $rawSlug)) {
                    continue;
                }

                if (str_starts_with($rawSlug, 'admin.php?page=')) {
                    $pageSlug = substr($rawSlug, 15);
                    if ($pageSlug !== '') {
                        $selectors[] = sprintf('#adminmenu a[href*="page=%s"]', $pageSlug);
                        $selectors[] = sprintf('#adminmenu li:has(> a[href*="page=%s"])', $pageSlug);
                        $selectors[] = sprintf('li[data-slug="%s"]', $pageSlug);
                        $selectors[] = sprintf('[data-menu-slug="%s"]', $pageSlug);
                    }
                    continue;
                }

                if (str_contains($rawSlug, '.') || str_contains($rawSlug, '?')) {
                    $selectors[] = sprintf('#adminmenu a[href*="%s"]', $rawSlug);
                    $selectors[] = sprintf('#adminmenu a[href$="%s"]', $rawSlug);
                    $selectors[] = sprintf('#adminmenu li:has(> a[href*="%s"])', $rawSlug);
                    $selectors[] = sprintf('#adminmenu li:has(> a[href$="%s"])', $rawSlug);
                    continue;
                }

                $selectors[] = sprintf('#adminmenu a[href*="page=%s"]', $rawSlug);
                $selectors[] = sprintf('#adminmenu a[href$="%s"]', $rawSlug);
                $selectors[] = sprintf('#adminmenu li:has(> a[href*="page=%s"])', $rawSlug);
                $selectors[] = sprintf('#adminmenu li:has(> a[href$="%s"])', $rawSlug);
                $selectors[] = sprintf('li[data-slug="%s"]', $rawSlug);
                $selectors[] = sprintf('[data-menu-slug="%s"]', $rawSlug);
            }
            if ($selectors !== []) {
                $uniqueSelectors = array_values(array_unique($selectors));
                $this->echoHtml(sprintf(
                    '<style id="wp-admin-control-sidebar-menu">%s { display: none !important; }</style>',
                    implode(', ', $uniqueSelectors)
                ));
            }
        }

        $removedTopbarNodes = $this->getRemovedTopbarNodeIdsForCurrentUser();
        if ($removedTopbarNodes !== []) {
            $topbarSelectors = [];
            foreach ($removedTopbarNodes as $nodeId) {
                if (preg_match('/^[a-zA-Z0-9_\-]+$/', $nodeId)) {
                    $topbarSelectors[] = '#wp-admin-bar-' . $nodeId;
                }
            }
            if ($topbarSelectors !== []) {
                $this->echoHtml(sprintf(
                    '<style id="wp-admin-control-hide-topbar-nodes">%s { display: none !important; }</style>',
                    implode(', ', $topbarSelectors)
                ));
            }
        }

        $hiddenMetaBoxes = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($hiddenMetaBoxes !== []) {
            $metaBoxSelectors = [];
            foreach ($hiddenMetaBoxes as $target) {
                foreach ($this->resolveMetaBoxTargetIds($target) as $boxId) {
                    if (preg_match('/^[a-zA-Z0-9_\-]+$/', $boxId)) {
                        $metaBoxSelectors[] = '#' . $boxId;
                    }
                }
                $norm = strtolower(trim($target));
                if ($norm === 'author' || $norm === 'authordiv') {
                    $metaBoxSelectors[] = '.editor-post-author';
                    $metaBoxSelectors[] = '.edit-post-post-author';
                    $metaBoxSelectors[] = '.editor-post-panel__row:has(.editor-post-author)';
                } elseif (
                    $norm === 'template'
                    || $norm === 'page_attributes'
                    || $norm === 'page-attributes'
                    || $norm === 'pageparentdiv'
                ) {
                    $metaBoxSelectors[] = '.editor-post-template';
                    $metaBoxSelectors[] = '.editor-page-attributes__template';
                    $metaBoxSelectors[] = '.edit-post-post-template';
                    $metaBoxSelectors[] = '.editor-post-panel__row:has(.editor-post-template)';
                }

                $panelId = $this->resolveGutenbergPanelId($target);
                if ($panelId !== null) {
                    if ($panelId === 'taxonomy-panel-post_tag') {
                        $metaBoxSelectors[] = '.components-panel__body:has(#editor-post-taxonomies-label-post_tag)';
                        $metaBoxSelectors[] = '.components-panel__body:has(.components-form-token-field__label)';
                    } elseif ($panelId === 'taxonomy-panel-category') {
                        $metaBoxSelectors[] = '.components-panel__body:has(#editor-post-taxonomies-label-category)';
                        $metaBoxSelectors[] = '.components-panel__body:has(.editor-post-taxonomies__terms-list)';
                    } elseif ($panelId === 'featured-image') {
                        $metaBoxSelectors[] = '.components-panel__body:has(.editor-post-featured-image)';
                    } elseif ($panelId === 'post-excerpt') {
                        $metaBoxSelectors[] = '.components-panel__body:has(.editor-post-excerpt)';
                    } elseif ($panelId === 'discussion-panel') {
                        $metaBoxSelectors[] = '.components-panel__body:has(.editor-post-discussion)';
                    }
                }
            }
            if ($metaBoxSelectors !== []) {
                $this->echoHtml(sprintf(
                    '<style id="wp-admin-control-hide-meta-boxes">%s { display: none !important; }</style>',
                    implode(', ', array_values(array_unique($metaBoxSelectors)))
                ));
            }
        }
    }

    public function handleWpHead(): void
    {
        if (!$this->isUserLoggedIn()) {
            return;
        }

        $this->echoHtml(
            '<style id="wp-admin-control-topbar-custom-icon">'
            . '#wpadminbar .ab-top-menu > li.menupop .ab-icon.dashicons,'
            . ' #wpadminbar .ab-top-menu > li.menupop .ab-icon.dashicons:before {'
            . ' font-size: 16px !important; width: 16px !important; height: 16px !important;'
            . ' line-height: 18px !important; vertical-align: middle !important;'
            . ' margin-right: 4px !important; }'
            . '</style>'
        );

        $removedTopbarNodes = $this->getRemovedTopbarNodeIdsForCurrentUser();
        $topbarSelectors = [];
        foreach ($removedTopbarNodes as $nodeId) {
            if (preg_match('/^[a-zA-Z0-9_\-]+$/', $nodeId)) {
                $topbarSelectors[] = '#wp-admin-bar-' . $nodeId;
            }
        }

        $queriedId = $this->getQueriedObjectId();
        if ($queriedId > 0 && $this->isPostRestrictedForCurrentUser($queriedId)) {
            $topbarSelectors[] = '#wp-admin-bar-edit';
        }

        if ($topbarSelectors !== []) {
            $this->echoHtml(sprintf(
                '<style id="wp-admin-control-hide-topbar-nodes">%s { display: none !important; }</style>',
                implode(', ', array_values(array_unique($topbarSelectors)))
            ));
        }
    }

    public function handleSidebarSeparators(): void
    {
        $raw = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER');
        if ($raw === false || trim($raw) === '') {
            return;
        }

        $menu = &$GLOBALS['menu'];
        if (!is_array($menu)) {
            return;
        }

        $targets = [];
        foreach (explode(',', trim($raw)) as $token) {
            $t = trim($token);
            if ($t !== '') {
                $targets[] = $t;
            }
        }

        if ($targets === []) {
            return;
        }

        $separatorIndex = 1;
        foreach ($targets as $target) {
            $foundPos = null;
            $matchedSlug = '';
            foreach ($menu as $pos => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rawSlug = $item[2] ?? '';
                $slug = is_scalar($rawSlug) ? (string) $rawSlug : '';
                $rawHook = $item[5] ?? '';
                $hook = is_scalar($rawHook) ? (string) $rawHook : '';
                if ($slug === $target || $hook === $target) {
                    $foundPos = $pos;
                    $matchedSlug = $slug;
                    break;
                }
            }

            if ($foundPos === null || $matchedSlug === '') {
                continue;
            }

            $basePos = is_numeric($foundPos) ? (float) $foundPos : 100.0;
            $newPos = $basePos + 0.1;
            while (isset($menu[(string) $newPos])) {
                $newPos += 0.01;
            }

            $separatorSlug = 'separator-custom-' . $separatorIndex;
            $menu[(string) $newPos] = ['', 'read', $separatorSlug, '', 'wp-menu-separator'];
            $this->separatorAfterMap[$matchedSlug] = $separatorSlug;
            $separatorIndex++;
        }
    }

    public function handleSidebarMenuOrder(): void
    {
        $raw = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_ORDER');
        if ($raw === false || trim($raw) === '') {
            return;
        }

        $menu = &$GLOBALS['menu'];
        if (!is_array($menu)) {
            return;
        }

        /** @var list<array{target: string, pos: float}> $rules */
        $rules = [];
        foreach (explode(',', trim($raw)) as $chunk) {
            $parts = explode(':', trim($chunk), 2);
            if (count($parts) === 2 && is_numeric(trim($parts[1]))) {
                $target = trim($parts[0]);
                if ($target !== '') {
                    $rules[] = [
                        'target' => $target,
                        'pos' => (float) trim($parts[1]),
                    ];
                }
            }
        }

        if ($rules === []) {
            return;
        }

        /** @var list<array{pos: float, item: mixed}> $explicit */
        $explicit = [];
        foreach ($rules as $rule) {
            $target = $rule['target'];
            foreach ($menu as $posKey => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rawSlug = $item[2] ?? '';
                $slug = is_scalar($rawSlug) ? (string) $rawSlug : '';
                $rawHook = $item[5] ?? '';
                $hook = is_scalar($rawHook) ? (string) $rawHook : '';

                if ($slug === $target || $hook === $target) {
                    unset($menu[$posKey]);
                    $explicit[] = [
                        'pos' => $rule['pos'],
                        'item' => $item,
                    ];
                    break;
                }
            }
        }

        if ($explicit === []) {
            return;
        }

        /** @var list<array{basePos: float, item: mixed}> $displacedQueue */
        $displacedQueue = [];
        /** @var array<string, bool> $assignedKeys */
        $assignedKeys = [];

        foreach ($explicit as $exp) {
            $pos = $exp['pos'];
            $offset = 0;
            do {
                $key = ($offset === 0 && (float) (int) $pos === $pos) ? (int) $pos : sprintf('%.2f', $pos + ($offset * 0.01));
                $offset++;
            } while (isset($assignedKeys[(string) $key]));

            $assignedKeys[(string) $key] = true;

            if (isset($menu[$key])) {
                $displacedQueue[] = [
                    'basePos' => $pos,
                    'item' => $menu[$key],
                ];
                unset($menu[$key]);
            }

            $menu[$key] = $exp['item'];
        }

        foreach ($displacedQueue as $disp) {
            $pos = $disp['basePos'];
            $offset = 1;
            do {
                $key = sprintf('%.2f', $pos + ($offset * 0.01));
                $offset++;
            } while (isset($menu[$key]) || isset($assignedKeys[(string) $key]));

            $assignedKeys[(string) $key] = true;
            $menu[$key] = $disp['item'];
        }

        ksort($menu, SORT_NUMERIC);
    }

    public function shouldHideWelcomePanel(?object $user): bool
    {
        $raw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE');
        if ($raw === false || trim($raw) === '') {
            return false;
        }

        $rules = $this->parseRules(trim($raw));
        foreach ($rules as $rule) {
            if (in_array('welcome-panel', $rule['targets'], true) && $this->isUserMatchingCosmeticRule($rule, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the custom administrative footer string for the current audience context.
     */
    public function getCustomFooterText(string $envName, ?object $user = null): ?string
    {
        $raw = $this->getEnv($envName);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        if ($currentUser === null) {
            return null;
        }

        $statements = explode(';', trim($raw));
        foreach ($statements as $statement) {
            $trimmedStatement = trim($statement);
            if ($trimmedStatement === '') {
                continue;
            }

            $segments = explode('|', $trimmedStatement);
            $value = trim($segments[0]);
            if ($value === '') {
                continue;
            }

            $modifiers = array_slice($segments, 1);
            $dummyRuleStr = 'footer|' . ($modifiers !== [] ? implode('|', $modifiers) : 'all');
            $rules = $this->parseRules($dummyRuleStr);
            if ($rules === []) {
                continue;
            }

            if ($this->isUserMatchingCosmeticRule($rules[0], $currentUser)) {
                return strtolower($value) === 'remove' ? '' : $value;
            }
        }

        return null;
    }

    /**
     * Filters the left administrative footer text for audience-specific customization.
     */
    public function filterAdminFooterText(string $defaultText): string
    {
        $custom = $this->getCustomFooterText('WP_ADMIN_FOOTER_LEFT');

        return $custom ?? $defaultText;
    }

    /**
     * Filters the right administrative footer version string for audience-specific customization.
     */
    public function filterUpdateFooter(string $defaultText): string
    {
        $custom = $this->getCustomFooterText('WP_ADMIN_FOOTER_RIGHT');

        return $custom ?? $defaultText;
    }

    /**
     * Determines whether the outer footer container element should be visually collapsed.
     */
    public function shouldHideAdminFooterContainer(?object $user = null): bool
    {
        $currentUser = $user ?? $this->getCurrentUser();
        if ($currentUser === null) {
            return false;
        }

        return $this->getCustomFooterText('WP_ADMIN_FOOTER_LEFT', $currentUser) === ''
            && $this->getCustomFooterText('WP_ADMIN_FOOTER_RIGHT', $currentUser) === '';
    }

    public function shouldHideAdminNotices(?object $user = null): bool
    {
        $raw = $this->getEnv('WP_ADMIN_HIDE_NOTICES');
        if ($raw === false || trim($raw) === '') {
            return false;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = $this->parseRules('notices|hide:' . trim($raw));

        return $rules !== [] && $this->isUserMatchingCosmeticRule($rules[0], $currentUser);
    }

    public function handleAdminPrintScripts(): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->shouldHideAdminNotices($currentUser)) {
            $this->removeAllActions('admin_notices');
            $this->removeAllActions('all_admin_notices');
            $this->removeAllActions('user_admin_notices');
            $this->removeAllActions('network_admin_notices');
            $this->dequeueStyle('wp-pointer');
            $this->dequeueScript('wp-pointer');
        }
    }

    public function handleAdminEnqueueScripts(): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->shouldHideAdminNotices($currentUser)) {
            $this->dequeueStyle('wp-pointer');
            $this->dequeueScript('wp-pointer');
        }
    }

    /**
     * @return list<string>
     */
    public function getGutenbergBlocksToRemoveForUser(?object $user = null): array
    {
        $raw = $this->getEnv('WP_ADMIN_GUTENBERG_BLOCKS_REMOVE');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = $this->parseRules($raw);
        $blocks = [];
        foreach ($rules as $rule) {
            if ($this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = strtolower(trim($target));
                    if ($trimmed === '') {
                        continue;
                    }
                    if (str_starts_with($trimmed, 'category:')) {
                        $cat = substr($trimmed, 9);
                        $blocks[] = 'category:' . $cat;
                        if (isset(self::STANDARD_GUTENBERG_CATEGORIES[$cat])) {
                            foreach (self::STANDARD_GUTENBERG_CATEGORIES[$cat] as $catBlock) {
                                $blocks[] = $catBlock;
                                $blocks[] = substr($catBlock, 5);
                            }
                        }
                        continue;
                    }
                    if (isset(self::STANDARD_GUTENBERG_BLOCK_ALIASES[$trimmed])) {
                        $canonical = self::STANDARD_GUTENBERG_BLOCK_ALIASES[$trimmed];
                        $blocks[] = $canonical;
                        $blocks[] = substr($canonical, 5);
                        $blocks[] = $trimmed;
                        continue;
                    }
                    $normalizedNoSlash = str_replace('/', '', $trimmed);
                    if (isset(self::STANDARD_GUTENBERG_BLOCK_ALIASES[$normalizedNoSlash])) {
                        $canonical = self::STANDARD_GUTENBERG_BLOCK_ALIASES[$normalizedNoSlash];
                        $blocks[] = $canonical;
                        $blocks[] = substr($canonical, 5);
                        $blocks[] = $trimmed;
                        continue;
                    }
                    $blocks[] = $trimmed;
                    if (!str_contains($trimmed, '/') && !str_contains($trimmed, '*')) {
                        $blocks[] = 'core/' . $trimmed;
                    } elseif (str_starts_with($trimmed, 'core/')) {
                        $blocks[] = substr($trimmed, 5);
                    }
                }
            }
        }

        return array_values(array_unique($blocks));
    }

    /**
     * @param bool|array<int|string, mixed> $allowedBlockTypes
     * @param mixed $blockEditorContext
     * @return bool|list<string>
     */
    public function filterAllowedBlockTypesAll(bool|array $allowedBlockTypes, mixed $blockEditorContext = null): bool|array
    {
        $currentUser = $this->getCurrentUser();
        $blocksToRemove = $this->getGutenbergBlocksToRemoveForUser($currentUser);
        if ($blocksToRemove === []) {
            if (is_bool($allowedBlockTypes)) {
                return $allowedBlockTypes;
            }
            $result = [];
            foreach ($allowedBlockTypes as $item) {
                if (is_string($item)) {
                    $result[] = $item;
                }
            }
            return $result;
        }

        if ($allowedBlockTypes === false) {
            return false;
        }

        if ($allowedBlockTypes === true) {
            $registered = $this->getRegisteredBlockTypes();
            if ($registered === []) {
                return true;
            }
            $allowedBlockTypes = $registered;
        }

        $categoriesToRemove = [];
        $wildcardsToRemove = [];
        $exactToRemove = [];

        foreach ($blocksToRemove as $blockTarget) {
            if (str_starts_with($blockTarget, 'category:')) {
                $categoriesToRemove[] = substr($blockTarget, 9);
            } elseif (str_contains($blockTarget, '*')) {
                $wildcardsToRemove[] = $blockTarget;
            } else {
                $exactToRemove[] = $blockTarget;
            }
        }

        $cleanAllowed = [];
        foreach ($allowedBlockTypes as $item) {
            if (!is_string($item)) {
                continue;
            }
            $lowerItem = strtolower(trim($item));
            if (in_array($lowerItem, $exactToRemove, true)) {
                continue;
            }
            $matchedWildcard = false;
            foreach ($wildcardsToRemove as $pattern) {
                if (fnmatch($pattern, $lowerItem)) {
                    $matchedWildcard = true;
                    break;
                }
            }
            if ($matchedWildcard) {
                continue;
            }
            if ($categoriesToRemove !== [] && class_exists('WP_Block_Type_Registry')) {
                $blockType = \WP_Block_Type_Registry::get_instance()->get_registered($item);
                if (
                    $blockType !== null
                    && in_array(strtolower((string) ($blockType->category ?? '')), $categoriesToRemove, true)
                ) {
                    continue;
                }
            }
            $cleanAllowed[] = $item;
        }

        return $cleanAllowed;
    }

    /**
     * @return list<string>
     */
    public function getProfileFieldsToRemoveForUser(?object $user = null): array
    {
        $raw = $this->getEnv('WP_ADMIN_PROFILE_FIELDS_REMOVE');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = $this->parseRules($raw);
        $fields = [];
        foreach ($rules as $rule) {
            if ($this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = strtolower(trim($target));
                    if ($trimmed !== '') {
                        $fields[] = $trimmed;
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, string> $methods
     * @param ?object $user
     * @return array<string, string>
     */
    public function filterUserContactMethods(array $methods, ?object $user = null): array
    {
        $currentUser = $user ?? $this->getCurrentUser();
        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if ($fieldsToRemove === []) {
            return $methods;
        }

        foreach ($fieldsToRemove as $field) {
            unset($methods[$field]);
            if ($field === 'website') {
                unset($methods['url']);
            }
            if ($field === 'url') {
                unset($methods['website']);
            }
            foreach (array_keys($methods) as $key) {
                if (strtolower($key) === $field) {
                    unset($methods[$key]);
                }
            }
        }

        return $methods;
    }

    public function filterShowAdminBar(bool $show): bool
    {
        $currentUser = $this->getCurrentUser();
        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if (in_array('toolbar', $fieldsToRemove, true) || in_array('admin_bar', $fieldsToRemove, true)) {
            return false;
        }

        return $show;
    }

    public function startProfileOutputBuffering(): void
    {
        if ($this->isProfileBufferingStarted) {
            return;
        }

        $this->isProfileBufferingStarted = true;
        ob_start([$this, 'filterProfilePageBuffer']);
    }

    public function filterProfilePageBuffer(string $buffer): string
    {
        $currentUser = $this->getCurrentUser();
        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if ($fieldsToRemove === []) {
            return $buffer;
        }

        return $this->removeProfileFieldsFromHtml($buffer, $fieldsToRemove);
    }

    /**
     * @param list<string> $fieldsToRemove
     */
    public function removeProfileFieldsFromHtml(string $buffer, array $fieldsToRemove): string
    {
        if ($fieldsToRemove === []) {
            return $buffer;
        }

        if (!preg_match('/(<form\b[^>]*\bid=["\'](?:your-profile|createuser)["\'][^>]*>)(.*?)(<\/form>)/is', $buffer, $formMatch)) {
            return $buffer;
        }

        $formOpen = $formMatch[1];
        $formInner = $formMatch[2];
        $formClose = $formMatch[3];

        if (trim($formInner) === '') {
            return $buffer;
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $encoded = mb_encode_numericentity($formInner, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $doc->loadHTML('<?xml encoding="utf-8" ?><body><div id="__wp_form_root">' . $encoded . '</div></body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $root = $doc->getElementById('__wp_form_root');
        if (!$root instanceof \DOMElement) {
            return $buffer;
        }

        foreach ($fieldsToRemove as $target) {
            $target = strtolower(trim($target));
            if ($target === '') {
                continue;
            }

            $queries = $this->buildProfileFieldXPathQueries($target);
            foreach ($queries as $q) {
                $nodes = $xpath->query($q, $root);
                if ($nodes instanceof \DOMNodeList) {
                    foreach ($nodes as $node) {
                        if (!$node instanceof \DOMElement || !$node->parentNode instanceof \DOMNode) {
                            continue;
                        }
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }

        $tables = $xpath->query('.//table', $root);
        if ($tables instanceof \DOMNodeList) {
            foreach ($tables as $table) {
                if (!$table instanceof \DOMElement) {
                    continue;
                }
                $rows = $xpath->query('.//tr', $table);
                if ($rows instanceof \DOMNodeList && $rows->length === 0) {
                    $prev = $table->previousSibling;
                    while ($prev !== null) {
                        $curr = $prev;
                        $prev = $prev->previousSibling;
                        if ($curr instanceof \DOMElement) {
                            if (preg_match('/^h[1-6]$/i', $curr->nodeName)) {
                                if ($curr->parentNode instanceof \DOMNode) {
                                    $curr->parentNode->removeChild($curr);
                                }
                            }
                            break;
                        }
                    }
                    if ($table->parentNode instanceof \DOMNode) {
                        $table->parentNode->removeChild($table);
                    }
                }
            }
        }

        $containers = $xpath->query('.//*[@id="wfls-user-settings" or @id="yoast-seo-settings" or contains(concat(" ", normalize-space(@class), " "), " application-passwords ")]', $root);
        if ($containers instanceof \DOMNodeList) {
            foreach ($containers as $c) {
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                $childRows = $xpath->query('.//tr', $c);
                if ($childRows instanceof \DOMNodeList && $childRows->length === 0) {
                    $prev = $c->previousSibling;
                    while ($prev !== null) {
                        $curr = $prev;
                        $prev = $prev->previousSibling;
                        if ($curr instanceof \DOMElement) {
                            if (preg_match('/^h[1-6]$/i', $curr->nodeName)) {
                                if ($curr->parentNode instanceof \DOMNode) {
                                    $curr->parentNode->removeChild($curr);
                                }
                            }
                            break;
                        }
                    }
                    if ($c->parentNode instanceof \DOMNode) {
                        $c->parentNode->removeChild($c);
                    }
                }
            }
        }

        $newInner = '';
        foreach ($root->childNodes as $child) {
            $saved = $doc->saveHTML($child);
            if (is_string($saved)) {
                $newInner .= $saved;
            }
        }

        $decodedInner = mb_decode_numericentity($newInner, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        return str_replace($formMatch[0], $formOpen . $decodedInner . $formClose, $buffer);
    }

    public function handleProfilePostProtection(?object $currentUser, string $pagenow): void
    {
        if ($currentUser === null) {
            return;
        }

        $currentUserId = (int) ($currentUser->ID ?? 0);
        if ($currentUserId <= 0) {
            return;
        }

        $targetUserId = 0;
        if ($pagenow === 'profile.php') {
            $targetUserId = $currentUserId;
        } elseif ($pagenow === 'user-edit.php') {
            $rawId = $_POST['user_id'] ?? $_GET['user_id'] ?? 0;
            $targetUserId = is_numeric($rawId) ? (int) $rawId : 0;
        }

        if ($targetUserId <= 0) {
            return;
        }

        $isEditingSelf = ($targetUserId === $currentUserId);
        if (!$isEditingSelf) {
            return;
        }

        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if ($fieldsToRemove === []) {
            return;
        }

        $this->enforceProfilePostProtection($currentUser, $fieldsToRemove);
    }

    /**
     * @param list<string> $fieldsToRemove
     */
    public function enforceProfilePostProtection(object $currentUser, array $fieldsToRemove): void
    {
        if (in_array('email', $fieldsToRemove, true) || in_array('user_email', $fieldsToRemove, true)) {
            $currentEmail = (string) ($currentUser->user_email ?? '');
            $_POST['email'] = $currentEmail;
            $_REQUEST['email'] = $currentEmail;
        }

        if (in_array('nickname', $fieldsToRemove, true)) {
            $currentNickname = (string) ($currentUser->nickname ?? ($currentUser->display_name ?? ''));
            if ($currentNickname === '') {
                $currentNickname = (string) ($currentUser->user_login ?? '');
            }
            $_POST['nickname'] = $currentNickname;
            $_REQUEST['nickname'] = $currentNickname;
        }

        if (in_array('toolbar', $fieldsToRemove, true) || in_array('admin_bar', $fieldsToRemove, true) || in_array('admin_bar_front', $fieldsToRemove, true)) {
            $_POST['admin_bar_front'] = '0';
            $_REQUEST['admin_bar_front'] = '0';
        }

        foreach ($fieldsToRemove as $target) {
            if (in_array($target, ['email', 'user_email', 'nickname', 'toolbar', 'admin_bar', 'admin_bar_front'], true)) {
                continue;
            }
            $cleanTarget = preg_replace('/[^a-z0-9_-]/', '', $target) ?? '';
            if ($cleanTarget !== '') {
                unset($_POST[$cleanTarget], $_REQUEST[$cleanTarget]);
                if ($cleanTarget === 'website') {
                    unset($_POST['url'], $_REQUEST['url']);
                }
                if ($cleanTarget === 'url') {
                    unset($_POST['website'], $_REQUEST['website']);
                }
                if ($cleanTarget === 'bio') {
                    unset($_POST['description'], $_REQUEST['description']);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function buildProfileFieldXPathQueries(string $target): array
    {
        if (in_array($target, ['user_login', 'user-login', 'username'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-user-login-wrap ")]',
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-login-wrap ")]',
                './/tr[.//input[@id="user_login" or @name="user_login"]]',
                './/tr[.//label[@for="user_login"]]',
            ];
        }

        if (in_array($target, ['url', 'website'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-url-wrap ")]',
                './/tr[.//input[@id="url" or @name="url"]]',
                './/tr[.//label[@for="url"]]',
            ];
        }

        if (in_array($target, ['description', 'bio'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-description-wrap ")]',
                './/tr[contains(@class, "user-description-")]',
                './/tr[.//textarea[starts-with(@name, "description")]]',
                './/tr[.//label[starts-with(@for, "description")]]',
            ];
        }

        if (in_array($target, ['profile_picture', 'avatar', 'profile-picture'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-profile-picture-wrap ")]',
            ];
        }

        if (in_array($target, ['about', 'about_yourself', 'about-yourself'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-description-wrap ")]',
                './/tr[contains(@class, "user-description-")]',
                './/tr[.//textarea[starts-with(@name, "description")]]',
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-profile-picture-wrap ")]',
            ];
        }

        if (in_array($target, ['wordfence', 'wfls', 'wordfence_2fa', 'wordfence_passkeys'], true)) {
            return [
                './/*[@id="wfls-user-settings"]',
                './/tr[@id="wfls-user-2fa-status"]',
                './/tr[@id="wfls-user-passkey-status"]',
                './/tr[contains(@class, "user-wordfence-ls")]',
            ];
        }

        $sanitized = preg_replace('/[^a-z0-9_-]/', '', $target) ?? '';
        if ($sanitized === '') {
            return [];
        }

        return [
            './/tr[contains(concat(" ", normalize-space(@class), " "), " user-' . $sanitized . '-wrap ")]',
            './/tr[contains(concat(" ", normalize-space(@class), " "), " ' . $sanitized . '-wrap ")]',
            './/tr[@id="' . $sanitized . '"]',
            './/tr[.//input[@id="' . $sanitized . '" or @name="' . $sanitized . '"]]',
            './/tr[.//textarea[@id="' . $sanitized . '" or @name="' . $sanitized . '"]]',
            './/tr[.//select[@id="' . $sanitized . '" or @name="' . $sanitized . '"]]',
            './/tr[.//label[@for="' . $sanitized . '"]]',
            './/*[@id="' . $sanitized . '"]',
        ];
    }

    /**
     * @return list<string>
     */
    public function getSidebarWidgetsToRemoveForUser(?object $user = null): array
    {
        $raw = $this->getEnv('WP_ADMIN_SIDEBAR_WIDGETS_REMOVE');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = $this->parseRules($raw);
        $widgets = [];
        foreach ($rules as $rule) {
            if ($this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = trim($target);
                    if ($trimmed !== '') {
                        $widgets[] = $trimmed;
                        $norm = str_replace('-', '_', strtolower($trimmed));
                        if (isset(self::STANDARD_WIDGETS_MAP[$norm])) {
                            $widgets[] = self::STANDARD_WIDGETS_MAP[$norm];
                        }
                    }
                }
            }
        }

        return array_values(array_unique($widgets));
    }

    public function handleWidgetsInit(): void
    {
        $currentUser = $this->getCurrentUser();
        $widgetsToRemove = $this->getSidebarWidgetsToRemoveForUser($currentUser);
        foreach ($widgetsToRemove as $widget) {
            $this->unregisterWidget($widget);
        }
    }

    /**
     * @return list<string>
     */
    public function getTinyMceButtonsToRemoveForUser(?object $user = null): array
    {
        $raw = $this->getEnv('WP_ADMIN_TINYMCE_BUTTONS_REMOVE');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = $this->parseRules($raw);
        $buttons = [];
        foreach ($rules as $rule) {
            if ($this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = strtolower(trim($target));
                    if ($trimmed !== '') {
                        $buttons[] = $trimmed;
                    }
                }
            }
        }

        return array_values(array_unique($buttons));
    }

    /**
     * @param array<int|string, mixed> $buttons
     * @param string|null $editorId
     * @return list<string>
     */
    public function filterMceButtons(array $buttons, ?string $editorId = null): array
    {
        $currentUser = $this->getCurrentUser();
        $buttonsToRemove = $this->getTinyMceButtonsToRemoveForUser($currentUser);
        if ($buttonsToRemove === []) {
            $result = [];
            foreach ($buttons as $button) {
                if (is_string($button)) {
                    $result[] = $button;
                }
            }
            return $result;
        }

        $filtered = [];
        foreach ($buttons as $button) {
            if (!is_string($button)) {
                continue;
            }
            $trimmed = trim($button);
            if ($trimmed === '') {
                continue;
            }
            if (!in_array(strtolower($trimmed), $buttonsToRemove, true)) {
                $filtered[] = $trimmed;
            }
        }

        return $filtered;
    }

    public function isGoogleFontsDisabled(): bool
    {
        $raw = $this->getEnv('WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS');
        if ($raw === false || trim($raw) === '') {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['true', '1', 'on', 'yes'], true);
    }

    public function shouldHideSuperadminPosts(): bool
    {
        $raw = $this->getEnv('WP_ADMIN_HIDE_SUPERADMIN_POSTS');
        if ($raw === false || trim($raw) === '') {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['true', '1', 'on', 'yes'], true);
    }

    public function handleDisableGoogleFontsInit(): void
    {
        $this->removeAction('admin_head', 'wp_resource_hints', 1);
    }

    public function handleDisableGoogleFontsHead(): void
    {
        $this->removeAction('admin_head', 'wp_resource_hints', 1);
        $this->stripGoogleFontsStyles();
    }

    public function handleDisableGoogleFontsStyles(): void
    {
        $this->stripGoogleFontsStyles();
    }

    public function stripGoogleFontsStyles(): void
    {
        $wpStyles = $GLOBALS['wp_styles'] ?? null;
        if (!is_object($wpStyles)) {
            return;
        }

        $registered = isset($wpStyles->registered) && is_array($wpStyles->registered)
            ? $wpStyles->registered
            : [];

        foreach ($registered as $handle => $styleObj) {
            if (!is_object($styleObj)) {
                continue;
            }

            $src = isset($styleObj->src) && is_string($styleObj->src) ? $styleObj->src : '';
            if (str_contains($src, 'fonts.googleapis.com') || str_contains($src, 'fonts.gstatic.com')) {
                $handleStr = (string) $handle;
                $this->dequeueStyle($handleStr);
                $this->deregisterStyle($handleStr);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $urls
     * @return array<int, string>
     */
    public function filterResourceHints(array $urls, mixed $relationType = null): array
    {
        $filtered = [];
        foreach ($urls as $url) {
            if (
                is_string($url)
                && !str_contains($url, 'fonts.googleapis.com')
                && !str_contains($url, 'fonts.gstatic.com')
            ) {
                $filtered[] = $url;
            }
        }

        return $filtered;
    }

    public function filterPreGetUsers(object $query): void
    {
        if ($this->isResolvingSuperAdmins || $this->isSuperAdmin($this->getCurrentUser())) {
            return;
        }

        if (!$this->isMethodCallable($query, 'get') || !$this->isMethodCallable($query, 'set')) {
            return;
        }

        $superAdminIds = $this->resolveSuperAdminIds();
        if ($superAdminIds === []) {
            return;
        }

        $existingInclude = $this->callObjectMethod($query, 'get', ['include']);
        if (is_array($existingInclude) && $existingInclude !== []) {
            $cleanedInclude = [];
            foreach ($existingInclude as $item) {
                if (is_numeric($item)) {
                    $itemInt = (int) $item;
                    if (!in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $this->callObjectMethod($query, 'set', ['include', $cleanedInclude === [] ? [0] : $cleanedInclude]);
        } elseif (is_string($existingInclude) && trim($existingInclude) !== '') {
            $cleanedInclude = [];
            foreach (explode(',', $existingInclude) as $item) {
                if (is_numeric(trim($item))) {
                    $itemInt = (int) trim($item);
                    if (!in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $this->callObjectMethod($query, 'set', ['include', $cleanedInclude === [] ? [0] : $cleanedInclude]);
        }

        $existing = $this->callObjectMethod($query, 'get', ['exclude']);
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        } elseif (is_string($existing) && trim($existing) !== '') {
            foreach (explode(',', $existing) as $item) {
                if (is_numeric(trim($item))) {
                    $excludeList[] = (int) trim($item);
                }
            }
        }

        $merged = array_values(array_unique(array_merge($excludeList, $superAdminIds)));
        $this->callObjectMethod($query, 'set', ['exclude', $merged]);
    }

    /**
     * Prevents metadata leakage across administrative user views by subtracting super-administrators
     * possessing corresponding security flags and clamping counts against visible totals.
     *
     * @param array<string, mixed> $views
     * @return array<string, mixed>
     */
    public function filterViewsUsers(array $views): array
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $views;
        }

        $superAdminIds = $this->resolveSuperAdminIds();
        $superAdminCount = count($superAdminIds);
        if ($superAdminCount === 0) {
            return $views;
        }

        $visibleTotalAll = PHP_INT_MAX;
        if (isset($views['all']) && is_string($views['all'])) {
            if (preg_match('/<span class="count">\(([\d,]+)\)<\/span>/', $views['all'], $matches)) {
                $rawAll = (int) str_replace(',', '', $matches[1]);
                $visibleTotalAll = max(0, $rawAll - $superAdminCount);
            }
        } elseif (isset($views['administrator']) && is_string($views['administrator'])) {
            if (preg_match('/<span class="count">\(([\d,]+)\)<\/span>/', $views['administrator'], $matches)) {
                $rawAdmin = (int) str_replace(',', '', $matches[1]);
                $visibleTotalAll = max(0, $rawAdmin - $superAdminCount);
            }
        }

        $superAdminsWith2Fa = $this->countSuperAdminsWithMeta([
            'wfls_2fa_active',
            '_wfls_2fa_secrets',
            '_two_factor_enabled_providers',
            'wp_2fa_enabled',
            'two_factor_status',
            'two_factor_key',
        ]);

        $superAdminsWithPasskey = $this->countSuperAdminsWithMeta([
            '_wfls_passkeys',
            'wfls_passkeys_active',
        ]);

        $superAdminsWithRemembered = $this->countSuperAdminsWithMeta([
            '_wfls_remembered_devices',
        ]);

        $adjusted = [];
        foreach ($views as $key => $html) {
            if (!is_string($html)) {
                $adjusted[$key] = $html;
                continue;
            }

            $adjusted[$key] = (string) preg_replace_callback(
                '/<span class="count">\(([\d,]+)\)<\/span>/',
                function (array $matches) use (
                    $key,
                    $superAdminCount,
                    $visibleTotalAll,
                    $superAdminsWith2Fa,
                    $superAdminsWithPasskey,
                    $superAdminsWithRemembered
                ): string {
                    $currentVal = (int) str_replace(',', '', $matches[1]);
                    $normKey = strtolower($key);

                    if ($normKey === 'all' || $normKey === 'administrator') {
                        $newVal = max(0, $currentVal - $superAdminCount);
                    } elseif (
                        str_contains($normKey, '2fa-inactive')
                        || str_contains($normKey, '2fa_inactive')
                        || str_contains($normKey, 'two-factor-inactive')
                        || str_contains($normKey, 'two_factor_inactive')
                    ) {
                        $superAdminsWithout2Fa = max(0, $superAdminCount - $superAdminsWith2Fa);
                        $newVal = max(0, $currentVal - $superAdminsWithout2Fa);
                        $newVal = min($newVal, $visibleTotalAll);
                    } elseif (
                        str_contains($normKey, 'passkey-inactive')
                        || str_contains($normKey, 'passkey_inactive')
                    ) {
                        $superAdminsWithoutPasskey = max(0, $superAdminCount - $superAdminsWithPasskey);
                        $newVal = max(0, $currentVal - $superAdminsWithoutPasskey);
                        $newVal = min($newVal, $visibleTotalAll);
                    } elseif (
                        str_contains($normKey, '2fa')
                        || str_contains($normKey, 'two-factor')
                        || str_contains($normKey, 'two_factor')
                    ) {
                        $newVal = max(0, $currentVal - $superAdminsWith2Fa);
                        $newVal = min($newVal, $visibleTotalAll);
                    } elseif (
                        str_contains($normKey, 'passkey')
                    ) {
                        $newVal = max(0, $currentVal - $superAdminsWithPasskey);
                        $newVal = min($newVal, $visibleTotalAll);
                    } elseif (
                        str_contains($normKey, 'remembered-devices')
                        || str_contains($normKey, 'remembered_devices')
                    ) {
                        $newVal = max(0, $currentVal - $superAdminsWithRemembered);
                        $newVal = min($newVal, $visibleTotalAll);
                    } else {
                        $newVal = min($currentVal, $visibleTotalAll);
                    }

                    return '<span class="count">(' . number_format($newVal) . ')</span>';
                },
                $html
            );
        }

        return $adjusted;
    }

    /**
     * Strips all row actions when a super-administrator user object is rendered for a non-superadmin.
     *
     * @param array<string, string> $actions
     * @param mixed $user
     * @return array<string, string>
     */
    public function filterUserRowActions(array $actions, mixed $user): array
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $actions;
        }

        if (is_object($user) && $this->isSuperAdmin($user)) {
            return [];
        }

        return $actions;
    }

    /**
     * Suppresses custom column content in user tables to prevent disclosing super-administrator details.
     */
    public function filterManageUsersCustomColumn(string $output, string $columnName, int $userId): string
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $output;
        }

        if (in_array($userId, $this->resolveSuperAdminIds(), true)) {
            return '';
        }

        return $output;
    }

    /**
     * Counts how many super-administrators possess any active security or feature meta keys.
     *
     * @param list<string> $metaKeys
     */
    private function countSuperAdminsWithMeta(array $metaKeys): int
    {
        $superAdminIds = $this->resolveSuperAdminIds();
        if ($superAdminIds === []) {
            return 0;
        }

        $count = 0;
        foreach ($superAdminIds as $adminId) {
            foreach ($metaKeys as $metaKey) {
                $val = $this->getUserMeta($adminId, $metaKey, true);
                if ($this->isMetaValueActive($val)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Evaluates whether a raw user meta value represents an active configuration flag.
     */
    private function isMetaValueActive(mixed $val): bool
    {
        if (is_array($val)) {
            return $val !== [];
        }

        if (is_string($val)) {
            $trimmed = strtolower(trim($val));
            return $trimmed !== '' && $trimmed !== '0' && $trimmed !== 'false' && $trimmed !== 'no';
        }

        if (is_bool($val)) {
            return $val;
        }

        if (is_numeric($val)) {
            return ((int) $val) > 0;
        }

        return $val !== null;
    }

    private function getUserMeta(int $userId, string $key, bool $single = true): mixed
    {
        if (is_callable($this->userMetaGetter)) {
            return ($this->userMetaGetter)($userId, $key, $single);
        }

        if (function_exists('get_user_meta')) {
            return get_user_meta($userId, $key, $single);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $preparedArgs
     * @return array<string, mixed>
     */
    public function filterRestUserQuery(array $preparedArgs, mixed $request = null): array
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $preparedArgs;
        }

        $superAdminIds = $this->resolveSuperAdminIds();
        if ($superAdminIds === []) {
            return $preparedArgs;
        }

        $existingInclude = $preparedArgs['include'] ?? null;
        if (is_array($existingInclude) && $existingInclude !== []) {
            $cleanedInclude = [];
            foreach ($existingInclude as $item) {
                if (is_numeric($item)) {
                    $itemInt = (int) $item;
                    if (!in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $preparedArgs['include'] = $cleanedInclude === [] ? [0] : $cleanedInclude;
        } elseif (is_string($existingInclude) && trim($existingInclude) !== '') {
            $cleanedInclude = [];
            foreach (explode(',', $existingInclude) as $item) {
                if (is_numeric(trim($item))) {
                    $itemInt = (int) trim($item);
                    if (!in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $preparedArgs['include'] = $cleanedInclude === [] ? [0] : $cleanedInclude;
        }

        $existing = $preparedArgs['exclude'] ?? [];
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        }

        $preparedArgs['exclude'] = array_values(array_unique(array_merge($excludeList, $superAdminIds)));
        return $preparedArgs;
    }

    public function filterRestPreDispatch(mixed $result, mixed $server, mixed $request): mixed
    {
        if ($result !== null) {
            return $result;
        }

        if (!$this->isMethodCallable($request, 'get_route')) {
            return $result;
        }

        $rawRoute = $this->callObjectMethod($request, 'get_route');
        $route = is_string($rawRoute) ? $rawRoute : '';
        if (preg_match('#^/wp/v2/users/(\d+)(?:/.*)?$#', $route, $matches) === 1) {
            $targetId = (int) $matches[1];
            if ($targetId > 0) {
                $targetUser = $this->getUserData($targetId);
                if ($targetUser !== null && $this->isSuperAdmin($targetUser)) {
                    $currentUser = $this->getCurrentUser();
                    if (!$this->isSuperAdmin($currentUser)) {
                        return new WP_Error(
                            'rest_user_invalid_id',
                            'Invalid user ID.',
                            ['status' => 404]
                        );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $preparedArgs
     * @return array<string, mixed>
     */
    public function filterRestPostQuery(array $preparedArgs, mixed $request = null): array
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $preparedArgs;
        }

        $superAdminIds = $this->resolveSuperAdminIds();
        if ($superAdminIds !== []) {
            if (isset($preparedArgs['author'])) {
                $rawAuthor = $preparedArgs['author'];
                if (is_array($rawAuthor)) {
                    $cleanedAuthor = [];
                    foreach ($rawAuthor as $item) {
                        if (is_numeric($item)) {
                            $itemInt = (int) $item;
                            if (!in_array($itemInt, $superAdminIds, true)) {
                                $cleanedAuthor[] = $itemInt;
                            }
                        }
                    }
                    $preparedArgs['author'] = $cleanedAuthor === [] ? [0] : $cleanedAuthor;
                } elseif (is_numeric($rawAuthor) && in_array((int) $rawAuthor, $superAdminIds, true)) {
                    $preparedArgs['author'] = [0];
                }
            }

            if ($this->shouldHideSuperadminPosts()) {
                $authorExclude = [];
                if (isset($preparedArgs['author_exclude']) && is_array($preparedArgs['author_exclude'])) {
                    foreach ($preparedArgs['author_exclude'] as $item) {
                        if (is_numeric($item)) {
                            $authorExclude[] = (int) $item;
                        }
                    }
                }
                $preparedArgs['author_exclude'] = array_values(
                    array_unique(array_merge($authorExclude, $superAdminIds))
                );
            }
        }

        $restrictedPostIds = $this->getRestrictedPostIdsForCurrentUser();
        if ($restrictedPostIds === []) {
            return $preparedArgs;
        }

        $existing = $preparedArgs['exclude'] ?? [];
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        }

        $preparedArgs['exclude'] = array_values(array_unique(array_merge($excludeList, $restrictedPostIds)));
        return $preparedArgs;
    }

    public function filterRestPreparePost(mixed $response, mixed $post, mixed $request): mixed
    {
        if (!is_object($response)) {
            return $response;
        }

        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return $response;
        }

        $authorId = 0;
        if (is_object($post) && isset($post->post_author) && is_numeric($post->post_author)) {
            $authorId = (int) $post->post_author;
        }

        if (
            $authorId <= 0
            && isset($response->data)
            && is_array($response->data)
            && isset($response->data['author'])
            && is_numeric($response->data['author'])
        ) {
            $authorId = (int) $response->data['author'];
        }

        if ($authorId > 0 && in_array($authorId, $this->resolveSuperAdminIds(), true)) {
            if (isset($response->data) && is_array($response->data)) {
                $response->data['author'] = 0;
            }
            if ($this->isMethodCallable($response, 'remove_link')) {
                $this->callObjectMethod($response, 'remove_link', ['author']);
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $preparedArgs
      * @return array<string, mixed>
      */
    public function filterRestCommentQuery(array $preparedArgs, mixed $request = null): array
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $preparedArgs;
        }

        $superAdminIds = $this->resolveSuperAdminIds();
        if ($superAdminIds === []) {
            return $preparedArgs;
        }

        if (isset($preparedArgs['author'])) {
            $rawAuthor = $preparedArgs['author'];
            if (is_array($rawAuthor)) {
                $cleanedAuthor = [];
                foreach ($rawAuthor as $item) {
                    if (is_numeric($item)) {
                        $itemInt = (int) $item;
                        if (!in_array($itemInt, $superAdminIds, true)) {
                            $cleanedAuthor[] = $itemInt;
                        }
                    }
                }
                $preparedArgs['author'] = $cleanedAuthor === [] ? [0] : $cleanedAuthor;
            } elseif (is_numeric($rawAuthor) && in_array((int) $rawAuthor, $superAdminIds, true)) {
                $preparedArgs['author'] = [0];
            }
        }

        $existingExclude = $preparedArgs['author_exclude'] ?? [];
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (is_array($existingExclude)) {
            foreach ($existingExclude as $item) {
                if (is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        }

        $preparedArgs['author_exclude'] = array_values(array_unique(array_merge($excludeList, $superAdminIds)));
        return $preparedArgs;
    }

    public function filterRestPrepareComment(mixed $response, mixed $comment, mixed $request): mixed
    {
        if (!is_object($response)) {
            return $response;
        }

        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return $response;
        }

        $authorId = 0;
        if (is_object($comment) && isset($comment->user_id) && is_numeric($comment->user_id)) {
            $authorId = (int) $comment->user_id;
        }

        if (
            $authorId <= 0
            && isset($response->data)
            && is_array($response->data)
            && isset($response->data['author'])
            && is_numeric($response->data['author'])
        ) {
            $authorId = (int) $response->data['author'];
        }

        if ($authorId > 0 && in_array($authorId, $this->resolveSuperAdminIds(), true)) {
            if (isset($response->data) && is_array($response->data)) {
                $response->data['author'] = 0;
            }
            if ($this->isMethodCallable($response, 'remove_link')) {
                $this->callObjectMethod($response, 'remove_link', ['author']);
            }
        }

        return $response;
    }

    public function filterCustomMenuOrder(mixed $custom): bool
    {
        $rawSeps = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER');
        if ($rawSeps !== false && trim($rawSeps) !== '') {
            return true;
        }

        return (bool) $custom;
    }

    /**
     * @param array<int|string, mixed> $menuOrder
     * @return array<int|string, mixed>
     */
    public function filterMenuOrder(array $menuOrder): array
    {
        if ($this->separatorAfterMap !== []) {
            $reordered = $menuOrder;
            foreach ($this->separatorAfterMap as $targetSlug => $sepSlug) {
                $targetPos = array_search($targetSlug, $reordered, true);
                $sepPos = array_search($sepSlug, $reordered, true);
                if ($targetPos !== false) {
                    if ($sepPos !== false) {
                        array_splice($reordered, (int) $sepPos, 1);
                        $targetPos = array_search($targetSlug, $reordered, true);
                    }
                    array_splice($reordered, (int) $targetPos + 1, 0, [$sepSlug]);
                }
            }
            return $reordered;
        }

        return $menuOrder;
    }

    public function filterScreenOptionsShowScreen(mixed $show): bool
    {
        if ($this->shouldHideScreenOptionsAndHelp($this->getCurrentUser())) {
            return false;
        }

        return (bool) $show;
    }

    /**
     * @param array<int|string, mixed> $hidden
     * @return array<int|string, mixed>
     */
    public function filterHiddenMetaBoxes(array $hidden, mixed $screen = null): array
    {
        return $this->applyHiddenList('WP_ADMIN_HIDDEN_META_BOXES', $hidden);
    }

    /**
     * @return list<string>
     */
    public function resolveMetaBoxTargetIds(string $target): array
    {
        $normalized = strtolower(trim($target));
        if ($normalized === '') {
            return [];
        }

        return match ($normalized) {
            'tags', 'post_tag', 'tagsdiv-post_tag' => ['tagsdiv-post_tag', 'post_tag', 'tags'],
            'categories', 'category', 'categorydiv' => ['categorydiv', 'category', 'categories'],
            'featured_image', 'featured-image', 'postimagediv' => [
                'postimagediv',
                'featured-image',
                'featured_image',
            ],
            'excerpt', 'postexcerpt' => ['postexcerpt', 'excerpt'],
            'discussion', 'discussion-panel', 'commentstatusdiv' => [
                'commentstatusdiv',
                'commentsdiv',
                'discussion',
            ],
            'page_attributes', 'page-attributes', 'pageparentdiv', 'template' => [
                'pageparentdiv',
                'page-attributes',
                'template',
            ],
            'slug', 'slugdiv' => ['slugdiv', 'slug'],
            'author', 'authordiv' => ['authordiv', 'author'],
            'revisions', 'revisionsdiv' => ['revisionsdiv', 'revisions'],
            'custom_fields', 'custom-fields', 'postcustom' => ['postcustom', 'custom-fields', 'custom_fields'],
            'yoast', 'yoast-seo', 'wpseo_meta' => ['wpseo_meta', 'yoast-seo', 'yoast'],
            default => [$target],
        };
    }

    public function resolveGutenbergPanelId(string $target): ?string
    {
        $normalized = strtolower(trim($target));
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'taxonomy-panel-')) {
            return $normalized;
        }

        return match ($normalized) {
            'tags', 'post_tag', 'tagsdiv-post_tag' => 'taxonomy-panel-post_tag',
            'categories', 'category', 'categorydiv' => 'taxonomy-panel-category',
            'featured_image', 'featured-image', 'postimagediv' => 'featured-image',
            'excerpt', 'postexcerpt' => 'post-excerpt',
            'discussion', 'discussion-panel', 'commentstatusdiv' => 'discussion-panel',
            'page_attributes', 'page-attributes', 'pageparentdiv', 'template' => 'page-attributes',
            'yoast', 'yoast-seo', 'wpseo_meta' => 'yoast-seo',
            default => null,
        };
    }

    private function enforcePostTypeSupportRestrictions(): void
    {
        $hiddenMetaBoxes = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($hiddenMetaBoxes === []) {
            return;
        }

        $hasAuthor = false;
        $hasTemplate = false;
        foreach ($hiddenMetaBoxes as $target) {
            $norm = strtolower(trim($target));
            if ($norm === 'author' || $norm === 'authordiv') {
                $hasAuthor = true;
            }
            if (
                $norm === 'template'
                || $norm === 'page_attributes'
                || $norm === 'page-attributes'
                || $norm === 'pageparentdiv'
            ) {
                $hasTemplate = true;
            }
        }

        if ($hasAuthor || $hasTemplate) {
            foreach ($this->getSupportedPostTypes() as $postType) {
                if ($hasAuthor) {
                    $this->removePostTypeSupport($postType, 'author');
                }
                if ($hasTemplate) {
                    $this->removePostTypeSupport($postType, 'page-attributes');
                }
            }
        }
    }

    /**
     * Removes target meta boxes from the post edit screen and global registry.
     */
    public function handleMetaBoxesSetup(string $postType = '', ?object $post = null): void
    {
        $toHide = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($toHide === []) {
            return;
        }

        $allTargetIds = [];
        $hasAuthor = false;
        $hasTemplate = false;
        foreach ($toHide as $target) {
            $norm = strtolower(trim($target));
            if ($norm === 'author' || $norm === 'authordiv') {
                $hasAuthor = true;
            }
            if (
                $norm === 'template'
                || $norm === 'page_attributes'
                || $norm === 'page-attributes'
                || $norm === 'pageparentdiv'
            ) {
                $hasTemplate = true;
            }
            foreach ($this->resolveMetaBoxTargetIds($target) as $id) {
                $allTargetIds[] = $id;
            }
        }
        $allTargetIds = array_values(array_unique($allTargetIds));

        if ($hasAuthor || $hasTemplate) {
            $types = $postType !== '' ? [$postType] : $this->getSupportedPostTypes();
            foreach ($types as $type) {
                if ($hasAuthor) {
                    $this->removePostTypeSupport($type, 'author');
                }
                if ($hasTemplate) {
                    $this->removePostTypeSupport($type, 'page-attributes');
                }
            }
        }

        foreach ($allTargetIds as $targetId) {
            if ($postType !== '') {
                $this->removeMetaBox($targetId, $postType, 'normal');
                $this->removeMetaBox($targetId, $postType, 'side');
                $this->removeMetaBox($targetId, $postType, 'advanced');
            }

            $screen = $this->getCurrentScreen();
            if ($screen !== null) {
                $this->removeMetaBox($targetId, $screen, 'normal');
                $this->removeMetaBox($targetId, $screen, 'side');
                $this->removeMetaBox($targetId, $screen, 'advanced');
            }

            if (isset($GLOBALS['wp_meta_boxes']) && is_array($GLOBALS['wp_meta_boxes'])) {
                foreach ($GLOBALS['wp_meta_boxes'] as $pageKey => &$contexts) {
                    if (!is_array($contexts)) {
                        continue;
                    }
                    foreach (['normal', 'side', 'advanced'] as $ctx) {
                        $contextBoxes = $contexts[$ctx] ?? null;
                        if (!is_array($contextBoxes)) {
                            continue;
                        }
                        foreach (['high', 'core', 'default', 'low'] as $prio) {
                            $priorityBoxes = $contextBoxes[$prio] ?? null;
                            if (is_array($priorityBoxes) && array_key_exists($targetId, $priorityBoxes)) {
                                unset($priorityBoxes[$targetId]);
                                $contextBoxes[$prio] = $priorityBoxes;
                                $contexts[$ctx] = $contextBoxes;
                            }
                        }
                    }
                }
                unset($contexts);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getGutenbergPanelsToHideForCurrentUser(): array
    {
        $toHide = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($toHide === []) {
            return [];
        }

        $panels = [];
        foreach ($toHide as $target) {
            $panelId = $this->resolveGutenbergPanelId($target);
            if ($panelId !== null) {
                $panels[] = $panelId;
            }
        }

        return array_values(array_unique($panels));
    }

    /**
     * Enqueues inline JavaScript to deregister panels in Gutenberg.
     */
    public function handleBlockEditorAssets(): void
    {
        $panels = $this->getGutenbergPanelsToHideForCurrentUser();
        if ($panels === []) {
            return;
        }

        $jsonPanels = json_encode($panels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPanels === false) {
            return;
        }

        $script = sprintf(
            'wp.domReady(function(){'
            . 'var d=(wp.data&&wp.data.dispatch)'
            . '?(wp.data.dispatch("core/edit-post")||wp.data.dispatch("core/editor")):null;'
            . 'if(d&&typeof d.removeEditorPanel==="function"){'
            . '%s.forEach(function(p){d.removeEditorPanel(p);});'
            . '}'
            . '});',
            $jsonPanels
        );

        $this->addInlineScript('wp-edit-post', $script);
    }

    /**
     * Outputs fallback script removing Gutenberg panels in the footer.
     */
    public function handleAdminPrintFooterScripts(): void
    {
        $panels = $this->getGutenbergPanelsToHideForCurrentUser();
        if ($panels === []) {
            return;
        }

        $jsonPanels = json_encode($panels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPanels === false) {
            return;
        }

        $this->echoHtml(sprintf(
            '<script id="wp-admin-control-gutenberg-panels">'
            . '(function(){if(window.wp&&wp.domReady&&wp.data&&wp.data.dispatch){'
            . 'wp.domReady(function(){'
            . 'var d=wp.data.dispatch("core/edit-post")||wp.data.dispatch("core/editor");'
            . 'if(d&&typeof d.removeEditorPanel==="function"){'
            . '%s.forEach(function(p){d.removeEditorPanel(p);});'
            . '}'
            . '});'
            . '}})();'
            . '</script>',
            $jsonPanels
        ));
    }

    /**
     * Disables block editor template creation and switching when template controls are restricted.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function filterBlockEditorSettingsAll(array $settings, mixed $context = null): array
    {
        $hiddenMetaBoxes = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($hiddenMetaBoxes === []) {
            return $settings;
        }

        foreach ($hiddenMetaBoxes as $target) {
            $norm = strtolower(trim($target));
            if (
                $norm === 'template'
                || $norm === 'page_attributes'
                || $norm === 'page-attributes'
                || $norm === 'pageparentdiv'
            ) {
                $settings['supportsTemplateMode'] = false;
                break;
            }
        }

        return $settings;
    }

    /**
     * Enforces immutable server-side post author assignment when author controls are restricted.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @param array<string, mixed> $unsanitizedPostarr
     * @return array<string, mixed>
     */
    public function filterInsertPostData(
        array $data,
        array $postarr,
        array $unsanitizedPostarr = [],
        bool $update = false
    ): array {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null || $this->isSuperAdmin($currentUser)) {
            return $data;
        }

        $hiddenMetaBoxes = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($hiddenMetaBoxes === []) {
            return $data;
        }

        $isAuthorHidden = false;
        foreach ($hiddenMetaBoxes as $target) {
            $norm = strtolower(trim($target));
            if ($norm === 'author' || $norm === 'authordiv') {
                $isAuthorHidden = true;
                break;
            }
        }

        if (!$isAuthorHidden) {
            return $data;
        }

        $currentUserId = (int) ($currentUser->ID ?? 0);

        if ($update) {
            $rawPostId = $postarr['ID'] ?? 0;
            $postId = is_numeric($rawPostId) ? (int) $rawPostId : 0;
            if ($postId > 0) {
                $existingPost = $this->getPost($postId);
                if (
                    $existingPost !== null
                    && isset($existingPost->post_author)
                    && is_numeric($existingPost->post_author)
                ) {
                    $data['post_author'] = (string) $existingPost->post_author;
                    return $data;
                }
            }
        }

        if ($currentUserId > 0) {
            $data['post_author'] = (string) $currentUserId;
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $hidden
     * @return array<int|string, mixed>
     */
    public function filterHiddenColumns(array $hidden, mixed $screen = null): array
    {
        return $this->applyHiddenList('WP_ADMIN_HIDDEN_COLUMNS', $hidden);
    }

    /**
     * @param array<int|string, mixed> $hidden
     * @return array<int|string, mixed>
     */
    public function filterDefaultHiddenColumns(array $hidden, mixed $screen = null): array
    {
        return $this->applyHiddenList('WP_ADMIN_HIDDEN_COLUMNS', $hidden);
    }

    /**
     * @param array<string, mixed> $columns
     * @return array<string, mixed>
     */
    public function filterScreenColumns(array $columns): array
    {
        $toHide = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_COLUMNS');
        if ($toHide === []) {
            return $columns;
        }

        foreach ($toHide as $target) {
            unset($columns[$target]);
        }

        return $columns;
    }

    /**
     * @param array<int, string> $caps
     * @param array<int, mixed> $args
     * @return array<int, string>
     */
    public function filterMapMetaCap(array $caps, mixed $cap, int $userId, array $args): array
    {
        if (!is_string($cap) || $cap === '') {
            return $caps;
        }

        if (in_array($cap, self::PROTECTED_USER_CAPABILITIES, true)) {
            $targetUserId = isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : 0;
            if ($targetUserId > 0) {
                $targetUser = $this->getUserData($targetUserId);
                if ($targetUser !== null && $this->isSuperAdmin($targetUser)) {
                    $currentUser = $this->getUserData($userId);
                    if ($currentUser === null || !$this->isSuperAdmin($currentUser)) {
                        return ['do_not_allow'];
                    }
                }
            }
            return $caps;
        }

        if (!in_array($cap, self::PROTECTED_POST_CAPABILITIES, true)) {
            return $caps;
        }

        $targetPostId = isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : 0;
        if ($targetPostId <= 0) {
            return $caps;
        }

        $user = $this->getUserData($userId);
        if ($user === null || $this->isSuperAdmin($user)) {
            return $caps;
        }

        if ($this->isPostRestrictedForUser($targetPostId, $user)) {
            return ['do_not_allow'];
        }

        return $caps;
    }

    public function filterPreGetPosts(object $query): void
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return;
        }

        if (!$this->isAdmin()) {
            $authorVal = $this->callObjectMethod($query, 'get', ['author']);
            $authorId = is_numeric($authorVal) ? (int) $authorVal : 0;
            $rawAuthorName = $this->callObjectMethod($query, 'get', ['author_name']);
            $authorName = is_string($rawAuthorName) ? trim($rawAuthorName) : '';

            $isTargetSuperAdmin = false;
            if ($authorId > 0 && in_array($authorId, $this->resolveSuperAdminIds(), true)) {
                $isTargetSuperAdmin = true;
            } elseif ($authorName !== '') {
                $authorUser = $this->getUserBy('slug', $authorName) ?? $this->getUserBy('login', $authorName);
                if ($authorUser !== null && $this->isSuperAdmin($authorUser)) {
                    $isTargetSuperAdmin = true;
                }
            }

            if ($isTargetSuperAdmin) {
                $this->callObjectMethod($query, 'set_404');
                return;
            }

            if ($this->shouldHideSuperadminPosts()) {
                $superAdminIds = $this->resolveSuperAdminIds();
                if ($superAdminIds !== []) {
                    $existingAuthorNotIn = $this->callObjectMethod($query, 'get', ['author__not_in']);
                    /** @var list<int> $authorNotInList */
                    $authorNotInList = [];
                    if (is_array($existingAuthorNotIn)) {
                        foreach ($existingAuthorNotIn as $item) {
                            if (is_numeric($item)) {
                                $authorNotInList[] = (int) $item;
                            }
                        }
                    }
                    $this->callObjectMethod(
                        $query,
                        'set',
                        ['author__not_in', array_values(array_unique(array_merge($authorNotInList, $superAdminIds)))]
                    );
                }
            }
            return;
        }

        if (!(bool) $this->callObjectMethod($query, 'is_main_query')) {
            return;
        }

        if ($this->getPageNow() !== 'edit.php') {
            return;
        }

        $restrictedPostIds = $this->getRestrictedPostIdsForCurrentUser();
        if ($restrictedPostIds === []) {
            return;
        }

        $existing = $this->callObjectMethod($query, 'get', ['post__not_in']);
        /** @var list<int> $notInList */
        $notInList = [];
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (is_numeric($item)) {
                    $notInList[] = (int) $item;
                }
            }
        }

        $this->callObjectMethod(
            $query,
            'set',
            ['post__not_in', array_values(array_unique(array_merge($notInList, $restrictedPostIds)))]
        );
    }

    public function filterCountPosts(object $counts, string $type, string $perm): object
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $counts;
        }

        if (!$this->isAdmin()) {
            return $counts;
        }

        $restrictedPostIds = $this->getRestrictedPostIdsForCurrentUser();
        if ($restrictedPostIds === []) {
            return $counts;
        }

        $deductions = 0;
        foreach ($restrictedPostIds as $postId) {
            $post = $this->getPost($postId);
            if ($post !== null) {
                $postType = is_string($post->post_type ?? null) ? $post->post_type : 'post';
                if ($postType !== $type) {
                    continue;
                }
                $postStatus = is_string($post->post_status ?? null) ? $post->post_status : 'publish';
                if ($postStatus === 'publish') {
                    $deductions++;
                }
            } else {
                if ($type === 'post') {
                    $deductions++;
                }
            }
        }

        if ($deductions > 0 && isset($counts->publish) && is_numeric($counts->publish)) {
            $counts->publish = (string) max(0, (int) $counts->publish - $deductions);
        }

        return $counts;
    }

    /**
     * Sanitizes post list table view counters to prevent oracle leaks of restricted content.
     *
     * @param array<string, string> $views
     * @return array<string, string>
     */
    public function filterViewsEditPosts(array $views): array
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null || $this->isSuperAdmin($currentUser)) {
            return $views;
        }

        $userId = (int) ($currentUser->ID ?? 0);
        if ($userId <= 0) {
            return $views;
        }

        $restrictedPostIds = $this->getRestrictedPostIdsForCurrentUser();
        if ($restrictedPostIds === []) {
            return $views;
        }

        $screen = $this->getCurrentScreen();
        $postType = is_object($screen) && isset($screen->post_type) && is_string($screen->post_type)
            ? $screen->post_type
            : (isset($_GET['post_type']) && is_string($_GET['post_type']) ? $_GET['post_type'] : 'post');

        $restrictedMineCount = 0;
        foreach ($restrictedPostIds as $postId) {
            $post = $this->getPost($postId);
            if ($post === null) {
                continue;
            }

            $type = isset($post->post_type) ? (string) $post->post_type : '';
            $author = isset($post->post_author) ? (int) $post->post_author : 0;
            $status = isset($post->post_status) ? (string) $post->post_status : '';

            if ($type === $postType && $author === $userId && !in_array($status, ['trash', 'auto-draft'], true)) {
                $restrictedMineCount++;
            }
        }

        if ($restrictedMineCount <= 0) {
            return $views;
        }

        $rawCount = 0;
        if (
            isset($views['mine'])
            && preg_match('/<span class="count">\(([0-9,]+)\)<\/span>/', $views['mine'], $matches) === 1
        ) {
            $rawCount = (int) str_replace(',', '', $matches[1]);
        } else {
            $rawCount = $this->getUserPostsCount($userId, $postType);
        }

        $adjustedCount = max(0, $rawCount - $restrictedMineCount);

        $allCount = null;
        if (
            isset($views['all'])
            && preg_match('/<span class="count">\(([0-9,]+)\)<\/span>/', $views['all'], $allMatches) === 1
        ) {
            $allCount = (int) str_replace(',', '', $allMatches[1]);
        }

        if ($adjustedCount === 0 || ($allCount !== null && $adjustedCount >= $allCount)) {
            unset($views['mine']);
            return $views;
        }

        $isTrash = isset($_GET['post_status']) && $_GET['post_status'] === 'trash';
        $mineUrl = $postType === 'post' ? 'edit.php' : 'edit.php?post_type=' . urlencode($postType);
        if ($isTrash) {
            $mineUrl .= (str_contains($mineUrl, '?') ? '&' : '?') . 'post_status=trash';
        }
        $mineUrl .= (str_contains($mineUrl, '?') ? '&' : '?') . 'author=' . $userId;

        $isCurrent = isset($_GET['author']) && is_numeric($_GET['author']) && (int) $_GET['author'] === $userId;
        $class = $isCurrent ? ' class="current" aria-current="page"' : '';
        $mineLabel = function_exists('_nx')
            ? _nx('Mine', 'Mine', $adjustedCount, 'posts')
            : (function_exists('__') ? __('Mine') : 'Mine');

        $countFormatted = function_exists('number_format_i18n')
            ? number_format_i18n($adjustedCount)
            : number_format($adjustedCount);

        $escapedUrl = function_exists('esc_url')
            ? esc_url($mineUrl)
            : htmlspecialchars($mineUrl, ENT_QUOTES, 'UTF-8');

        $mineHtml = sprintf(
            '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
            $escapedUrl,
            $class,
            $mineLabel,
            $countFormatted
        );

        if (isset($views['mine'])) {
            $views['mine'] = $mineHtml;
            return $views;
        }

        return $this->insertViewAfter($views, 'all', 'mine', $mineHtml);
    }

    /**
     * @param array<string, string> $views
     * @return array<string, string>
     */
    private function insertViewAfter(array $views, string $targetKey, string $newKey, string $newVal): array
    {
        $result = [];
        $inserted = false;
        foreach ($views as $key => $val) {
            $result[$key] = $val;
            if ($key === $targetKey) {
                $result[$newKey] = $newVal;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $result[$newKey] = $newVal;
        }
        return $result;
    }

    protected function getUserPostsCount(int $userId, string $postType): int
    {
        if (is_callable($this->userPostsCountGetter)) {
            return ($this->userPostsCountGetter)($userId, $postType);
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->posts) || !is_string($wpdb->posts)) {
            return 0;
        }

        if ($this->isMethodCallable($wpdb, 'prepare') && $this->isMethodCallable($wpdb, 'get_var')) {
            $sql = $this->callObjectMethod(
                $wpdb,
                'prepare',
                [
                    'SELECT COUNT(1) FROM ' . $wpdb->posts
                    . " WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft') AND post_author = %d",
                    $postType,
                    $userId,
                ]
            );
            if (is_string($sql)) {
                $count = $this->callObjectMethod($wpdb, 'get_var', [$sql]);
                return is_numeric($count) ? (int) $count : 0;
            }
        }

        return 0;
    }

    /**
     * Dynamically registers views filters for custom post types on listing screens.
     */
    public function handleCurrentScreen(object $screen): void
    {
        $screenId = isset($screen->id) && is_string($screen->id) ? $screen->id : '';
        if (str_starts_with($screenId, 'edit-') && $screenId !== 'edit-post' && $screenId !== 'edit-page') {
            $this->addFilter('views_' . $screenId, [$this, 'filterViewsEditPosts'], 10, 1);
        }

        if ($screenId !== '') {
            $this->addFilter('manage_' . $screenId . '_columns', [$this, 'filterScreenColumns'], 999, 1);
        }
    }

    public function handleTemplateRedirect(): void
    {
        if ($this->isAdmin() || $this->isSuperAdmin($this->getCurrentUser())) {
            return;
        }

        if (function_exists('is_author') && is_author()) {
            $authorId = $this->getQueriedObjectId();
            if ($authorId > 0 && in_array($authorId, $this->resolveSuperAdminIds(), true)) {
                if (function_exists('status_header')) {
                    status_header(404);
                }
                if (function_exists('nocache_headers')) {
                    nocache_headers();
                }
                $wpQuery = $GLOBALS['wp_query'] ?? null;
                if ($wpQuery !== null) {
                    $this->callObjectMethod($wpQuery, 'set_404');
                }
            }
        }
    }

    public function filterAuthorLink(string $link, int $authorId, string $authorNicename = ''): string
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $link;
        }

        if (in_array($authorId, $this->resolveSuperAdminIds(), true)) {
            return function_exists('home_url') ? (string) home_url('/') : '/';
        }

        return $link;
    }

    /**
     * @param callable(mixed, string|int, array<string, mixed>): void $defaultHandler
     * @return callable(mixed, string|int, array<string, mixed>): void
     */
    public function filterWpDieHandler(callable $defaultHandler): callable
    {
        return function (mixed $message, string|int $title = '', array $args = []): void {
            $this->renderStealthDie($message, $title, $args);
        };
    }

    /**
     * @param mixed $message
     * @param string|int $title
     * @param array<string, mixed> $args
     */
    public function renderStealthDie(mixed $message, string|int $title = '', array $args = []): void
    {
        $statusCode = isset($args['response']) && is_numeric($args['response']) ? (int) $args['response'] : 403;
        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 403;
        }

        if (function_exists('status_header')) {
            status_header($statusCode);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $rawText = '';
        if ($message instanceof WP_Error) {
            $rawText = $message->get_error_message();
        } elseif (is_scalar($message)) {
            $rawText = (string) $message;
        }

        if (trim($rawText) === '') {
            $rawText = 'Access denied. You do not have permission to view this resource.';
        }

        $escapedText = htmlspecialchars($rawText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleText = is_string($title) && trim($title) !== ''
            ? htmlspecialchars(trim($title), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : (string) $statusCode . ' Forbidden';

        $color = $statusCode === 403 ? '#f59e0b' : '#ef4444';

        $html = sprintf(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>%s</title>'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
            . 'background:#0f172a;color:#f8fafc;display:flex;align-items:center;'
            . 'justify-content:center;height:100vh;margin:0}'
            . '.c{text-align:center;padding:2rem;max-width:420px}'
            . 'h1{font-size:2.5rem;margin:0 0 .5rem;color:%s;font-weight:700}'
            . 'p{font-size:1rem;color:#94a3b8;line-height:1.5;margin:0}</style>'
            . '</head><body><div class="c"><h1>%d</h1><p>%s</p></div></body></html>',
            $titleText,
            $color,
            $statusCode,
            $escapedText
        );

        $this->echoHtml($html);
        $this->terminate();
    }

    public function renderMicroMarkdown(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        $normalized = str_replace(['\r\n', '\r', '\n'], "\n", $markdown);
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        $html = htmlspecialchars($normalized, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = (string) preg_replace(
            '/\[(.*?)\]\((https:\/\/[^\s\)\"\']+)\)/',
            '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
            $html
        );
        $html = (string) preg_replace(
            '/\[(.*?)\]\((mailto:[^\s\)\"\']+)\)/',
            '<a href="$2">$1</a>',
            $html
        );

        $html = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/\*([^\*\n]+?)\*/', '<em>$1</em>', $html);

        $lines = explode("\n", $html);
        $formattedLines = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(#{1,4})\s+(.+)$/u', $trimmed, $headingMatches)) {
                if ($inList) {
                    $formattedLines[] = '</ul>';
                    $inList = false;
                }
                $level = min(4, max(2, strlen($headingMatches[1]) + 1));
                $formattedLines[] = sprintf('<h%d style="margin: 0.5em 0;">%s</h%d>', $level, $headingMatches[2], $level);
                continue;
            }

            if (str_starts_with($trimmed, '- ')) {
                if (!$inList) {
                    $formattedLines[] = '<ul>';
                    $inList = true;
                }
                $formattedLines[] = '<li>' . substr($trimmed, 2) . '</li>';
            } else {
                if ($inList) {
                    $formattedLines[] = '</ul>';
                    $inList = false;
                }
                $formattedLines[] = $line;
            }
        }

        if ($inList) {
            $formattedLines[] = '</ul>';
        }

        $result = implode("\n", $formattedLines);
        return nl2br($result, false);
    }

    private function isCurrentPluginPageRequest(string $target): bool
    {
        $rawPage = $_GET['page'] ?? '';
        if (!is_string($rawPage) || trim($rawPage) === '') {
            return false;
        }

        $currentPage = strtolower(trim($rawPage));
        $rawTarget = str_contains($target, ':') ? explode(':', $target, 2)[1] : $target;
        $rawTarget = trim($rawTarget);

        if (str_starts_with($rawTarget, 'admin.php?page=')) {
            $rawTarget = substr($rawTarget, 15);
        }

        if (str_contains($rawTarget, '?')) {
            $rawTarget = explode('?', $rawTarget, 2)[0];
        }

        $targetSlug = strtolower(trim($rawTarget));
        return $targetSlug !== '' && $currentPage === $targetSlug;
    }

    /**
     * @return array{
     *     hide: list<string>,
     *     block: list<string>
     * }
     */
    private function getSidebarMenuControlForCurrentUser(): array
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            return ['hide' => [], 'block' => []];
        }

        if ($this->cachedSidebarMenuControlRules === null) {
            $raw = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_CONTROL');
            $this->cachedSidebarMenuControlRules = ($raw !== false && trim($raw) !== '')
                ? $this->parseRules(trim($raw))
                : [];
        }

        if ($this->cachedSidebarMenuControlRules === []) {
            return ['hide' => [], 'block' => []];
        }

        /** @var array<string, 'hide'|'block'> $targetActions */
        $targetActions = [];

        foreach ($this->cachedSidebarMenuControlRules as $rule) {
            if ($rule['action'] === 'block') {
                if (!$this->isUserRestrictedByRule($rule, $currentUser)) {
                    continue;
                }
                foreach ($rule['targets'] as $target) {
                    $targetActions[$target] = 'block';
                }
            } else {
                if (!$this->isUserMatchingCosmeticRule($rule, $currentUser)) {
                    continue;
                }
                foreach ($rule['targets'] as $target) {
                    if (!isset($targetActions[$target]) || $targetActions[$target] !== 'block') {
                        $targetActions[$target] = 'hide';
                    }
                }
            }
        }

        $hideTargets = [];
        $blockTargets = [];
        foreach ($targetActions as $target => $action) {
            $hideTargets[] = $target;
            if ($action === 'block') {
                $blockTargets[] = $target;
            }
        }

        return [
            'hide' => array_values(array_unique($hideTargets)),
            'block' => array_values(array_unique($blockTargets)),
        ];
    }

    /**
     * @return list<AdminRule>
     */
    private function getContentAccessRules(): array
    {
        if ($this->cachedContentAccessRules === null) {
            $raw = $this->getEnv('WP_ADMIN_CONTENT_ACCESS_RULES');
            $this->cachedContentAccessRules = ($raw !== false && trim($raw) !== '')
                ? $this->parseRules(trim($raw))
                : [];
        }

        return $this->cachedContentAccessRules;
    }

    private function resolveContentTargetToPostId(string $target): ?int
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        if (array_key_exists($target, $this->resolvedContentTargetIds)) {
            return $this->resolvedContentTargetIds[$target];
        }

        if (str_starts_with($target, 'id:')) {
            $rawId = substr($target, 3);
            $id = ctype_digit($rawId) ? (int) $rawId : 0;
            $res = $id > 0 ? $id : null;
            $this->resolvedContentTargetIds[$target] = $res;
            return $res;
        }

        if (ctype_digit($target)) {
            $id = (int) $target;
            $res = $id > 0 ? $id : null;
            $this->resolvedContentTargetIds[$target] = $res;
            return $res;
        }

        if (is_callable($this->postResolver)) {
            $resolved = ($this->postResolver)($target);
            $res = null;
            if (is_numeric($resolved)) {
                $id = (int) $resolved;
                $res = $id > 0 ? $id : null;
            } elseif (is_object($resolved) && isset($resolved->ID) && is_numeric($resolved->ID)) {
                $id = (int) $resolved->ID;
                $res = $id > 0 ? $id : null;
            }
            $this->resolvedContentTargetIds[$target] = $res;
            return $res;
        }

        $postType = null;
        $slug = $target;
        if (str_starts_with($target, 'slug:')) {
            $slug = substr($target, 5);
        } elseif (str_contains($target, ':')) {
            $parts = explode(':', $target, 2);
            $postType = $parts[0] !== '' ? $parts[0] : null;
            $slug = $parts[1];
        }

        $resolvedId = null;
        if (function_exists('get_posts')) {
            /** @var array<string, mixed> $queryArgs */
            $queryArgs = [
                'name' => $slug,
                'post_status' => 'any',
                'numberposts' => 1,
                'suppress_filters' => false,
            ];
            if ($postType !== null) {
                $queryArgs['post_type'] = $postType;
            } else {
                $queryArgs['post_type'] = 'any';
            }
            $posts = get_posts($queryArgs);
            if (
                is_array($posts)
                && isset($posts[0])
                && is_object($posts[0])
                && isset($posts[0]->ID)
                && is_numeric($posts[0]->ID)
            ) {
                $id = (int) $posts[0]->ID;
                if ($id > 0) {
                    $resolvedId = $id;
                }
            }
        }

        if ($resolvedId === null && function_exists('get_page_by_path')) {
            $page = get_page_by_path($slug, 'OBJECT', $postType ?? 'page');
            if (is_object($page) && isset($page->ID) && is_numeric($page->ID)) {
                $id = (int) $page->ID;
                if ($id > 0) {
                    $resolvedId = $id;
                }
            }
        }

        $this->resolvedContentTargetIds[$target] = $resolvedId;
        return $resolvedId;
    }

    /**
     * @return list<int>
     */
    private function getRestrictedPostIdsForCurrentUser(): array
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null || $this->isSuperAdmin($currentUser)) {
            return [];
        }

        return $this->getRestrictedPostIdsForUser($currentUser);
    }

    /**
     * @return list<int>
     */
    private function getRestrictedPostIdsForUser(object $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return [];
        }

        $userId = (int) ($user->ID ?? 0);
        if ($userId > 0 && isset($this->cachedRestrictedPostIdsByUser[$userId])) {
            return $this->cachedRestrictedPostIdsByUser[$userId];
        }

        $rules = $this->getContentAccessRules();
        if ($rules === []) {
            return [];
        }

        $postIds = [];
        foreach ($rules as $rule) {
            if ($this->isUserRestrictedByRule($rule, $user)) {
                foreach ($rule['targets'] as $target) {
                    $pid = $this->resolveContentTargetToPostId($target);
                    if ($pid !== null && $pid > 0) {
                        $postIds[] = $pid;
                    }
                }
            }
        }

        $result = array_values(array_unique($postIds));
        if ($userId > 0) {
            $this->cachedRestrictedPostIdsByUser[$userId] = $result;
        }

        return $result;
    }

    private function isPostRestrictedForCurrentUser(int $postId): bool
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            return false;
        }

        return $this->isPostRestrictedForUser($postId, $currentUser);
    }

    private function isPostRestrictedForUser(int $postId, object $user): bool
    {
        if ($postId <= 0 || $this->isSuperAdmin($user)) {
            return false;
        }

        $rules = $this->getContentAccessRules();
        if ($rules === []) {
            return false;
        }

        $post = null;
        $hasLookedUpPost = false;

        foreach ($rules as $rule) {
            if (!$this->isUserRestrictedByRule($rule, $user)) {
                continue;
            }

            foreach ($rule['targets'] as $target) {
                $resolvedId = $this->resolveContentTargetToPostId($target);
                if ($resolvedId !== null && $resolvedId === $postId) {
                    return true;
                }

                if (!$hasLookedUpPost) {
                    $post = $this->getPost($postId);
                    $hasLookedUpPost = true;
                }

                if ($post !== null) {
                    $postName = is_string($post->post_name ?? null) ? $post->post_name : '';
                    $postType = is_string($post->post_type ?? null) ? $post->post_type : 'post';

                    if (str_starts_with($target, 'id:')) {
                        $rawId = substr($target, 3);
                        if (ctype_digit($rawId) && (int) $rawId === $postId) {
                            $this->resolvedContentTargetIds[$target] = $postId;
                            return true;
                        }
                    } elseif (ctype_digit($target)) {
                        if ((int) $target === $postId) {
                            $this->resolvedContentTargetIds[$target] = $postId;
                            return true;
                        }
                    } elseif (str_starts_with($target, 'slug:')) {
                        $targetSlug = substr($target, 5);
                        if ($targetSlug !== '' && $targetSlug === $postName) {
                            $this->resolvedContentTargetIds[$target] = $postId;
                            return true;
                        }
                    } elseif (str_contains($target, ':')) {
                        [$targetType, $targetSlug] = explode(':', $target, 2);
                        if ($targetType === $postType && $targetSlug === $postName) {
                            $this->resolvedContentTargetIds[$target] = $postId;
                            return true;
                        }
                    } else {
                        if ($target !== '' && $target === $postName) {
                            $this->resolvedContentTargetIds[$target] = $postId;
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $existingList
     * @return array<int|string, mixed>
     */
    private function applyHiddenList(string $envKey, array $existingList): array
    {
        $toHide = $this->getHiddenTargetsForCurrentUser($envKey);
        if ($toHide === []) {
            return $existingList;
        }

        /** @var list<string> $merged */
        $merged = [];
        foreach ($existingList as $item) {
            if (is_string($item)) {
                $merged[] = $item;
            }
        }
        foreach ($toHide as $item) {
            $merged[] = $item;
        }

        return array_values(array_unique($merged));
    }

    /**
     * @return list<string>
     */
    private function getHiddenTargetsForCurrentUser(string $envKey): array
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null || $this->isSuperAdmin($currentUser)) {
            return [];
        }

        $raw = $this->getEnv($envKey);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $rules = $this->parseRules(trim($raw));
        $targets = [];
        foreach ($rules as $rule) {
            if ($this->isUserRestrictedByRule($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $targets[] = $target;
                }
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @return list<string>
     */
    private function extractUserRoles(object $user): array
    {
        $rawRoles = $user->roles ?? [];
        if (!is_array($rawRoles)) {
            return [];
        }

        $roles = [];
        foreach ($rawRoles as $r) {
            if (is_string($r) && trim($r) !== '') {
                $roles[] = strtolower(trim($r));
            }
        }

        return array_values(array_unique($roles));
    }

    private function getActiveSalt(): string
    {
        if ($this->cachedSalt !== null) {
            return $this->cachedSalt;
        }

        $path = $this->customSaltFile ?? self::DEFAULT_SALT_SECRETS_PATH;
        $content = $this->readFile($path);
        if ($content !== false && trim($content) !== '') {
            $this->cachedSalt = trim($content);
            return $this->cachedSalt;
        }

        $this->cachedSalt = '';
        return '';
    }

    /**
     * @return list<string>
     */
    private function getSuperAdminHashes(): array
    {
        if ($this->cachedSuperAdminHashes !== null) {
            return $this->cachedSuperAdminHashes;
        }

        $path = $this->customSuperAdminFile ?? self::DEFAULT_SUPER_ADMIN_SECRETS_PATH;
        $content = $this->readFile($path);
        if ($content !== false && trim($content) !== '') {
            $lines = preg_split('/[\r\n,]+/', $content);
            $hashes = [];
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $trimmed = strtolower(trim($line));
                    if ($trimmed !== '' && preg_match('/^[a-f0-9]{128}$/', $trimmed) === 1) {
                        $hashes[] = $trimmed;
                    }
                }
            }
            if ($hashes !== []) {
                $this->cachedSuperAdminHashes = array_values(array_unique($hashes));
                return $this->cachedSuperAdminHashes;
            }
        }

        $this->cachedSuperAdminHashes = [];
        return [];
    }

    private function getEnv(string $name): string|false
    {
        if (is_callable($this->envGetter)) {
            return ($this->envGetter)($name);
        }

        return getenv($name, true);
    }

    private function readFile(string $path): string|false
    {
        if (is_callable($this->fileReader)) {
            return ($this->fileReader)($path);
        }

        return is_file($path) ? file_get_contents($path) : false;
    }

    private function getCurrentUser(): ?object
    {
        if (is_callable($this->currentUserGetter)) {
            return ($this->currentUserGetter)();
        }

        if (function_exists('wp_get_current_user')) {
            $u = wp_get_current_user();
            return is_object($u) ? $u : null;
        }

        return null;
    }

    private function getUserData(int $id): ?object
    {
        if (is_callable($this->userDataGetter)) {
            return ($this->userDataGetter)($id);
        }

        if (function_exists('get_userdata')) {
            $u = get_userdata($id);
            return is_object($u) ? $u : null;
        }

        return null;
    }

    private function getUserBy(string $field, string|int $value): ?object
    {
        if (is_callable($this->userByGetter)) {
            return ($this->userByGetter)($field, $value);
        }

        if (function_exists('get_user_by')) {
            $u = get_user_by($field, $value);
            return is_object($u) ? $u : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $args
     * @return list<object>
     */
    private function getUsers(array $args): array
    {
        if (is_callable($this->usersGetter)) {
            return ($this->usersGetter)($args);
        }

        if (function_exists('get_users')) {
            $users = get_users($args);
            if (is_array($users)) {
                $result = [];
                foreach ($users as $u) {
                    if (is_object($u)) {
                        $result[] = $u;
                    }
                }
                return $result;
            }
        }

        return [];
    }

    private function getPost(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        if (is_callable($this->postGetter)) {
            $p = ($this->postGetter)($id);
            return is_object($p) ? $p : null;
        }

        if (function_exists('get_post')) {
            $p = get_post($id);
            return is_object($p) ? $p : null;
        }

        return null;
    }

    private function getQueriedObjectId(): int
    {
        if (is_callable($this->queriedObjectIdGetter)) {
            $val = ($this->queriedObjectIdGetter)();
            if ($val > 0) {
                return $val;
            }
        }

        if (function_exists('get_queried_object_id')) {
            $val = get_queried_object_id();
            return is_numeric($val) ? (int) $val : 0;
        }

        return 0;
    }

    private function dieForbidden(string $message): void
    {
        if (is_callable($this->dieHandler)) {
            ($this->dieHandler)($message, 403, ['response' => 403]);
            return;
        }

        if (function_exists('wp_die')) {
            wp_die($message, '403 Forbidden', ['response' => 403]);
            return;
        }

        $this->renderStealthDie($message, '403 Forbidden', ['response' => 403]);
    }

    private function dieInvalidUser(string $message): void
    {
        if (is_callable($this->dieHandler)) {
            ($this->dieHandler)($message, 500, ['response' => 500]);
            return;
        }

        if (function_exists('wp_die')) {
            wp_die($message, '404 Not Found', ['response' => 404]);
            return;
        }

        $this->renderStealthDie($message, '404 Not Found', ['response' => 404]);
    }

    private function isMethodCallable(mixed $target, string $method): bool
    {
        if (!is_object($target)) {
            return false;
        }

        $callback = [$target, $method];

        return is_callable($callback);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function callObjectMethod(mixed $target, string $method, array $arguments = []): mixed
    {
        if (is_object($target)) {
            $callback = [$target, $method];
            if (is_callable($callback)) {
                return $callback(...$arguments);
            }
        }

        return null;
    }

    private function isUserLoggedIn(): bool
    {
        if (function_exists('is_user_logged_in')) {
            return is_user_logged_in();
        }

        return $this->getCurrentUser() !== null;
    }

    private function isAdmin(): bool
    {
        if (is_callable($this->adminChecker)) {
            return ($this->adminChecker)();
        }

        if (function_exists('is_admin')) {
            return is_admin();
        }

        return false;
    }

    private function wpDoingAjax(): bool
    {
        if (is_callable($this->ajaxChecker)) {
            return ($this->ajaxChecker)();
        }

        if (function_exists('wp_doing_ajax')) {
            return wp_doing_ajax();
        }

        return defined('DOING_AJAX') && DOING_AJAX;
    }

    private function wpDoingCron(): bool
    {
        if (is_callable($this->cronChecker)) {
            return ($this->cronChecker)();
        }

        if (function_exists('wp_doing_cron')) {
            return wp_doing_cron();
        }

        return defined('DOING_CRON') && DOING_CRON;
    }

    private function getPageNow(): string
    {
        $pagenow = $GLOBALS['pagenow'] ?? '';
        return is_string($pagenow) ? $pagenow : '';
    }

    private function getCurrentScreen(): ?object
    {
        if (is_callable($this->currentScreenGetter)) {
            return ($this->currentScreenGetter)();
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            return is_object($screen) ? $screen : null;
        }

        return null;
    }

    private function removeMenuPage(string $menuSlug): void
    {
        if (str_contains($menuSlug, ':')) {
            $parts = explode(':', $menuSlug, 2);
            $this->removeSubmenuPage($parts[0], $parts[1]);
            return;
        }

        if (is_callable($this->menuRemover)) {
            ($this->menuRemover)($menuSlug);
            return;
        }

        if (function_exists('remove_menu_page')) {
            remove_menu_page($menuSlug);
        }
    }

    private function removeSubmenuPage(string $menuSlug, string $submenuSlug): void
    {
        if (is_callable($this->submenuRemover)) {
            ($this->submenuRemover)($menuSlug, $submenuSlug);
            return;
        }

        if (function_exists('remove_submenu_page')) {
            remove_submenu_page($menuSlug, $submenuSlug);
        }
    }

    private function removeMetaBox(string $id, string|object $screen, string $context): void
    {
        if (is_callable($this->metaBoxRemover)) {
            ($this->metaBoxRemover)($id, $screen, $context);
            return;
        }

        if (function_exists('remove_meta_box')) {
            remove_meta_box($id, $screen, $context);
        }
    }

    private function removePostTypeSupport(string $postType, string $feature): void
    {
        if (is_callable($this->postTypeSupportRemover)) {
            ($this->postTypeSupportRemover)($postType, $feature);
            return;
        }

        if (function_exists('remove_post_type_support')) {
            remove_post_type_support($postType, $feature);
        }
    }

    /**
     * @return list<string>
     */
    private function getSupportedPostTypes(): array
    {
        if (function_exists('get_post_types')) {
            $types = get_post_types();
            if (is_array($types)) {
                $result = [];
                foreach ($types as $t) {
                    if (is_string($t) && trim($t) !== '') {
                        $result[] = trim($t);
                    }
                }
                if ($result !== []) {
                    return array_values(array_unique($result));
                }
            }
        }

        return ['post', 'page'];
    }

    private function addDashboardWidget(string $widgetId, string $widgetName, callable $callback): void
    {
        if (is_callable($this->dashboardWidgetAdder)) {
            ($this->dashboardWidgetAdder)($widgetId, $widgetName, $callback, null, null, 'normal', 'core');
            return;
        }

        if (function_exists('wp_add_dashboard_widget')) {
            wp_add_dashboard_widget($widgetId, $widgetName, $callback);
        }
    }

    private function echoHtml(string $html): void
    {
        if (is_callable($this->headerEchoer)) {
            ($this->headerEchoer)($html);
            return;
        }

        echo $html;
    }

    private function addInlineScript(string $handle, string $data, string $position = 'after'): bool
    {
        if (is_callable($this->inlineScriptAdder)) {
            return (bool) ($this->inlineScriptAdder)($handle, $data, $position);
        }

        if (function_exists('wp_add_inline_script')) {
            return wp_add_inline_script($handle, $data, $position);
        }

        return false;
    }

    private function addAction(string $tag, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (is_callable($this->actionAdder)) {
            ($this->actionAdder)($tag, $callback, $priority, $acceptedArgs);
            return;
        }

        if (function_exists('add_action')) {
            add_action($tag, $callback, $priority, $acceptedArgs);
        }
    }

    private function addFilter(string $tag, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (is_callable($this->filterAdder)) {
            ($this->filterAdder)($tag, $callback, $priority, $acceptedArgs);
            return;
        }

        if (function_exists('add_filter')) {
            add_filter($tag, $callback, $priority, $acceptedArgs);
        }
    }

    private function removeAction(string $tag, callable|string $callback, int $priority = 10): void
    {
        if (is_callable($this->actionRemover)) {
            ($this->actionRemover)($tag, $callback, $priority);
            return;
        }

        if (function_exists('remove_action')) {
            remove_action($tag, $callback, $priority);
        }
    }

    private function dequeueStyle(string $handle): void
    {
        if (is_callable($this->styleDequeuer)) {
            ($this->styleDequeuer)($handle);
            return;
        }

        if (function_exists('wp_dequeue_style')) {
            wp_dequeue_style($handle);
        }
    }

    private function deregisterStyle(string $handle): void
    {
        if (is_callable($this->styleDeregisterer)) {
            ($this->styleDeregisterer)($handle);
            return;
        }

        if (function_exists('wp_deregister_style')) {
            wp_deregister_style($handle);
        }
    }

    private function dequeueScript(string $handle): void
    {
        if (is_callable($this->scriptDequeuer)) {
            ($this->scriptDequeuer)($handle);
            return;
        }

        if (function_exists('wp_dequeue_script')) {
            wp_dequeue_script($handle);
        }
    }

    private function removeAllActions(string $tag, int|false $priority = false): void
    {
        if (is_callable($this->allActionsRemover)) {
            ($this->allActionsRemover)($tag, $priority);
            return;
        }

        if (function_exists('remove_all_actions')) {
            remove_all_actions($tag, $priority);
        }
    }

    private function unregisterWidget(string $widgetClass): void
    {
        if (is_callable($this->widgetUnregisterer)) {
            ($this->widgetUnregisterer)($widgetClass);
            return;
        }

        if (function_exists('unregister_widget')) {
            unregister_widget($widgetClass);
        }
    }

    /**
     * @return list<string>
     */
    private function getRegisteredBlockTypes(): array
    {
        if (is_callable($this->registeredBlockTypesGetter)) {
            /** @var list<string> */
            return ($this->registeredBlockTypesGetter)();
        }

        if (class_exists('WP_Block_Type_Registry')) {
            /** @var array<string, mixed> $registered */
            $registered = \WP_Block_Type_Registry::get_instance()->get_all_registered();
            return array_keys($registered);
        }

        return [];
    }

    private function terminate(): void
    {
        ($this->terminator)();
    }
}

if (function_exists('add_action') || function_exists('add_filter')) {
    (new WpAdminControl())->registerHooks();
}
