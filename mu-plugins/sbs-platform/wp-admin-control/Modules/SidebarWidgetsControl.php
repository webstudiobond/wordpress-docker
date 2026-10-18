<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Manages WordPress sidebar widget unregistration and role-based widget restrictions.
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
final class SidebarWidgetsControl
{
    /**
     * @var array<string, string>
     */
    public const array STANDARD_WIDGETS_MAP = [
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
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(string): void)|null $widgetUnregisterer
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $widgetUnregisterer = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getWidgetsToRemoveForUser(?object $user = null): array
    {
        $raw = $this->getEnv('WP_ADMIN_SIDEBAR_WIDGETS_REMOVE');
        if ($raw === false || \trim($raw) === '') {
            return [];
        }

        if ($this->ruleParser === null || $this->userMatcher === null) {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = ($this->ruleParser)($raw);
        $widgets = [];
        foreach ($rules as $rule) {
            if (($this->userMatcher)($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = \trim($target);
                    if ($trimmed !== '') {
                        $widgets[] = $trimmed;
                        $norm = \str_replace('-', '_', \strtolower($trimmed));
                        if (isset(self::STANDARD_WIDGETS_MAP[$norm])) {
                            $widgets[] = self::STANDARD_WIDGETS_MAP[$norm];
                        }
                    }
                }
            }
        }

        return \array_values(\array_unique($widgets));
    }

    public function handleWidgetsInit(): void
    {
        $currentUser = $this->getCurrentUser();
        $widgetsToRemove = $this->getWidgetsToRemoveForUser($currentUser);
        foreach ($widgetsToRemove as $widget) {
            $this->unregisterWidget($widget);
        }
    }

    private function unregisterWidget(string $widgetClass): void
    {
        if (\is_callable($this->widgetUnregisterer)) {
            ($this->widgetUnregisterer)($widgetClass);
            return;
        }

        if (\function_exists('unregister_widget')) {
            \unregister_widget($widgetClass);
        }
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
