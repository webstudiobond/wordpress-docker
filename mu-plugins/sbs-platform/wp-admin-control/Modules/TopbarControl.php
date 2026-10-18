<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Manages WordPress top administrative bar (WP_Admin_Bar) customization, node removal,
 * custom topbar dropdown menus, node reordering, profile access lock, and topbar CSS styling.
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
final class TopbarControl
{
    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(): bool)|null $adminChecker
     * @param (callable(): int)|null $queriedObjectIdGetter
     * @param (callable(int): bool)|null $postRestrictedChecker
     * @param (callable(): bool)|null $userLoggedInChecker
     * @param (callable(string): void)|null $htmlEchoer
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $adminChecker = null,
        private readonly mixed $queriedObjectIdGetter = null,
        private readonly mixed $postRestrictedChecker = null,
        private readonly mixed $userLoggedInChecker = null,
        private readonly mixed $htmlEchoer = null,
    ) {
    }

    public function shouldRemoveUserProfile(?object $user = null): bool
    {
        $raw = $this->getEnv('WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE');
        if ($raw === false || \trim($raw) === '' || $this->ruleParser === null || $this->userMatcher === null) {
            return false;
        }

        $currentUser = \func_num_args() > 0 ? $user : $this->getCurrentUser();
        if ($currentUser === null) {
            return false;
        }

        $rules = ($this->ruleParser)('profile|hide:' . \trim($raw));
        return $rules !== [] && ($this->userMatcher)($rules[0], $currentUser);
    }

    /**
     * @return list<string>
     */
    public function getRemovedTopbarNodeIdsForCurrentUser(?object $user = null): array
    {
        $currentUser = $user ?? $this->getCurrentUser();
        if ($currentUser === null) {
            return [];
        }

        $removeNodesEnv = $this->getEnv('WP_ADMIN_TOPBAR_MENU_REMOVE');
        if (
            $removeNodesEnv === false
            || \trim($removeNodesEnv) === ''
            || $this->ruleParser === null
            || $this->userMatcher === null
        ) {
            return [];
        }


        $rules = ($this->ruleParser)(\trim($removeNodesEnv));
        $removedIds = [];
        foreach ($rules as $rule) {
            if (($this->userMatcher)($rule, $currentUser)) {
                foreach ($rule['targets'] as $nodeId) {
                    $trimmed = \trim($nodeId);
                    if ($trimmed !== '') {
                        $removedIds[] = $trimmed;
                    }
                }
            }
        }

        return \array_values(\array_unique($removedIds));
    }

    public function resolveAdminUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (\str_starts_with($url, 'http://') || \str_starts_with($url, 'https://') || \str_starts_with($url, '//')) {
            return $url;
        }

        if (\str_starts_with($url, '/')) {
            return $url;
        }

        return \function_exists('admin_url') ? \admin_url($url) : $url;
    }

    public function registerTopbarCustomMenus(object $wpAdminBar, ?object $currentUser = null): void
    {
        if (!$this->isMethodCallable($wpAdminBar, 'add_node')) {
            return;
        }

        $targetUser = $currentUser ?? $this->getCurrentUser();

        for ($i = 1; $i <= 20; $i++) {
            $prefix = 'WP_ADMIN_TOPBAR_CUSTOM_MENU_' . $i . '_';
            $titleRaw = $this->getEnv($prefix . 'TITLE');
            if ($titleRaw === false || \trim($titleRaw) === '') {
                continue;
            }

            $targetRaw = $this->getEnv($prefix . 'TARGET');
            if (
                $targetRaw !== false
                && \trim($targetRaw) !== ''
                && $this->ruleParser !== null
                && $this->userMatcher !== null
            ) {
                $targetRules = ($this->ruleParser)('menu|' . \trim($targetRaw));
                if ($targetRules !== [] && !($this->userMatcher)($targetRules[0], $targetUser)) {
                    continue;
                }
            }

            $scopeRaw = $this->getEnv($prefix . 'SCOPE');
            $scope = ($scopeRaw !== false && \trim($scopeRaw) !== '')
                ? \strtolower(\trim($scopeRaw))
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
            $menuId = $idRaw !== false && \trim($idRaw) !== ''
                ? \trim($idRaw)
                : 'custom-topbar-menu-' . $i;

            $iconRaw = $this->getEnv($prefix . 'ICON');
            $icon = $iconRaw !== false ? \trim($iconRaw) : '';
            $iconHtml = '';
            if ($icon !== '') {
                $cleanIcon = \preg_replace('/[^a-zA-Z0-9_\-]/', '', \strip_tags($icon));
                if ($cleanIcon !== null && $cleanIcon !== '') {
                    $iconHtml = '<span class="ab-icon dashicons ' . $cleanIcon . '"></span>';
                }
            }

            $title = \trim($titleRaw);
            $rootTitle = $iconHtml
                . '<span class="ab-label">'
                . \htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span>';


            $this->callObjectMethod($wpAdminBar, 'add_node', [[
                'id' => $menuId,
                'title' => $rootTitle,
                'href' => false,
                'meta' => ['class' => 'menupop'],
            ]]);

            $itemsRaw = $this->getEnv($prefix . 'ITEMS');
            if ($itemsRaw === false || \trim($itemsRaw) === '') {
                continue;
            }

            $registeredGroups = [];
            $itemTokens = \explode(';', \trim($itemsRaw));
            $itemIndex = 1;

            foreach ($itemTokens as $token) {
                $trimmedToken = \trim($token);
                if ($trimmedToken === '') {
                    continue;
                }

                $groupName = null;
                $itemDef = $trimmedToken;

                if (\preg_match('/^\[(.*?)\]\s*(.+)$/u', $trimmedToken, $matches)) {
                    $groupName = \trim($matches[1]);
                    $itemDef = \trim($matches[2]);
                }

                $parts = \explode('|', $itemDef, 2);
                $itemTitle = \trim($parts[0]);
                $itemUrl = isset($parts[1]) ? \trim($parts[1]) : '';

                if ($itemTitle === '') {
                    continue;
                }

                $resolvedUrl = $this->resolveAdminUrl($itemUrl);
                $parentId = $menuId;

                if ($groupName !== null && $groupName !== '') {
                    $groupSlug = \preg_replace('/[^a-zA-Z0-9_\-]/', '-', \strtolower($groupName));
                    $groupSlugClean = \is_string($groupSlug) ? \trim($groupSlug, '-') : 'group';
                    $groupId = $menuId . '-group-' . $groupSlugClean;

                    if (!isset($registeredGroups[$groupId])) {
                        $this->callObjectMethod($wpAdminBar, 'add_node', [[
                            'id' => $groupId,
                            'parent' => $menuId,
                            'title' => \htmlspecialchars($groupName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
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
                    'title' => \htmlspecialchars($itemTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
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
            if (\is_object($myAccount) || \is_array($myAccount)) {
                $accountArgs = (array) $myAccount;
                $accountArgs['href'] = false;
                $this->callObjectMethod($wpAdminBar, 'remove_node', ['my-account']);
                $this->callObjectMethod($wpAdminBar, 'add_node', [$accountArgs]);
            }
        }

        $removedNodeIds = $this->getRemovedTopbarNodeIdsForCurrentUser($currentUser);
        foreach ($removedNodeIds as $nodeId) {
            $this->callObjectMethod($wpAdminBar, 'remove_node', [$nodeId]);
        }

        $newRemoveEnv = $this->getEnv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_REMOVE');
        if (
            $newRemoveEnv !== false
            && \trim($newRemoveEnv) !== ''
            && $this->ruleParser !== null
            && $this->userMatcher !== null
        ) {
            $newRules = ($this->ruleParser)(\trim($newRemoveEnv));
            foreach ($newRules as $rule) {
                if (($this->userMatcher)($rule, $currentUser)) {
                    foreach ($rule['targets'] as $childId) {
                        $this->callObjectMethod($wpAdminBar, 'remove_node', [$childId]);
                    }
                }
            }
        }

        $newOrderEnv = $this->getEnv('WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER');
        if (
            $newOrderEnv !== false
            && \trim($newOrderEnv) !== ''
            && $this->isMethodCallable($wpAdminBar, 'add_node')
        ) {
            $orderedIds = [];
            foreach (\explode(',', \trim($newOrderEnv)) as $orderedId) {
                $trimmedId = \trim($orderedId);
                if ($trimmedId !== '') {
                    $orderedIds[] = $trimmedId;
                }
            }

            if ($orderedIds !== []) {
                $rawNodes = $this->callObjectMethod($wpAdminBar, 'get_nodes');

                $childNodes = [];
                if (\is_array($rawNodes)) {
                    foreach ($rawNodes as $nodeKey => $nodeObj) {
                        if (\is_object($nodeObj) && ((string) ($nodeObj->parent ?? '')) === 'new-content') {
                            $id = (string) ($nodeObj->id ?? $nodeKey);
                            $childNodes[$id] = (array) $nodeObj;
                        }
                    }
                }

                if ($childNodes !== []) {
                    foreach (\array_keys($childNodes) as $childId) {
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
        if ($raw === false || \trim($raw) === '') {
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
        foreach (\explode(',', \trim($raw)) as $chunk) {
            $parts = \explode(':', \trim($chunk), 2);
            if (\count($parts) === 2 && \is_numeric(\trim($parts[1]))) {
                $nodeId = \trim($parts[0]);
                if ($nodeId !== '') {
                    $rules[$nodeId] = (float) \trim($parts[1]);
                }
            }
        }

        if ($rules === []) {
            return;
        }

        $allNodes = $this->callObjectMethod($wpAdminBar, 'get_nodes');
        if (!\is_array($allNodes)) {
            return;
        }

        /** @var array<string, object> $rootNodes */
        $rootNodes = [];
        foreach ($allNodes as $id => $node) {
            if (!\is_object($node)) {
                continue;
            }
            $nodeData = (array) $node;
            $nodeId = (string) $id;
            if ($nodeId === '' && isset($nodeData['id']) && \is_scalar($nodeData['id'])) {
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
                $key = ($offset === 0 && (float) (int) $pos === $pos)
                    ? (int) $pos
                    : \sprintf('%.2f', $pos + ($offset * 0.01));
                $offset++;
            } while (isset($assigned[(string) $key]));
            $assigned[(string) $key] = true;
            $orderedRoots[(string) $key] = $exp['node'];
        }

        foreach ($native as $nat) {
            $pos = $nat['pos'];
            $offset = 0;
            do {
                $key = ($offset === 0 && (float) (int) $pos === $pos)
                    ? (int) $pos
                    : \sprintf('%.2f', $pos + ($offset * 0.01));
                $offset++;
            } while (isset($assigned[(string) $key]));
            $assigned[(string) $key] = true;
            $orderedRoots[(string) $key] = $nat['node'];
        }


        \ksort($orderedRoots, \SORT_NUMERIC);

        foreach (\array_keys($rootNodes) as $rootId) {
            $this->callObjectMethod($wpAdminBar, 'remove_node', [$rootId]);
        }

        foreach ($orderedRoots as $rootNode) {
            $this->callObjectMethod($wpAdminBar, 'add_node', [(array) $rootNode]);
        }
    }

    public function handleBeforeAdminBarRender(): void
    {
        $wpAdminBar = $GLOBALS['wp_admin_bar'] ?? null;
        if (!\is_object($wpAdminBar)) {
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

        $removedNodeIds = $this->getRemovedTopbarNodeIdsForCurrentUser($currentUser);
        foreach ($removedNodeIds as $nodeId) {
            $this->callObjectMethod($wpAdminBar, 'remove_node', [$nodeId]);
        }
    }

    public function renderLockProfileStyles(?object $currentUser = null): void
    {
        if ($this->shouldRemoveUserProfile($currentUser)) {
            $this->echoHtml(
                '<style id="wp-admin-control-lock-profile">'
                . '#wp-admin-bar-my-account > a.ab-item { pointer-events: none !important;'
                . ' cursor: default !important; }'
                . '#wp-admin-bar-user-info { display: none !important; }'
                . '</style>'
            );
        }
    }


    public function renderCustomIconStyles(): void
    {
        $this->echoHtml(
            '<style id="wp-admin-control-topbar-custom-icon">'
            . '#wpadminbar .ab-top-menu > li.menupop .ab-icon.dashicons,'
            . ' #wpadminbar .ab-top-menu > li.menupop .ab-icon.dashicons:before {'
            . ' font-size: 16px !important; width: 16px !important; height: 16px !important;'
            . ' line-height: 18px !important; vertical-align: middle !important;'
            . ' margin-right: 4px !important; }'
            . '</style>'
        );
    }

    public function renderHideTopbarNodesStyles(?object $currentUser = null): void
    {
        $removedTopbarNodes = $this->getRemovedTopbarNodeIdsForCurrentUser($currentUser);
        if ($removedTopbarNodes === []) {
            return;
        }

        $topbarSelectors = [];
        foreach ($removedTopbarNodes as $nodeId) {
            if (\preg_match('/^[a-zA-Z0-9_\-]+$/', $nodeId)) {
                $topbarSelectors[] = '#wp-admin-bar-' . $nodeId;
            }
        }

        if ($topbarSelectors !== []) {
            $this->echoHtml(\sprintf(
                '<style id="wp-admin-control-hide-topbar-nodes">%s { display: none !important; }</style>',
                \implode(', ', $topbarSelectors)
            ));
        }
    }

    public function handleWpHead(): void
    {
        if (!$this->isUserLoggedIn()) {
            return;
        }

        $currentUser = $this->getCurrentUser();
        $this->renderLockProfileStyles($currentUser);
        $this->renderCustomIconStyles();

        $removedTopbarNodes = $this->getRemovedTopbarNodeIdsForCurrentUser($currentUser);
        $topbarSelectors = [];
        foreach ($removedTopbarNodes as $nodeId) {
            if (\preg_match('/^[a-zA-Z0-9_\-]+$/', $nodeId)) {
                $topbarSelectors[] = '#wp-admin-bar-' . $nodeId;
            }
        }

        $queriedId = $this->getQueriedObjectId();
        if ($queriedId > 0 && $this->isPostRestrictedForCurrentUser($queriedId)) {
            $topbarSelectors[] = '#wp-admin-bar-edit';
        }

        if ($topbarSelectors !== []) {
            $this->echoHtml(\sprintf(
                '<style id="wp-admin-control-hide-topbar-nodes">%s { display: none !important; }</style>',
                \implode(', ', \array_values(\array_unique($topbarSelectors)))
            ));
        }
    }

    private function isMethodCallable(object $object, string $method): bool
    {
        return \method_exists($object, $method) || \is_callable([$object, $method]);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function callObjectMethod(object $object, string $method, array $args = []): mixed
    {
        if (!$this->isMethodCallable($object, $method)) {
            return null;
        }


        return $object->{$method}(...$args);
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

        return \function_exists('wp_get_current_user') ? \wp_get_current_user() : null;
    }

    private function isAdmin(): bool
    {
        if ($this->adminChecker !== null) {
            return (bool) ($this->adminChecker)();
        }

        return \function_exists('is_admin') && \is_admin();
    }

    private function isUserLoggedIn(): bool
    {
        if ($this->userLoggedInChecker !== null) {
            return (bool) ($this->userLoggedInChecker)();
        }

        return \function_exists('is_user_logged_in') && \is_user_logged_in();
    }

    private function getQueriedObjectId(): int
    {
        if ($this->queriedObjectIdGetter !== null) {
            return (int) ($this->queriedObjectIdGetter)();
        }

        return \function_exists('get_queried_object_id') ? (int) \get_queried_object_id() : 0;
    }

    private function isPostRestrictedForCurrentUser(int $postId): bool
    {
        if ($this->postRestrictedChecker !== null) {
            return (bool) ($this->postRestrictedChecker)($postId);
        }

        return false;
    }

    private function echoHtml(string $html): void
    {
        if (\is_callable($this->htmlEchoer)) {
            ($this->htmlEchoer)($html);
            return;
        }

        echo $html;
    }
}
