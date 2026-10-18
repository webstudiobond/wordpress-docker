<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl;

use Closure;
use WordPressDocker\AdminControl\Config\SelectorsMap;
use WordPressDocker\AdminControl\Dom\ProfileDomCleaner;
use WordPressDocker\AdminControl\Modules\ContentAccessControl;
use WordPressDocker\AdminControl\Modules\DashboardControl;
use WordPressDocker\AdminControl\Modules\GutenbergControl;
use WordPressDocker\AdminControl\Modules\MenuControl;
use WordPressDocker\AdminControl\Modules\SidebarWidgetsControl;
use WordPressDocker\AdminControl\Modules\TinyMceControl;
use WordPressDocker\AdminControl\Modules\TopbarControl;
use WordPressDocker\AdminControl\Modules\UiSanitizer;
use WordPressDocker\AdminControl\Security\ProfilePostGuard;
use WordPressDocker\AdminControl\Security\RuleMatcher;
use WordPressDocker\AdminControl\Security\SuperAdminCloaking;

/**
 * Main coordinator and hook aggregator for the WordPress Admin Control platform.
 *
 * Decomposes administrative lockdown responsibilities across specialized domain modules:
 * access security, DOM cleansing, menu/topbar sanitization, Gutenberg/TinyMCE filtering.
 *
 * @phpstan-import-type AdminRule from \WordPressDocker\AdminControl\Security\RuleMatcher
 */
final class Plugin
{
    public const string DEFAULT_SUPER_ADMIN_SECRETS_PATH = RuleMatcher::DEFAULT_SUPER_ADMIN_SECRETS_PATH;
    public const string DEFAULT_SALT_SECRETS_PATH = RuleMatcher::DEFAULT_SALT_SECRETS_PATH;

    /**
     * @var array<string, string>
     */
    public const array PROFILE_FIELD_SELECTORS_MAP = SelectorsMap::PROFILE_FIELD_SELECTORS_MAP;

    /**
     * @var array<string, string>
     */
    public const array STANDARD_WIDGETS_MAP = SidebarWidgetsControl::STANDARD_WIDGETS_MAP;

    /**
     * @var array<string, string>
     */
    public const array STANDARD_GUTENBERG_BLOCK_ALIASES = GutenbergControl::STANDARD_BLOCK_ALIASES;

    /**
     * @var array<string, list<string>>
     */
    public const array STANDARD_GUTENBERG_CATEGORIES = GutenbergControl::STANDARD_CATEGORIES;

