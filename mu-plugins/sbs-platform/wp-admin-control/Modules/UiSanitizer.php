<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Sanitizes dashboard administrative interfaces by suppressing notices, fonts, contextual help, and footers.
 *
 * Removes external asset dependencies to prevent data exfiltration and visual distractions.
 * Intercepts fatal termination handlers to deliver hardened, information-leak-free error responses.
 *
 * @phpstan-import-type AdminRule from \WordPressDocker\AdminControl\Security\RuleMatcher
 */
final class UiSanitizer
{
    /** @var callable(): mixed */
    private readonly mixed $terminator;

    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(?object): bool)|null $superAdminChecker
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(AdminRule, ?object): bool)|null $userRestrictedChecker
     * @param (callable(string, callable|string, int): void)|null $actionRemover
     * @param (callable(string, int|false): void)|null $allActionsRemover
     * @param (callable(string): void)|null $styleDequeuer
     * @param (callable(string): void)|null $styleDeregisterer
     * @param (callable(string): void)|null $scriptDequeuer
     * @param (callable(): (?object))|null $currentScreenGetter
     * @param (callable(string, string|object, string): void)|null $metaBoxRemover
     * @param (callable(string, string): void)|null $postTypeSupportRemover
     * @param (callable(string): void)|null $htmlEchoer
     * @param (callable(string): (?string))|null $gutenbergPanelResolver
     * @param (callable(string, callable|string, int, int): void)|null $filterAdder
     * @param (callable(): void)|null $terminator
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $superAdminChecker = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $userRestrictedChecker = null,
        private readonly mixed $actionRemover = null,
        private readonly mixed $allActionsRemover = null,
        private readonly mixed $styleDequeuer = null,
        private readonly mixed $styleDeregisterer = null,
        private readonly mixed $scriptDequeuer = null,
        private readonly mixed $currentScreenGetter = null,
        private readonly mixed $metaBoxRemover = null,
        private readonly mixed $postTypeSupportRemover = null,
        private readonly mixed $htmlEchoer = null,
        private readonly mixed $gutenbergPanelResolver = null,
        private readonly mixed $filterAdder = null,
        mixed $terminator = null,
    ) {
        $this->terminator = $terminator ?? static fn(): never => exit(0);
    }

    public function isGoogleFontsDisabled(): bool
    {
        $raw = $this->getEnv('WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS');
        if ($raw === false || \trim($raw) === '') {
            return false;
        }

        return \in_array(\strtolower(\trim($raw)), ['true', '1', 'on', 'yes'], true);
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
        if (!\is_object($wpStyles)) {
            return;
        }

        $registered = isset($wpStyles->registered) && \is_array($wpStyles->registered)
            ? $wpStyles->registered
            : [];

        foreach ($registered as $handle => $styleObj) {
            if (!\is_object($styleObj)) {
                continue;
            }

            $src = isset($styleObj->src) && \is_string($styleObj->src) ? $styleObj->src : '';
            if (\str_contains($src, 'fonts.googleapis.com') || \str_contains($src, 'fonts.gstatic.com')) {
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
                \is_string($url)
                && !\str_contains($url, 'fonts.googleapis.com')
                && !\str_contains($url, 'fonts.gstatic.com')
            ) {
                $filtered[] = $url;
            }
        }

        return $filtered;
    }

    public function shouldHideAdminNotices(?object $user = null): bool
    {
        $raw = $this->getEnv('WP_ADMIN_HIDE_NOTICES');
        if ($raw === false || \trim($raw) === '') {
            return false;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        if ($this->ruleParser === null) {
            return false;
        }

        $rules = ($this->ruleParser)('notices|hide:' . \trim($raw));
        if ($rules === []) {
            return false;
        }

        return $this->userMatcher !== null && ($this->userMatcher)($rules[0], $currentUser);
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

    public function shouldHideScreenOptionsAndHelp(?object $user = null): bool
    {
        $raw = $this->getEnv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP');
        if ($raw === false || \trim($raw) === '') {
            return false;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        if ($this->ruleParser === null) {
            return false;
        }

        $rules = ($this->ruleParser)('screen_options|hide:' . \trim($raw));
        if ($rules === []) {
            return false;
        }

        return $this->userMatcher !== null && ($this->userMatcher)($rules[0], $currentUser);
    }

    public function filterScreenOptionsShowScreen(mixed $show): bool
    {
        if ($this->shouldHideScreenOptionsAndHelp($this->getCurrentUser())) {
            return false;
        }

        return (bool) $show;
    }

    public function handleCurrentScreen(object $screen): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->shouldHideScreenOptionsAndHelp($currentUser)) {
            if ($this->isMethodCallable($screen, 'remove_help_tabs')) {
                $this->callObjectMethod($screen, 'remove_help_tabs');
            }
        }

        $screenId = isset($screen->id) && \is_string($screen->id) ? $screen->id : '';
        if ($screenId !== '') {
            $this->addFilter('manage_' . $screenId . '_columns', [$this, 'filterScreenColumns'], 999, 1);
        }
    }

    public function handleAdminHead(): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->shouldHideScreenOptionsAndHelp($currentUser)) {
            $screen = $this->getCurrentScreen();
            if ($screen !== null && $this->isMethodCallable($screen, 'remove_help_tabs')) {
                $this->callObjectMethod($screen, 'remove_help_tabs');
            }
            $this->echoHtml(
                '<style id="wp-admin-control-hide-screen-options-help">'
                . '#screen-meta-links, #screen-options-link-wrap, '
                . '#contextual-help-link-wrap { display: none !important; }'
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

        $this->renderHiddenMetaBoxesStyles();
    }

    /**
     * Injects CSS hiding rules into administrative head to suppress hidden meta boxes and Gutenberg panels.
     */
    public function renderHiddenMetaBoxesStyles(): void
    {
        $hiddenMetaBoxes = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($hiddenMetaBoxes === []) {
            return;
        }

        $metaBoxSelectors = [];
        foreach ($hiddenMetaBoxes as $target) {
            foreach ($this->resolveMetaBoxTargetIds($target) as $boxId) {
                if (\preg_match('/^[a-zA-Z0-9_\-]+$/', $boxId)) {
                    $metaBoxSelectors[] = '#' . $boxId;
                }
            }
            $norm = \strtolower(\trim($target));
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

            if ($this->gutenbergPanelResolver !== null) {
                $panelId = ($this->gutenbergPanelResolver)($target);
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
        }

        if ($metaBoxSelectors !== []) {
            $this->echoHtml(\sprintf(
                '<style id="wp-admin-control-hide-meta-boxes">%s { display: none !important; }</style>',
                \implode(', ', \array_values(\array_unique($metaBoxSelectors)))
            ));
        }
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
     * @return list<string>
     */
    public function resolveMetaBoxTargetIds(string $target): array
    {
        $normalized = \strtolower(\trim($target));
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
            $norm = \strtolower(\trim($target));
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
        $allTargetIds = \array_values(\array_unique($allTargetIds));

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

            if (isset($GLOBALS['wp_meta_boxes']) && \is_array($GLOBALS['wp_meta_boxes'])) {
                foreach ($GLOBALS['wp_meta_boxes'] as $pageKey => &$contexts) {
                    if (!\is_array($contexts)) {
                        continue;
                    }
                    foreach (['normal', 'side', 'advanced'] as $ctx) {
                        $contextBoxes = $contexts[$ctx] ?? null;
                        if (!\is_array($contextBoxes)) {
                            continue;
                        }
                        foreach (['high', 'core', 'default', 'low'] as $prio) {
                            $priorityBoxes = $contextBoxes[$prio] ?? null;
                            if (\is_array($priorityBoxes) && \array_key_exists($targetId, $priorityBoxes)) {
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

    public function enforcePostTypeSupportRestrictions(): void
    {
        $hiddenMetaBoxes = $this->getHiddenTargetsForCurrentUser('WP_ADMIN_HIDDEN_META_BOXES');
        if ($hiddenMetaBoxes === []) {
            return;
        }

        $hasAuthor = false;
        $hasTemplate = false;
        foreach ($hiddenMetaBoxes as $target) {
            $norm = \strtolower(\trim($target));
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

    public function getCustomFooterText(string $envKey, ?object $user = null): ?string
    {
        $raw = $this->getEnv($envKey);
        if ($raw === false || \trim($raw) === '') {
            return null;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $statements = \explode(';', \trim($raw));
        foreach ($statements as $statement) {
            $trimmed = \trim($statement);
            if ($trimmed === '') {
                continue;
            }

            $segments = \explode('|', $trimmed);
            $value = \trim($segments[0]);
            if ($value === '') {
                continue;
            }

            $modifiers = \array_slice($segments, 1);
            $dummyRuleStr = 'footer|' . ($modifiers !== [] ? \implode('|', $modifiers) : 'all');
            if ($this->ruleParser === null) {
                continue;
            }

            $rules = ($this->ruleParser)($dummyRuleStr);
            if ($rules === []) {
                continue;
            }

            if ($this->userMatcher !== null && ($this->userMatcher)($rules[0], $currentUser)) {
                return \strtolower($value) === 'remove' ? '' : $value;
            }
        }

        return null;
    }

    public function filterAdminFooterText(string $defaultText): string
    {
        $custom = $this->getCustomFooterText('WP_ADMIN_FOOTER_LEFT');

        return $custom !== null
            ? \htmlspecialchars($custom, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            : $defaultText;
    }

    public function filterUpdateFooter(string $defaultText): string
    {
        $custom = $this->getCustomFooterText('WP_ADMIN_FOOTER_RIGHT');

        return $custom !== null
            ? \htmlspecialchars($custom, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            : $defaultText;
    }

    public function shouldHideAdminFooterContainer(?object $user = null): bool
    {
        $currentUser = $user ?? $this->getCurrentUser();
        if ($currentUser === null) {
            return false;
        }

        return $this->getCustomFooterText('WP_ADMIN_FOOTER_LEFT', $currentUser) === ''
            && $this->getCustomFooterText('WP_ADMIN_FOOTER_RIGHT', $currentUser) === '';
    }

    public function shouldUseStealthDie(?object $user = null): bool
    {
        $raw = $this->getEnv('WP_ADMIN_STEALTH_DIE');
        if ($raw === false) {
            return false;
        }

        $trimmed = \trim($raw);
        if ($trimmed === '' || \in_array(\strtolower($trimmed), ['false', '0', 'off', 'no', 'none'], true)) {
            return false;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        if ($this->ruleParser === null) {
            return false;
        }

        $rules = ($this->ruleParser)('stealth_die|hide:' . $trimmed);
        if ($rules === []) {
            return false;
        }

        return $this->userMatcher !== null && ($this->userMatcher)($rules[0], $currentUser);
    }

    /**
     * @param callable(mixed, string|int, array<string, mixed>): void $defaultHandler
     * @return callable(mixed, string|int, array<string, mixed>): void
     */
    public function filterWpDieHandler(callable $defaultHandler): callable
    {
        if (!$this->shouldUseStealthDie($this->getCurrentUser())) {
            return $defaultHandler;
        }

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
        $statusCode = isset($args['response']) && \is_numeric($args['response']) ? (int) $args['response'] : 500;
        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 500;
        }

        if (\function_exists('status_header')) {
            \status_header($statusCode);
        }
        if (\function_exists('nocache_headers')) {
            \nocache_headers();
        }

        $rawText = '';
        if ($message instanceof \WP_Error) {
            $rawText = $message->get_error_message();
        } elseif (\is_scalar($message)) {
            $rawText = (string) $message;
        }

        if (\trim($rawText) === '') {
            $rawText = 'Access denied. You do not have permission to view this resource.';
        }

        $escapedText = \htmlspecialchars($rawText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $defaultTitle = $statusCode === 403 ? '403 Forbidden' : (string) $statusCode . ' Error';
        $titleText = \is_string($title) && \trim($title) !== ''
            ? \htmlspecialchars(\trim($title), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : $defaultTitle;

        $color = $statusCode === 403 ? '#f59e0b' : '#ef4444';

        $html = \sprintf(
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
        ($this->terminator)();
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
            if (\is_string($item)) {
                $merged[] = $item;
            }
        }
        foreach ($toHide as $item) {
            $merged[] = $item;
        }

        return \array_values(\array_unique($merged));
    }

    /**
     * @return list<string>
     */
    public function getHiddenTargetsForCurrentUser(string $envKey): array
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null || $this->isSuperAdmin($currentUser)) {
            return [];
        }

        $raw = $this->getEnv($envKey);
        if ($raw === false || \trim($raw) === '') {
            return [];
        }

        if ($this->ruleParser === null) {
            return [];
        }

        $rules = ($this->ruleParser)(\trim($raw));
        $targets = [];
        foreach ($rules as $rule) {
            if ($this->userRestrictedChecker !== null && ($this->userRestrictedChecker)($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $targets[] = $target;
                }
            }
        }

        return \array_values(\array_unique($targets));
    }

    /**
     * @return list<string>
     */
    private function getSupportedPostTypes(): array
    {
        if (\function_exists('get_post_types')) {
            $types = \get_post_types();
            if (\is_array($types) && $types !== []) {
                return \array_values(\array_filter($types, '\is_string'));
            }
        }

        return ['post', 'page'];
    }

    private function removePostTypeSupport(string $postType, string $feature): void
    {
        if (\is_callable($this->postTypeSupportRemover)) {
            ($this->postTypeSupportRemover)($postType, $feature);
            return;
        }

        if (\function_exists('remove_post_type_support')) {
            \remove_post_type_support($postType, $feature);
        }
    }

    private function removeMetaBox(string $id, string|object $screen, string $context): void
    {
        if (\is_callable($this->metaBoxRemover)) {
            ($this->metaBoxRemover)($id, $screen, $context);
            return;
        }

        if (\function_exists('remove_meta_box')) {
            \remove_meta_box($id, $screen, $context);
        }
    }

    private function removeAction(string $tag, callable|string $functionToAdd, int $priority = 10): void
    {
        if (\is_callable($this->actionRemover)) {
            ($this->actionRemover)($tag, $functionToAdd, $priority);
            return;
        }

        if (\function_exists('remove_action')) {
            \remove_action($tag, $functionToAdd, $priority);
        }
    }

    private function removeAllActions(string $tag, int|false $priority = false): void
    {
        if (\is_callable($this->allActionsRemover)) {
            ($this->allActionsRemover)($tag, $priority);
            return;
        }

        if (\function_exists('remove_all_actions')) {
            \remove_all_actions($tag, $priority);
        }
    }

    private function dequeueStyle(string $handle): void
    {
        if (\is_callable($this->styleDequeuer)) {
            ($this->styleDequeuer)($handle);
            return;
        }

        if (\function_exists('wp_dequeue_style')) {
            \wp_dequeue_style($handle);
        }
    }

    private function deregisterStyle(string $handle): void
    {
        if (\is_callable($this->styleDeregisterer)) {
            ($this->styleDeregisterer)($handle);
            return;
        }

        if (\function_exists('wp_deregister_style')) {
            \wp_deregister_style($handle);
        }
    }

    private function dequeueScript(string $handle): void
    {
        if (\is_callable($this->scriptDequeuer)) {
            ($this->scriptDequeuer)($handle);
            return;
        }

        if (\function_exists('wp_dequeue_script')) {
            \wp_dequeue_script($handle);
        }
    }

    private function getEnv(string $name): string|false
    {
        if (\is_callable($this->envGetter)) {
            return ($this->envGetter)($name);
        }

        return \getenv($name, true);
    }

    private function getCurrentUser(): ?object
    {
        if (\is_callable($this->currentUserGetter)) {
            $user = ($this->currentUserGetter)();
            return \is_object($user) ? $user : null;
        }

        $user = \function_exists('wp_get_current_user') ? \wp_get_current_user() : null;
        return \is_object($user) ? $user : null;
    }

    private function isSuperAdmin(?object $user = null): bool
    {
        if (\is_callable($this->superAdminChecker)) {
            return ($this->superAdminChecker)($user);
        }

        return false;
    }

    private function getCurrentScreen(): ?object
    {
        if (\is_callable($this->currentScreenGetter)) {
            return ($this->currentScreenGetter)();
        }

        return \function_exists('get_current_screen') ? \get_current_screen() : null;
    }

    private function echoHtml(string $html): void
    {
        if (\is_callable($this->htmlEchoer)) {
            ($this->htmlEchoer)($html);
            return;
        }

        echo $html;
    }

    private function isMethodCallable(object $object, string $method): bool
    {
        return \is_callable([$object, $method]);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function callObjectMethod(object $object, string $method, array $arguments = []): mixed
    {
        /** @var callable $callable */
        $callable = [$object, $method];
        return \call_user_func_array($callable, $arguments);
    }

    private function addFilter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\is_callable($this->filterAdder)) {
            ($this->filterAdder)($hookName, $callback, $priority, $acceptedArgs);
            return;
        }

        if (\function_exists('add_filter')) {
            \add_filter($hookName, $callback, $priority, $acceptedArgs);
        }
    }
}
