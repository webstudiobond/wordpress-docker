<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Manages Gutenberg block editor filtering, panel suppression, and settings hardening.
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
final class GutenbergControl
{
    /**
     * @var array<string, string>
     */
    public const array STANDARD_BLOCK_ALIASES = [
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
    public const array STANDARD_CATEGORIES = [
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

    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(): list<string>)|null $registeredBlockTypesGetter
     * @param (callable(string): list<string>)|null $hiddenTargetsGetter
     * @param (callable(string, string, string): bool)|null $inlineScriptAdder
     * @param (callable(string): void)|null $htmlEchoer
     * @param (callable(?object): list<string>)|null $blocksResolver
     * @param (callable(string): (?string))|null $blockTypeCategoryGetter
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $registeredBlockTypesGetter = null,
        private readonly mixed $hiddenTargetsGetter = null,
        private readonly mixed $inlineScriptAdder = null,
        private readonly mixed $htmlEchoer = null,
        private readonly mixed $blocksResolver = null,
        private readonly mixed $blockTypeCategoryGetter = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getBlocksToRemoveForUser(?object $user = null): array
    {
        if ($this->blocksResolver !== null) {
            /** @var list<string> */
            return ($this->blocksResolver)($user);
        }

        $raw = $this->getEnv('WP_ADMIN_GUTENBERG_BLOCKS_REMOVE');
        if ($raw === false || \trim($raw) === '') {
            return [];
        }

        if ($this->ruleParser === null || $this->userMatcher === null) {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = ($this->ruleParser)($raw);
        $blocks = [];
        foreach ($rules as $rule) {
            if (($this->userMatcher)($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = \strtolower(\trim($target));
                    if ($trimmed === '') {
                        continue;
                    }
                    if (\str_starts_with($trimmed, 'category:')) {
                        $cat = \substr($trimmed, 9);
                        $blocks[] = 'category:' . $cat;
                        if (isset(self::STANDARD_CATEGORIES[$cat])) {
                            foreach (self::STANDARD_CATEGORIES[$cat] as $catBlock) {
                                $blocks[] = $catBlock;
                                $blocks[] = \substr($catBlock, 5);
                            }
                        }
                        continue;
                    }
                    if (isset(self::STANDARD_BLOCK_ALIASES[$trimmed])) {
                        $canonical = self::STANDARD_BLOCK_ALIASES[$trimmed];
                        $blocks[] = $canonical;
                        $blocks[] = \substr($canonical, 5);
                        $blocks[] = $trimmed;
                        continue;
                    }
                    $normalizedNoSlash = \str_replace('/', '', $trimmed);
                    if (isset(self::STANDARD_BLOCK_ALIASES[$normalizedNoSlash])) {
                        $canonical = self::STANDARD_BLOCK_ALIASES[$normalizedNoSlash];
                        $blocks[] = $canonical;
                        $blocks[] = \substr($canonical, 5);
                        $blocks[] = $trimmed;
                        continue;
                    }
                    $blocks[] = $trimmed;
                    if (!\str_contains($trimmed, '/') && !\str_contains($trimmed, '*')) {
                        $blocks[] = 'core/' . $trimmed;
                    } elseif (\str_starts_with($trimmed, 'core/')) {
                        $blocks[] = \substr($trimmed, 5);
                    }
                }
            }
        }

        return \array_values(\array_unique($blocks));
    }

    /**
     * @param bool|array<int|string, mixed> $allowedBlockTypes
     * @param mixed $blockEditorContext
     * @param list<string>|null $blocksToRemove
     * @return bool|list<string>
     */
    public function filterAllowedBlockTypesAll(
        bool|array $allowedBlockTypes,
        mixed $blockEditorContext = null,
        ?array $blocksToRemove = null
    ): bool|array {
        $toRemove = $blocksToRemove ?? $this->getBlocksToRemoveForUser();
        if ($toRemove === []) {
            if (\is_bool($allowedBlockTypes)) {
                return $allowedBlockTypes;
            }
            $result = [];
            foreach ($allowedBlockTypes as $item) {
                if (\is_string($item)) {
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

        foreach ($toRemove as $blockTarget) {
            if (\str_starts_with($blockTarget, 'category:')) {
                $categoriesToRemove[] = \substr($blockTarget, 9);
            } elseif (\str_contains($blockTarget, '*')) {
                $wildcardsToRemove[] = $blockTarget;
            } else {
                $exactToRemove[] = $blockTarget;
            }
        }

        $cleanAllowed = [];
        foreach ($allowedBlockTypes as $item) {
            if (!\is_string($item)) {
                continue;
            }
            $lowerItem = \strtolower(\trim($item));
            if (\in_array($lowerItem, $exactToRemove, true)) {
                continue;
            }
            $matchedWildcard = false;
            foreach ($wildcardsToRemove as $pattern) {
                if (\fnmatch($pattern, $lowerItem)) {
                    $matchedWildcard = true;
                    break;
                }
            }
            if ($matchedWildcard) {
                continue;
            }
            if ($categoriesToRemove !== []) {
                $cat = $this->getBlockCategory($item);
                if ($cat !== null && \in_array(\strtolower($cat), $categoriesToRemove, true)) {
                    continue;
                }
            }
            $cleanAllowed[] = $item;
        }

        return $cleanAllowed;
    }

    public function resolveGutenbergPanelId(string $target): ?string
    {
        $normalized = \strtolower(\trim($target));
        if ($normalized === '') {
            return null;
        }

        if (\str_starts_with($normalized, 'taxonomy-panel-')) {
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

    /**
     * @param list<string>|null $hiddenTargets
     * @return list<string>
     */
    public function getPanelsToHide(?array $hiddenTargets = null): array
    {
        $targets = $hiddenTargets ?? $this->getHiddenTargets();
        if ($targets === []) {
            return [];
        }

        $panels = [];
        foreach ($targets as $target) {
            $panelId = $this->resolveGutenbergPanelId($target);
            if ($panelId !== null) {
                $panels[] = $panelId;
            }
        }

        return \array_values(\array_unique($panels));
    }

    /**
     * @param list<string>|null $panels
     */
    public function handleBlockEditorAssets(?array $panels = null): void
    {
        $panelsToHide = $panels ?? $this->getPanelsToHide();
        if ($panelsToHide === []) {
            return;
        }

        $jsonPanels = \json_encode($panelsToHide, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($jsonPanels === false) {
            return;
        }

        $script = \sprintf(
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
     * @param list<string>|null $panels
     */
    public function handleAdminPrintFooterScripts(?array $panels = null): void
    {
        $panelsToHide = $panels ?? $this->getPanelsToHide();
        if ($panelsToHide === []) {
            return;
        }

        $jsonPanels = \json_encode($panelsToHide, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($jsonPanels === false) {
            return;
        }

        $this->echoHtml(\sprintf(
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
     * @param array<string, mixed> $settings
     * @param mixed $context
     * @param list<string>|null $hiddenTargets
     * @return array<string, mixed>
     */
    public function filterBlockEditorSettingsAll(
        array $settings,
        mixed $context = null,
        ?array $hiddenTargets = null
    ): array {
        $targets = $hiddenTargets ?? $this->getHiddenTargets();
        if ($targets === []) {
            return $settings;
        }

        foreach ($targets as $target) {
            $norm = \strtolower(\trim($target));
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
     * @return list<string>
     */
    public function getRegisteredBlockTypes(): array
    {
        if ($this->registeredBlockTypesGetter !== null) {
            return ($this->registeredBlockTypesGetter)();
        }

        if (\class_exists('WP_Block_Type_Registry')) {
            $registry = \WP_Block_Type_Registry::get_instance();
            /** @var array<array-key, mixed> $registered */
            $registered = $registry->get_all_registered();
            $names = [];
            foreach (\array_keys($registered) as $name) {
                $names[] = (string) $name;
            }
            return $names;
        }

        return [];
    }

    private function getBlockCategory(string $blockName): ?string
    {
        if ($this->blockTypeCategoryGetter !== null) {
            return ($this->blockTypeCategoryGetter)($blockName);
        }

        if (\class_exists('WP_Block_Type_Registry')) {
            $registry = \WP_Block_Type_Registry::get_instance();
            $blockType = $registry->get_registered($blockName);
            if ($blockType !== null && isset($blockType->category) && \is_scalar($blockType->category)) {
                return (string) $blockType->category;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function getHiddenTargets(): array
    {
        if ($this->hiddenTargetsGetter !== null) {
            return ($this->hiddenTargetsGetter)('WP_ADMIN_HIDDEN_META_BOXES');
        }

        return [];
    }

    private function addInlineScript(string $handle, string $data, string $position = 'after'): bool
    {
        if ($this->inlineScriptAdder !== null) {
            return ($this->inlineScriptAdder)($handle, $data, $position);
        }

        if (\function_exists('wp_add_inline_script')) {
            return \wp_add_inline_script($handle, $data, $position);
        }

        return false;
    }

    private function echoHtml(string $html): void
    {
        if ($this->htmlEchoer !== null) {
            ($this->htmlEchoer)($html);
            return;
        }

        echo $html;
    }

    private function getEnv(string $name): string|false
    {
        if ($this->envGetter !== null) {
            return ($this->envGetter)($name);
        }

        return \getenv($name);
    }

    private function getCurrentUser(): ?object
    {
        if ($this->currentUserGetter !== null) {
            return ($this->currentUserGetter)();
        }

        return null;
    }
}