    private readonly RuleMatcher $ruleMatcher;
    private readonly ProfileDomCleaner $profileDomCleaner;
    private readonly ProfilePostGuard $profilePostGuard;
    private readonly GutenbergControl $gutenbergControl;
    private readonly TinyMceControl $tinyMceControl;
    private readonly SidebarWidgetsControl $sidebarWidgetsControl;
    private readonly DashboardControl $dashboardControl;
    private readonly MenuControl $menuControl;
    private readonly TopbarControl $topbarControl;
    private readonly SuperAdminCloaking $superAdminCloaking;
    private readonly ContentAccessControl $contentAccessControl;
    private readonly UiSanitizer $uiSanitizer;

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
     * @param callable|null $dashboardWidgetAdder
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
     * @param (callable(): void)|null $terminator
     * @param (callable(): bool)|null $userLoggedInChecker
     * @param (callable(): array<string, string>)|null $allEnvGetter
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
        private readonly mixed $terminator = null,
        private readonly mixed $userLoggedInChecker = null,
        private readonly mixed $allEnvGetter = null,
    ) {
        $this->ruleMatcher = new RuleMatcher(
            customSuperAdminFile: $this->customSuperAdminFile,
            customSaltFile: $this->customSaltFile,
            fileReader: $this->fileReader,
            currentUserGetter: $this->currentUserGetter,
        );

        $this->profilePostGuard = new ProfilePostGuard(
            fieldsResolver: fn(?object $user = null): array => $this->getProfileFieldsToRemoveForUser($user),
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
        );

        $this->profileDomCleaner = new ProfileDomCleaner(
            docLoader: null,
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
            pageNowGetter: fn(): string => $this->getPageNow(),
            actionRemover: $this->actionRemover,
            htmlEchoer: function (string $html): void {
                $this->echoHtml($html);
            },
            postGuardSanitizer: function (array $fields): void {
                $this->profilePostGuard->handleProfilePostProtection(
                    $this->getCurrentUser(),
                    $this->getPageNow(),
                    $fields
                );
            },
        );

        $this->tinyMceControl = new TinyMceControl(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
        );

        $this->gutenbergControl = new GutenbergControl(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
            registeredBlockTypesGetter: $this->registeredBlockTypesGetter !== null
                ? fn(): array => ($this->registeredBlockTypesGetter)()
                : null,
            hiddenTargetsGetter: fn(string $envKey): array => $this->getHiddenTargetsForCurrentUser($envKey),
            inlineScriptAdder: $this->inlineScriptAdder !== null
                ? fn(string $h, string $d, string $p): bool => ($this->inlineScriptAdder)($h, $d, $p)
                : null,
            htmlEchoer: function (string $html): void {
                $this->echoHtml($html);
            },
        );

        $this->sidebarWidgetsControl = new SidebarWidgetsControl(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
            widgetUnregisterer: $this->widgetUnregisterer,
        );

        $this->dashboardControl = new DashboardControl(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
            actionRemover: $this->actionRemover,
            metaBoxRemover: $this->metaBoxRemover,
            dashboardWidgetAdder: $this->dashboardWidgetAdder,
            htmlEchoer: function (string $html): void {
                $this->echoHtml($html);
            },
            pageNowGetter: fn(): string => $this->getPageNow(),
            scriptDequeuer: $this->scriptDequeuer,
        );

        $this->menuControl = new MenuControl(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
            userRestrictedChecker: fn(array $rule, ?object $user): bool => $this->isUserRestrictedByRule($rule, $user),
            superAdminChecker: fn(?object $user): bool => $this->isSuperAdmin($user),
            menuRemover: $this->menuRemover !== null ? function (string $s): void {
                ($this->menuRemover)($s);
            } : null,
            submenuRemover: $this->submenuRemover !== null ? function (string $m, string $s): void {
                ($this->submenuRemover)($m, $s);
            } : null,
            dieForbiddenHandler: function (string $message): void {
                $this->dieForbidden($message);
            },
            htmlEchoer: function (string $html): void {
                $this->echoHtml($html);
            },
            allEnvGetter: $this->allEnvGetter !== null ? fn(): array => ($this->allEnvGetter)() : null,
        );

        $this->topbarControl = new TopbarControl(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
            adminChecker: $this->adminChecker !== null ? fn(): bool => ($this->adminChecker)() : null,
            queriedObjectIdGetter: $this->queriedObjectIdGetter !== null
                ? fn(): int => ($this->queriedObjectIdGetter)()
                : null,
            postRestrictedChecker: fn(int $id): bool => $this->isPostRestrictedForCurrentUser($id),
            userLoggedInChecker: fn(): bool => $this->isUserLoggedIn(),
            htmlEchoer: function (string $html): void {
                $this->echoHtml($html);
            },
        );

        $this->superAdminCloaking = new SuperAdminCloaking(
            superAdminChecker: fn(?object $user = null): bool => $this->isSuperAdmin($user),
            superAdminIdsResolver: null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            userDataGetter: $this->userDataGetter !== null
                ? fn(int $id): ?object => ($this->userDataGetter)($id)
                : null,
            usersGetter: $this->usersGetter !== null
                ? fn(array $args): array => ($this->usersGetter)($args)
                : null,
            userMetaGetter: $this->userMetaGetter !== null
                ? fn(int $id, string $k, bool $s): mixed => ($this->userMetaGetter)($id, $k, $s)
                : null,
            blockedTargetsGetter: fn(): array => $this->getSidebarMenuControlForCurrentUser()['block'],
            pageNowGetter: fn(): string => $this->getPageNow(),
            htmlEchoer: function (string $html): void {
                $this->echoHtml($html);
            },
            dieInvalidUserHandler: function (string $message): void {
                $this->dieInvalidUser($message);
            },
            adminChecker: $this->adminChecker !== null ? fn(): bool => ($this->adminChecker)() : null,
            authorArchiveChecker: null,
            queriedObjectIdGetter: $this->queriedObjectIdGetter !== null
                ? fn(): int => ($this->queriedObjectIdGetter)()
                : null,
            statusHeaderSender: null,
            nocacheHeadersSender: null,
            userActionsControlGetter: fn(): array => $this->getUserActionsControlForCurrentUser(),
            dieForbiddenHandler: function (string $message): void {
                $this->dieForbidden($message);
            },
        );

        $this->contentAccessControl = new ContentAccessControl(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            userDataGetter: $this->userDataGetter !== null
                ? fn(int $id): ?object => ($this->userDataGetter)($id)
                : null,
            superAdminChecker: fn(?object $user = null): bool => $this->isSuperAdmin($user),
            superAdminIdsResolver: fn(): array => $this->superAdminCloaking->resolveSuperAdminIds(),
            userByGetter: $this->userByGetter !== null
                ? fn(string $f, string|int $v): ?object => ($this->userByGetter)($f, $v)
                : null,
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userRestrictedChecker: fn(array $rule, ?object $user): bool => $this->isUserRestrictedByRule($rule, $user),
            postGetter: $this->postGetter !== null ? fn(int $id): ?object => ($this->postGetter)($id) : null,
            postResolver: $this->postResolver,
            adminChecker: $this->adminChecker !== null ? fn(): bool => ($this->adminChecker)() : null,
            currentScreenGetter: $this->currentScreenGetter !== null
                ? fn(): ?object => ($this->currentScreenGetter)()
                : null,
            userPostsCountGetter: $this->userPostsCountGetter !== null
                ? fn(int $u, string $p): int => ($this->userPostsCountGetter)($u, $p)
                : null,
            pageNowGetter: fn(): string => $this->getPageNow(),
            hiddenTargetsGetter: fn(string $envKey): array => $this->getHiddenTargetsForCurrentUser($envKey),
            dieForbiddenHandler: function (string $message): void {
                $this->dieForbidden($message);
            },
        );

        $this->uiSanitizer = new UiSanitizer(
            envGetter: $this->envGetter !== null ? fn(string $name): string|false => ($this->envGetter)($name) : null,
            currentUserGetter: $this->currentUserGetter !== null
                ? fn(): ?object => ($this->currentUserGetter)()
                : fn(): ?object => $this->getCurrentUser(),
            superAdminChecker: fn(?object $user = null): bool => $this->isSuperAdmin($user),
            ruleParser: fn(string $raw): array => $this->parseRules($raw),
            userMatcher: fn(array $rule, ?object $user): bool => $this->isUserMatchingCosmeticRule($rule, $user),
            userRestrictedChecker: fn(array $rule, ?object $user): bool => $this->isUserRestrictedByRule($rule, $user),
            actionRemover: $this->actionRemover,
            allActionsRemover: $this->allActionsRemover,
            styleDequeuer: $this->styleDequeuer,
            styleDeregisterer: $this->styleDeregisterer,
            scriptDequeuer: $this->scriptDequeuer,
            currentScreenGetter: $this->currentScreenGetter !== null
                ? fn(): ?object => ($this->currentScreenGetter)()
                : null,
            metaBoxRemover: $this->metaBoxRemover,
            postTypeSupportRemover: $this->postTypeSupportRemover,
            htmlEchoer: function (string $html): void {
                $this->echoHtml($html);
            },
            gutenbergPanelResolver: fn(string $target): ?string => $this->gutenbergControl->resolveGutenbergPanelId(
                $target
            ),
            filterAdder: $this->filterAdder !== null
                ? function (string $tag, mixed $callback, int $prio, int $args): void {
                    if (\is_callable($callback) || \is_string($callback)) {
                        ($this->filterAdder)($tag, $callback, $prio, $args);
                    }
                } : null,
            terminator: $this->terminator,
        );
    }

    public function getRuleMatcher(): RuleMatcher
    {
        return $this->ruleMatcher;
    }

    public function getProfileDomCleaner(): ProfileDomCleaner
    {
        return $this->profileDomCleaner;
    }

    public function getProfilePostGuard(): ProfilePostGuard
    {
        return $this->profilePostGuard;
    }

    public function getGutenbergControl(): GutenbergControl
    {
        return $this->gutenbergControl;
    }

    public function getTinyMceControl(): TinyMceControl
    {
        return $this->tinyMceControl;
    }

    public function getSidebarWidgetsControl(): SidebarWidgetsControl
    {
        return $this->sidebarWidgetsControl;
    }

    public function getDashboardControl(): DashboardControl
    {
        return $this->dashboardControl;
    }

    public function getMenuControl(): MenuControl
    {
        return $this->menuControl;
    }

    public function getTopbarControl(): TopbarControl
    {
        return $this->topbarControl;
    }

    public function getSuperAdminCloaking(): SuperAdminCloaking
    {
        return $this->superAdminCloaking;
    }

    public function getContentAccessControl(): ContentAccessControl
    {
        return $this->contentAccessControl;
    }

    public function getUiSanitizer(): UiSanitizer
    {
        return $this->uiSanitizer;
    }

    public function registerHooks(): void
    {
        $this->addAction('admin_init', [$this, 'handleAdminInit'], 1, 0);
        $this->addAction('admin_menu', [$this->menuControl, 'handleAdminMenu'], 99999, 0);
        $this->addAction('admin_bar_menu', [$this->topbarControl, 'handleAdminBarMenu'], 999, 1);
        $this->addAction('admin_bar_menu', [$this->topbarControl, 'handleTopbarMenuOrder'], 9999, 1);
        $this->addAction('wp_before_admin_bar_render', [$this->topbarControl, 'handleBeforeAdminBarRender'], 99999, 0);
        $this->addAction('wp_dashboard_setup', [$this->dashboardControl, 'handleDashboardSetup'], 999, 0);
        $this->addAction('admin_head', [$this, 'handleAdminHead'], 999, 0);
        $this->addAction('admin_print_scripts', [$this->uiSanitizer, 'handleAdminPrintScripts'], 999, 0);
        $this->addAction('admin_enqueue_scripts', [$this->uiSanitizer, 'handleAdminEnqueueScripts'], 999, 0);
        $this->addAction('admin_enqueue_scripts', [$this->dashboardControl, 'handleAdminEnqueueScripts'], 999, 0);
        $this->addAction(
            'admin_print_footer_scripts',
            [$this->gutenbergControl, 'handleAdminPrintFooterScripts'],
            999,
            0
        );
        $this->addAction('wp_head', [$this->topbarControl, 'handleWpHead'], 999, 0);
        $this->addAction('current_screen', [$this, 'handleCurrentScreen'], 10, 1);
        $this->addAction('add_meta_boxes', [$this->uiSanitizer, 'handleMetaBoxesSetup'], 999, 2);
        $this->addAction('enqueue_block_editor_assets', [$this->gutenbergControl, 'handleBlockEditorAssets'], 999, 0);
        $this->addAction('rest_api_init', [$this, 'handleRestApiInit'], 999, 0);
        $this->addAction('template_redirect', [$this->superAdminCloaking, 'handleTemplateRedirect'], 1, 0);
        $this->addAction('widgets_init', [$this->sidebarWidgetsControl, 'handleWidgetsInit'], 99, 0);

        if ($this->uiSanitizer->isGoogleFontsDisabled()) {
            $this->addAction('admin_init', [$this->uiSanitizer, 'handleDisableGoogleFontsInit'], 1, 0);
            $this->addAction('admin_head', [$this->uiSanitizer, 'handleDisableGoogleFontsHead'], 1, 0);
            $this->addAction('admin_print_styles', [$this->uiSanitizer, 'handleDisableGoogleFontsStyles'], 1, 0);
            $this->addAction('admin_print_styles', [$this->uiSanitizer, 'handleDisableGoogleFontsStyles'], 9999, 0);
            $this->addFilter('wp_resource_hints', [$this->uiSanitizer, 'filterResourceHints'], 999, 2);
        }

        $this->addFilter('pre_get_users', [$this->superAdminCloaking, 'filterPreGetUsers'], 10, 1);
        $this->addFilter('views_users', [$this->superAdminCloaking, 'filterViewsUsers'], 999, 1);
        $this->addFilter('user_row_actions', [$this->superAdminCloaking, 'filterUserRowActions'], 999, 2);
        $this->addFilter('bulk_actions-users', [$this->superAdminCloaking, 'filterBulkActionsUsers'], 999, 1);
        $this->addFilter(
            'manage_users_custom_column',
            [$this->superAdminCloaking, 'filterManageUsersCustomColumn'],
            999,
            3
        );
        $this->addFilter('rest_user_query', [$this->superAdminCloaking, 'filterRestUserQuery'], 10, 2);
        $this->addFilter('rest_pre_dispatch', [$this->superAdminCloaking, 'filterRestPreDispatch'], 10, 3);
        $this->addFilter('rest_post_query', [$this->contentAccessControl, 'filterRestPostQuery'], 10, 2);
        $this->addFilter('rest_page_query', [$this->contentAccessControl, 'filterRestPostQuery'], 10, 2);
        $this->addFilter('rest_prepare_post', [$this->contentAccessControl, 'filterRestPreparePost'], 10, 3);
        $this->addFilter('rest_prepare_page', [$this->contentAccessControl, 'filterRestPreparePost'], 10, 3);
        $this->addFilter('rest_comment_query', [$this->contentAccessControl, 'filterRestCommentQuery'], 10, 2);
        $this->addFilter('rest_prepare_comment', [$this->contentAccessControl, 'filterRestPrepareComment'], 10, 3);

        $this->addFilter('custom_menu_order', [$this->menuControl, 'filterCustomMenuOrder'], 10, 1);
        $this->addFilter('menu_order', [$this->menuControl, 'filterMenuOrder'], 10, 1);

        $this->addFilter('screen_options_show_screen', [$this->uiSanitizer, 'filterScreenOptionsShowScreen'], 10, 1);
        $this->addFilter('hidden_meta_boxes', [$this->uiSanitizer, 'filterHiddenMetaBoxes'], 10, 2);
        $this->addFilter('hidden_columns', [$this->uiSanitizer, 'filterHiddenColumns'], 10, 2);
        $this->addFilter('default_hidden_columns', [$this->uiSanitizer, 'filterDefaultHiddenColumns'], 10, 2);
        $this->addFilter('manage_posts_columns', [$this->uiSanitizer, 'filterScreenColumns'], 999, 1);
        $this->addFilter('manage_pages_columns', [$this->uiSanitizer, 'filterScreenColumns'], 999, 1);
        $this->addFilter('manage_users_columns', [$this->uiSanitizer, 'filterScreenColumns'], 999, 1);

        $this->addFilter('map_meta_cap', [$this, 'filterMapMetaCap'], 10, 4);
        $this->addFilter('pre_get_posts', [$this->contentAccessControl, 'filterPreGetPosts'], 10, 1);
        $this->addFilter('wp_count_posts', [$this->contentAccessControl, 'filterCountPosts'], 10, 3);
        $this->addFilter('views_edit-post', [$this->contentAccessControl, 'filterViewsEditPosts'], 10, 1);
        $this->addFilter('views_edit-page', [$this->contentAccessControl, 'filterViewsEditPosts'], 10, 1);
        $this->addFilter('author_link', [$this->contentAccessControl, 'filterAuthorLink'], 10, 3);
        $this->addFilter('wp_die_handler', [$this->uiSanitizer, 'filterWpDieHandler'], 999, 1);
        $this->addFilter('admin_footer_text', [$this->uiSanitizer, 'filterAdminFooterText'], 999, 1);
        $this->addFilter('update_footer', [$this->uiSanitizer, 'filterUpdateFooter'], 999, 1);
        $this->addFilter(
            'block_editor_settings_all',
            [$this->gutenbergControl, 'filterBlockEditorSettingsAll'],
            999,
            2
        );
        $this->addFilter('wp_insert_post_data', [$this->contentAccessControl, 'filterInsertPostData'], 999, 4);
        $this->addFilter('allowed_block_types_all', [$this->gutenbergControl, 'filterAllowedBlockTypesAll'], 999, 2);
        $this->addFilter('user_contactmethods', [$this->profileDomCleaner, 'filterUserContactMethods'], 999, 2);
        $this->addFilter('show_admin_bar', [$this->profileDomCleaner, 'filterShowAdminBar'], 999, 1);
        $this->addFilter('mce_buttons', [$this->tinyMceControl, 'filterMceButtons'], 999, 2);
        $this->addFilter('mce_buttons_2', [$this->tinyMceControl, 'filterMceButtons'], 999, 2);
        $this->addFilter('mce_buttons_3', [$this->tinyMceControl, 'filterMceButtons'], 999, 2);
        $this->addFilter('mce_buttons_4', [$this->tinyMceControl, 'filterMceButtons'], 999, 2);
        $this->addFilter('rest_pre_insert_user', [$this->profilePostGuard, 'filterRestPreInsertUser'], 10, 2);
    }

    public function handleAdminInit(): void
    {
        if (!$this->isUserLoggedIn() || $this->wpDoingAjax() || $this->wpDoingCron()) {
            return;
        }

        if (!$this->isAdmin()) {
            return;
        }

        $this->uiSanitizer->enforcePostTypeSupportRestrictions();

        $currentUser = $this->getCurrentUser();
        $pagenow = $this->getPageNow();

        $this->menuControl->enforceRouteRestrictions($pagenow, $currentUser);
        $this->superAdminCloaking->enforceUserActionProtection($pagenow);
        if ($pagenow === 'users.php' && $this->superAdminCloaking->isTargetBlocked('user-new.php')) {
            $this->superAdminCloaking->startOutputBuffering();
        }

        $this->contentAccessControl->enforcePostAccessRestrictions($pagenow);
        $this->profileDomCleaner->handleAdminInit();
        $this->dashboardControl->handleAdminInit();
    }

    public function handleCurrentScreen(mixed $screen): void
    {
        if (!\is_object($screen)) {
            return;
        }

        $this->uiSanitizer->handleCurrentScreen($screen);
        $screenId = isset($screen->id) && \is_string($screen->id) ? $screen->id : '';
        $isTaxonomy = isset($screen->taxonomy) && \is_string($screen->taxonomy) && $screen->taxonomy !== '';

        if (
            $screenId !== ''
            && \str_starts_with($screenId, 'edit-')
            && $screenId !== 'edit-post'
            && $screenId !== 'edit-page'
            && !$isTaxonomy
        ) {
            $this->addFilter('views_' . $screenId, [$this->contentAccessControl, 'filterViewsEditPosts'], 10, 1);
        }
    }

    public function handleAdminHead(): void
    {
        $this->uiSanitizer->handleAdminHead();
        $this->dashboardControl->handleAdminHead();
        $this->profileDomCleaner->handleAdminHead();
        $this->superAdminCloaking->handleAdminHead();
        $currentUser = $this->getCurrentUser();
        $this->menuControl->handleAdminHead($currentUser);
        $this->topbarControl->renderLockProfileStyles($currentUser);
        $this->topbarControl->renderCustomIconStyles();
        $this->topbarControl->renderHideTopbarNodesStyles($currentUser);
    }

    /**
     * @param array<int, string> $caps
     * @param mixed $cap
     * @param int $userId
     * @param array<int, mixed> $args
     * @return array<int, string>
     */
    public function filterMapMetaCap(array $caps, mixed $cap, int $userId, array $args): array
    {
        $caps = $this->superAdminCloaking->filterMapMetaCap($caps, $cap, $userId, $args);
        if (\in_array('do_not_allow', $caps, true)) {
            return $caps;
        }

        return $this->contentAccessControl->filterMapMetaCap($caps, $cap, $userId, $args);
    }

    /**
     * Prevents self-profile field tampering across REST API endpoints by delegating to ProfilePostGuard.
     */
    public function filterRestPreInsertUser(mixed $preparedUser, mixed $request = null): mixed
    {
        return $this->profilePostGuard->filterRestPreInsertUser($preparedUser, $request);
    }

    public function isSuperAdmin(?object $user = null): bool
    {
        return $this->ruleMatcher->isSuperAdmin($user);
    }

    /**
     * @return list<int>
     */
    public function resolveSuperAdminIds(): array
    {
        return $this->superAdminCloaking->resolveSuperAdminIds();
    }

    /**
     * @return list<AdminRule>
     */
    public function parseRules(string $rawRules): array
    {
        return $this->ruleMatcher->parseRules($rawRules);
    }

    /**
     * @param AdminRule $rule
     */
    public function isUserRestrictedByRule(array $rule, ?object $user): bool
    {
        return $this->ruleMatcher->isUserRestrictedByRule($rule, $user);
    }

    /**
     * @param AdminRule $rule
     */
    public function isUserMatchingCosmeticRule(array $rule, ?object $user): bool
    {
        return $this->ruleMatcher->isUserMatchingCosmeticRule($rule, $user);
    }

    /**
     * @return list<string>
     */
    public function getRegisteredBlockTypes(): array
    {
        return $this->gutenbergControl->getRegisteredBlockTypes();
    }

    public function handleRestApiInit(): void
    {
        $this->uiSanitizer->enforcePostTypeSupportRestrictions();
    }

    public function removeMenuPage(string $menuSlug): void
    {
        $this->menuControl->removeMenuPage($menuSlug);
    }

    public function removeSubmenuPage(string $menuSlug, string $submenuSlug): void
    {
        $this->menuControl->removeSubmenuPage($menuSlug, $submenuSlug);
    }

    public function filterCustomMenuOrder(mixed $custom): bool
    {
        return $this->menuControl->filterCustomMenuOrder($custom);
    }

    /**
     * @param array<int|string, mixed> $menuOrder
     * @return array<int|string, mixed>
     */
    public function filterMenuOrder(array $menuOrder): array
    {
        return $this->menuControl->filterMenuOrder($menuOrder);
    }

    /**
     * @param array<int|string, mixed> $buttons
     * @param string|null $editorId
     * @return list<string>
     */
    public function filterMceButtons(array $buttons, ?string $editorId = null): array
    {
        return $this->tinyMceControl->filterMceButtons($buttons, $editorId);
    }

    /**
     * @param bool|array<int|string, mixed> $allowedBlockTypes
     * @param mixed $blockEditorContext
     * @return bool|list<string>
     */
    public function filterAllowedBlockTypesAll(
        bool|array $allowedBlockTypes,
        mixed $blockEditorContext = null
    ): bool|array {
        return $this->gutenbergControl->filterAllowedBlockTypesAll($allowedBlockTypes, $blockEditorContext);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function filterBlockEditorSettingsAll(array $settings, mixed $context = null): array
    {
        return $this->gutenbergControl->filterBlockEditorSettingsAll($settings, $context);
    }

    /**
     * @param array<string, mixed> $views
     * @return array<string, mixed>
     */
    public function filterViewsUsers(array $views): array
    {
        return $this->superAdminCloaking->filterViewsUsers($views);
    }

    /**
     * @param array<string, string> $actions
     * @param mixed $user
     * @return array<string, string>
     */
    public function filterUserRowActions(array $actions, mixed $user): array
    {
        return $this->superAdminCloaking->filterUserRowActions($actions, $user);
    }

    public function isPostRestrictedForCurrentUser(int $postId): bool
    {
        return $this->contentAccessControl->isPostRestrictedForCurrentUser($postId);
    }

    public function filterAuthorLink(string $link, int $authorId, string $authorNicename = ''): string
    {
        return $this->contentAccessControl->filterAuthorLink($link, $authorId, $authorNicename);
    }

    /**
     * @param array<string, mixed> $columns
     * @return array<string, mixed>
     */
    public function filterScreenColumns(array $columns): array
    {
        return $this->uiSanitizer->filterScreenColumns($columns);
    }

    public function filterAdminFooterText(string $defaultText): string
    {
        return $this->uiSanitizer->filterAdminFooterText($defaultText);
    }

    public function filterUpdateFooter(string $defaultText): string
    {
        return $this->uiSanitizer->filterUpdateFooter($defaultText);
    }

    public function filterRestPreDispatch(mixed $result, mixed $server, mixed $request): mixed
    {
        return $this->superAdminCloaking->filterRestPreDispatch($result, $server, $request);
    }

    /**
     * @return array{
     *     hide: list<string>,
     *     block: list<string>
     * }
     */
    public function getSidebarMenuControlForCurrentUser(): array
    {
        return $this->menuControl->getSidebarMenuControlForCurrentUser($this->getCurrentUser());
    }

    /**
     * @return array{
     *     hide: list<string>,
     *     block: list<string>
     * }
     */
    public function getUserActionsControlForCurrentUser(?object $user = null): array
    {
        $currentUser = $user ?? $this->getCurrentUser();
        if ($currentUser === null) {
            return ['hide' => [], 'block' => []];
        }

        $rawParts = $this->getUserActionsControlRawParts();
        if ($rawParts === []) {
            return ['hide' => [], 'block' => []];
        }

        $combined = \implode('; ', $rawParts);
        $rules = $this->parseRules($combined);
        if ($rules === []) {
            return ['hide' => [], 'block' => []];
        }

        /** @var array<string, 'hide'|'block'> $targetActions */
        $targetActions = [];

        foreach ($rules as $rule) {
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
            'hide' => \array_values(\array_unique($hideTargets)),
            'block' => \array_values(\array_unique($blockTargets)),
        ];
    }

    /**
     * @return list<string>
     */
    protected function getUserActionsControlRawParts(): array
    {
        $rawParts = [];
        $rawMain = $this->getEnv('WP_ADMIN_USER_ACTIONS_CONTROL');
        $allEnv = $this->getAllEnv();

        if ($rawMain === false && isset($allEnv['WP_ADMIN_USER_ACTIONS_CONTROL'])) {
            $rawMain = (string) $allEnv['WP_ADMIN_USER_ACTIONS_CONTROL'];
        }

        if ($rawMain !== false) {
            $trimmedMain = \trim($rawMain);
            if ($trimmedMain !== '') {
                $rawParts[] = $trimmedMain;
            }
        }

        if ($allEnv !== []) {
            $sharded = [];
            foreach ($allEnv as $key => $val) {
                $keyStr = (string) $key;
                if (\str_starts_with($keyStr, 'WP_ADMIN_USER_ACTIONS_CONTROL_')) {
                    $trimmed = \trim((string) $val);
                    if ($trimmed !== '') {
                        $sharded[$keyStr] = $trimmed;
                    }
                }
            }

            if ($sharded !== []) {
                \ksort($sharded);
                foreach ($sharded as $val) {
                    $rawParts[] = $val;
                }
            }
        }

        return $rawParts;
    }

    /**
     * @return array<string, string>
     */
    private function getAllEnv(): array
    {
        if (\is_callable($this->allEnvGetter)) {
            return ($this->allEnvGetter)();
        }

        return \getenv();
    }

    protected function getEnv(string $name): string|false
    {
        if (\is_callable($this->envGetter)) {
            return ($this->envGetter)($name);
        }

        return \getenv($name);
    }

    /**
     * @return list<string>
     */
    public function getProfileFieldsToRemoveForUser(?object $user = null): array
    {
        return $this->profileDomCleaner->getProfileFieldsToRemoveForUser($user);
    }

    /**
     * @return list<string>
     */
    public function getHiddenTargetsForCurrentUser(string $envKey): array
    {
        return $this->uiSanitizer->getHiddenTargetsForCurrentUser($envKey);
    }

    public function isGoogleFontsDisabled(): bool
    {
        return $this->uiSanitizer->isGoogleFontsDisabled();
    }

    protected function getCurrentUser(): ?object
    {
        if (\is_callable($this->currentUserGetter)) {
            $user = ($this->currentUserGetter)();
            return \is_object($user) ? $user : null;
        }

        $user = \function_exists('wp_get_current_user') ? \wp_get_current_user() : null;
        return \is_object($user) ? $user : null;
    }

    protected function isAdmin(): bool
    {
        if (\is_callable($this->adminChecker)) {
            return ($this->adminChecker)();
        }

        return \function_exists('is_admin') && \is_admin();
    }

    protected function getPageNow(): string
    {
        $raw = $GLOBALS['pagenow'] ?? '';
        return \is_string($raw) ? \trim($raw) : '';
    }

    protected function echoHtml(string $html): void
    {
        if (\is_callable($this->headerEchoer)) {
            ($this->headerEchoer)($html);
            return;
        }

        echo $html;
    }

    protected function dieForbidden(string $message): void
    {
        if (\is_callable($this->dieHandler)) {
            ($this->dieHandler)($message, 403, ['response' => 403]);
            return;
        }

        if (\function_exists('wp_die')) {
            \wp_die(
                \function_exists('__') ? (string) \__($message) : $message,
                '403 Forbidden',
                ['response' => 403]
            );
        }
    }

    protected function dieInvalidUser(string $message): void
    {
        if (\is_callable($this->dieHandler)) {
            ($this->dieHandler)($message, 500, ['response' => 500]);
            return;
        }

        if (\function_exists('wp_die')) {
            \wp_die(\function_exists('__') ? (string) \__($message) : $message);
        }
    }

    protected function addAction(
        string $tag,
        callable|string $callback,
        int $priority = 10,
        int $acceptedArgs = 1
    ): void {
        if (\is_callable($this->actionAdder)) {
            ($this->actionAdder)($tag, $callback, $priority, $acceptedArgs);
            return;
        }

        if (\function_exists('add_action')) {
            \add_action($tag, $callback, $priority, $acceptedArgs);
        }
    }

    protected function addFilter(
        string $tag,
        callable|string $callback,
        int $priority = 10,
        int $acceptedArgs = 1
    ): void {
        if (\is_callable($this->filterAdder)) {
            ($this->filterAdder)($tag, $callback, $priority, $acceptedArgs);
            return;
        }

        if (\function_exists('add_filter')) {
            \add_filter($tag, $callback, $priority, $acceptedArgs);
        }
    }

    protected function isUserLoggedIn(): bool
    {
        if (\is_callable($this->userLoggedInChecker)) {
            return ($this->userLoggedInChecker)();
        }

        return \function_exists('is_user_logged_in') && \is_user_logged_in();
    }

    protected function wpDoingAjax(): bool
    {
        if (\is_callable($this->ajaxChecker)) {
            return ($this->ajaxChecker)();
        }

        return \function_exists('wp_doing_ajax') && \wp_doing_ajax();
    }

    protected function wpDoingCron(): bool
    {
        if (\is_callable($this->cronChecker)) {
            return ($this->cronChecker)();
        }

        return \function_exists('wp_doing_cron') && \wp_doing_cron();
    }
}
