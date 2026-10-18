<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Manages WordPress administrative dashboard widgets removal, welcome panel visibility,
 * custom dashboard widgets registration, and micro-Markdown rendering.
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
final class DashboardControl
{
    private bool $isBufferingStarted = false;

    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(string, callable|string, int): void)|null $actionRemover
     * @param (callable(string, string|object, string): void)|null $metaBoxRemover
     * @param callable|null $dashboardWidgetAdder
     * @param (callable(string): void)|null $htmlEchoer
     * @param (callable(): string)|null $pageNowGetter
     * @param (callable(callable): bool)|null $bufferStarter
     * @param (callable(string): void)|null $scriptDequeuer
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $actionRemover = null,
        private readonly mixed $metaBoxRemover = null,
        private readonly mixed $dashboardWidgetAdder = null,
        private readonly mixed $htmlEchoer = null,
        private readonly mixed $pageNowGetter = null,
        private readonly mixed $bufferStarter = null,
        private readonly mixed $scriptDequeuer = null,
    ) {
    }

    public function handleDashboardSetup(): void
    {
        $currentUser = $this->getCurrentUser();
        $removeWidgetsEnv = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE');
        if (
            $removeWidgetsEnv !== false
            && \trim($removeWidgetsEnv) !== ''
            && $this->ruleParser !== null
            && $this->userMatcher !== null
        ) {
            $rules = ($this->ruleParser)(\trim($removeWidgetsEnv));
            foreach ($rules as $rule) {
                if (($this->userMatcher)($rule, $currentUser)) {
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

        for ($index = 1;; $index++) {
            $titleRaw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGET_' . $index . '_TITLE');
            if ($titleRaw === false || \trim($titleRaw) === '') {
                break;
            }

            $title = \strip_tags(\trim($titleRaw));
            $targetRaw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGET_' . $index . '_TARGET');
            $targetRuleStr = ($targetRaw !== false && \trim($targetRaw) !== '')
                ? 'widget|' . \trim($targetRaw)
                : 'widget|all';
            $parsedTargetRules = $this->ruleParser !== null ? ($this->ruleParser)($targetRuleStr) : [];

            $isAllowed = true;
            if ($parsedTargetRules !== [] && $this->userMatcher !== null) {
                $isAllowed = ($this->userMatcher)($parsedTargetRules[0], $currentUser);
            }

            if (!$isAllowed) {
                continue;
            }

            $contentRaw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGET_' . $index . '_CONTENT');
            $content = $contentRaw !== false ? \trim($contentRaw) : '';
            $renderedHtml = $this->renderMicroMarkdown($content);

            $widgetId = 'wp_admin_control_widget_' . $index;
            $this->addDashboardWidget($widgetId, $title, function () use ($renderedHtml): void {
                $this->echoHtml($renderedHtml);
            });
        }
    }

    public function shouldHideWelcomePanel(?object $user = null): bool
    {
        $raw = $this->getEnv('WP_ADMIN_DASHBOARD_WIDGETS_REMOVE');
        if ($raw === false || \trim($raw) === '') {
            return false;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        if ($this->ruleParser === null || $this->userMatcher === null) {
            return false;
        }

        $rules = ($this->ruleParser)(\trim($raw));
        foreach ($rules as $rule) {
            if (\in_array('welcome-panel', $rule['targets'], true) && ($this->userMatcher)($rule, $currentUser)) {
                return true;
            }
        }

        return false;
    }

    public function handleAdminHead(): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->shouldHideWelcomePanel($currentUser)) {
            $this->echoHtml(
                '<style id="wp-admin-control-hide-welcome-panel">'
                . '#welcome-panel, .welcome-panel, label[for="wp_welcome_panel-hide"] { display: none !important; }'
                . '</style>'
            );
        }
    }

    public function shouldHideScreenOptionsAndHelp(?object $user = null): bool
    {
        $raw = $this->getEnv('WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP');
        if ($raw === false || \trim($raw) === '') {
            return false;
        }

        $currentUser = $user ?? $this->getCurrentUser();
        if ($this->ruleParser === null || $this->userMatcher === null) {
            return false;
        }

        $targetRuleStr = \trim($raw) !== '' ? 'screen_options|' . \trim($raw) : 'screen_options|all';
        $rules = ($this->ruleParser)($targetRuleStr);
        if ($rules === []) {
            return false;
        }

        return ($this->userMatcher)($rules[0], $currentUser);
    }

    public function hasActiveDashboardWidgets(): bool
    {
        $metaBoxes = $GLOBALS['wp_meta_boxes'] ?? null;
        if (!\is_array($metaBoxes)) {
            return false;
        }

        $dashboardBoxes = $metaBoxes['dashboard'] ?? null;
        if (!\is_array($dashboardBoxes)) {
            return false;
        }

        foreach ($dashboardBoxes as $context) {
            if (!\is_array($context)) {
                continue;
            }
            foreach ($context as $priority) {
                if (!\is_array($priority)) {
                    continue;
                }
                foreach ($priority as $box) {
                    if (\is_array($box) && $box !== []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function isBufferingStarted(): bool
    {
        return $this->isBufferingStarted;
    }

    public function startOutputBuffering(): void
    {
        if ($this->isBufferingStarted) {
            return;
        }

        $this->isBufferingStarted = true;
        $handler = function (string $buffer): string {
            return $this->filterBuffer($buffer);
        };

        if (\is_callable($this->bufferStarter)) {
            ($this->bufferStarter)($handler);
            return;
        }

        \ob_start($handler);
    }

    public function handleAdminInit(): void
    {
        $pagenow = $this->getPageNow();
        if ($pagenow !== 'index.php') {
            return;
        }

        if ($this->shouldHideScreenOptionsAndHelp()) {
            $this->startOutputBuffering();
        }
    }

    public function handleAdminEnqueueScripts(): void
    {
        $pagenow = $this->getPageNow();
        if ($pagenow !== 'index.php') {
            return;
        }

        if ($this->shouldHideScreenOptionsAndHelp() && !$this->hasActiveDashboardWidgets()) {
            $this->dequeueScript('dashboard');
        }
    }

    public function filterBuffer(string $buffer): string
    {
        if (!$this->shouldHideScreenOptionsAndHelp()) {
            return $buffer;
        }

        return $this->cleanDashboardWidgetsContainer($buffer);
    }

    public function cleanDashboardWidgetsContainer(string $buffer): string
    {
        if (
            \preg_match(
                '/<div\b[^>]*\bid=["\']dashboard-widgets-wrap["\'][^>]*>/i',
                $buffer,
                $match,
                \PREG_OFFSET_CAPTURE
            ) !== 1
        ) {
            return $buffer;
        }

        $startPos = (int) $match[0][1];
        $commentNeedle = '<!-- dashboard-widgets-wrap -->';
        $commentPos = \strpos($buffer, $commentNeedle, $startPos);
        $endPos = false;
        if ($commentPos !== false) {
            $endPos = $commentPos + \strlen($commentNeedle);
        } else {
            $endPos = $this->findClosingDivPosition($buffer, $startPos);
        }

        if ($endPos === false) {
            return $buffer;
        }

        $containerHtml = \substr($buffer, $startPos, $endPos - $startPos);
        if (\preg_match('/class=["\'][^"\']*(?<![a-zA-Z0-9_-])postbox(?![a-zA-Z0-9_-])/i', $containerHtml) === 1) {
            return $buffer;
        }

        return \substr($buffer, 0, $startPos) . \substr($buffer, $endPos);
    }

    public function findClosingDivPosition(string $html, int $startPos): int|false
    {
        $depth = 0;
        $pos = $startPos;

        while (true) {
            $nextOpen = \stripos($html, '<div', $pos);
            $nextClose = \stripos($html, '</div>', $pos);

            if ($nextClose === false) {
                return false;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 4;
            } else {
                $depth--;
                $pos = $nextClose + 6;
                if ($depth === 0) {
                    return $pos;
                }
            }
        }
    }

    public function renderMicroMarkdown(string $markdown): string
    {
        if (\trim($markdown) === '') {
            return '';
        }

        $normalized = \str_replace(['\r\n', '\r', '\n'], "\n", $markdown);
        $normalized = \str_replace(["\r\n", "\r"], "\n", $normalized);

        $html = \htmlspecialchars($normalized, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $html = (string) \preg_replace(
            '/\[(.*?)\]\((https:\/\/[^\s\)\"\']+)\)/',
            '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
            $html
        );
        $html = (string) \preg_replace(
            '/\[(.*?)\]\((mailto:[^\s\)\"\']+)\)/',
            '<a href="$2">$1</a>',
            $html
        );

        $html = (string) \preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $html = (string) \preg_replace('/\*([^\*\n]+?)\*/', '<em>$1</em>', $html);

        $lines = \explode("\n", $html);
        $formattedLines = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = \trim($line);
            if (\preg_match('/^(#{1,4})\s+(.+)$/u', $trimmed, $headingMatches) === 1) {
                if ($inList) {
                    $formattedLines[] = '</ul>';
                    $inList = false;
                }
                $level = \min(4, \max(2, \strlen($headingMatches[1]) + 1));
                $formattedLines[] = \sprintf(
                    '<h%d style="margin: 0.5em 0;">%s</h%d>',
                    $level,
                    $headingMatches[2],
                    $level
                );
                continue;
            }

            if (\str_starts_with($trimmed, '- ')) {
                if (!$inList) {
                    $formattedLines[] = '<ul>';
                    $inList = true;
                }
                $formattedLines[] = '<li>' . \substr($trimmed, 2) . '</li>';
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

        $result = \implode("\n", $formattedLines);
        return \nl2br($result, false);
    }

    private function removeAction(string $tag, callable|string $callback, int $priority = 10): void
    {
        if (\is_callable($this->actionRemover)) {
            ($this->actionRemover)($tag, $callback, $priority);
            return;
        }

        if (\function_exists('remove_action')) {
            \remove_action($tag, $callback, $priority);
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

    private function addDashboardWidget(string $widgetId, string $widgetName, callable $callback): void
    {
        if (\is_callable($this->dashboardWidgetAdder)) {
            ($this->dashboardWidgetAdder)($widgetId, $widgetName, $callback, null, null, 'normal', 'core');
            return;
        }

        if (\function_exists('wp_add_dashboard_widget')) {
            \wp_add_dashboard_widget($widgetId, $widgetName, $callback);
        }
    }

    private function echoHtml(string $html): void
    {
        if (\is_callable($this->htmlEchoer)) {
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
            $user = ($this->currentUserGetter)();
            return \is_object($user) ? $user : null;
        }

        if (\function_exists('wp_get_current_user')) {
            $user = \wp_get_current_user();
            return \is_object($user) ? $user : null;
        }

        return null;
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

    private function getPageNow(): string
    {
        if ($this->pageNowGetter !== null) {
            return ($this->pageNowGetter)();
        }

        $raw = $GLOBALS['pagenow'] ?? '';
        return \is_string($raw) ? \trim($raw) : '';
    }
}
