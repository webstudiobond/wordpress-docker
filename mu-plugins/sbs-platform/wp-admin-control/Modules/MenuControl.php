<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Governs sidebar menu navigation, menu removal, custom menu ordering,
 * custom separators, sidebar CSS generation, and direct route access enforcement.
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
final class MenuControl
{
    /**
     * @var list<string>
     */
    private const CORE_SHARED_PARENTS = [
        'users.php',
        'tools.php',
        'options-general.php',
        'themes.php',
    ];

    /**
     * @var array<string, string>
     */
    private array $separatorAfterMap = [];

    /**
     * @var list<AdminRule>|null
     */
    private ?array $cachedSidebarRules = null;

    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(AdminRule, ?object): bool)|null $userRestrictedChecker
     * @param (callable(?object): bool)|null $superAdminChecker
     * @param (callable(string): void)|null $menuRemover
     * @param (callable(string, string): void)|null $submenuRemover
     * @param (callable(string): void)|null $dieForbiddenHandler
     * @param (callable(string): void)|null $htmlEchoer
     * @param (callable(): array<string, string>)|null $allEnvGetter
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $userRestrictedChecker = null,
        private readonly mixed $superAdminChecker = null,
        private readonly mixed $menuRemover = null,
        private readonly mixed $submenuRemover = null,
        private readonly mixed $dieForbiddenHandler = null,
        private readonly mixed $htmlEchoer = null,
        private readonly mixed $allEnvGetter = null,
    ) {
    }

    /**
     * @return array{
     *     hide: list<string>,
     *     block: list<string>
     * }
     */
    public function getSidebarMenuControlForCurrentUser(?object $user = null): array
    {
        $currentUser = $user ?? $this->getCurrentUser();
        if ($currentUser === null) {
            return ['hide' => [], 'block' => []];
        }

        if ($this->cachedSidebarRules === null) {
            $rawParts = $this->getSidebarMenuControlRawParts();
            $combined = \implode('; ', $rawParts);
            $this->cachedSidebarRules = ($combined !== '' && $this->ruleParser !== null)
                ? ($this->ruleParser)($combined)
                : [];
        }

        if ($this->cachedSidebarRules === []) {
            return ['hide' => [], 'block' => []];
        }

        /** @var array<string, 'hide'|'block'> $targetActions */
        $targetActions = [];

        foreach ($this->cachedSidebarRules as $rule) {
            if ($rule['action'] === 'block') {
                if ($this->userRestrictedChecker !== null && !($this->userRestrictedChecker)($rule, $currentUser)) {
                    continue;
                }
                foreach ($rule['targets'] as $target) {
                    $targetActions[$target] = 'block';
                }
            } else {
                if ($this->userMatcher !== null && !($this->userMatcher)($rule, $currentUser)) {
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

    private function isExplicitSubmenuTarget(string $target): bool
    {
        return \str_contains($target, ':')
            && !\str_starts_with($target, 'http://')
            && !\str_starts_with($target, 'https://');
    }

    private function currentUserCan(string $capability): bool
    {
        if (
            $capability === ''
            || $capability === 'exist'
            || (!isset($GLOBALS['test_user_caps']) && $capability === 'read')
        ) {
            return true;
        }

        return \function_exists('current_user_can') ? (bool) \current_user_can($capability) : true;
    }

    /**
     * @param array<int|string, mixed> $subList
     * @param string $parentSlug
     * @param array<string, bool> $removedSubmenuKeys
     * @return bool
     */
    private function hasAccessibleSubmenuItems(
        array $subList,
        string $parentSlug = '',
        array $removedSubmenuKeys = []
    ): bool {
        foreach ($subList as $subItem) {
            if (!\is_array($subItem) || !isset($subItem[2]) || !\is_string($subItem[2])) {
                continue;
            }
            $childSlug = $subItem[2];
            $normalizedChild = \str_replace('&amp;', '&', $childSlug);
            if (
                isset($removedSubmenuKeys[$parentSlug . ':' . $childSlug])
                || isset($removedSubmenuKeys[$parentSlug . ':' . $normalizedChild])
                || isset($removedSubmenuKeys[$childSlug])
                || isset($removedSubmenuKeys[$normalizedChild])
            ) {
                continue;
            }
            $cap = isset($subItem[1]) && \is_string($subItem[1]) ? $subItem[1] : '';
            if ($this->currentUserCan($cap)) {
                return true;
            }
        }
        return false;
    }

    public function isCurrentPluginPageRequest(string $target): bool
    {
        $rawPage = $_POST['page'] ?? $_GET['page'] ?? '';
        if (!\is_string($rawPage) || \trim($rawPage) === '') {
            return false;
        }

        $currentPage = \strtolower(\trim($rawPage));
        $rawTarget = $this->isExplicitSubmenuTarget($target) ? \explode(':', $target, 2)[1] : $target;
        $rawTarget = \trim($rawTarget);

        if (\str_starts_with($rawTarget, 'admin.php?page=')) {
            $rawTarget = \substr($rawTarget, 15);
        }

        if (\str_contains($rawTarget, '?')) {
            $rawTarget = \explode('?', $rawTarget, 2)[0];
        }

        $targetSlug = \strtolower(\trim($rawTarget));
        return $targetSlug !== '' && $currentPage === $targetSlug;
    }

    public function handleAdminMenu(): void
    {
        $currentUser = $this->getCurrentUser();
        $sidebarControl = $this->getSidebarMenuControlForCurrentUser($currentUser);
        $blockedSet = \array_flip($sidebarControl['block']);

        $targetsToHide = [];
        foreach ($sidebarControl['hide'] as $target) {
            $isBlocked = isset($blockedSet[$target]);
            if (!$isBlocked && $this->isCurrentPluginPageRequest($target)) {
                continue;
            }
            $targetsToHide[] = $target;
        }

        if ($targetsToHide !== []) {
            /** @var array<string, bool> $explicitSubmenu */
            $explicitSubmenu = [];
            /** @var array<string, bool> $genericTargets */
            $genericTargets = [];
            /** @var array<string, bool> $bareGenericSlugs */
            $bareGenericSlugs = [];
            /** @var list<array{base: string, params: array<string, string>}> $genericTargetsWithQuery */
            $genericTargetsWithQuery = [];

            foreach ($targetsToHide as $t) {
                if ($this->isExplicitSubmenuTarget($t)) {
                    $explicitSubmenu[$t] = true;
                    $childPart = \strtolower(\trim(\explode(':', $t, 2)[1]));
                    if (\str_starts_with($childPart, 'admin.php?page=')) {
                        $extracted = \substr($childPart, 15);
                        if ($extracted !== '') {
                            $bareGenericSlugs[$extracted] = true;
                        }
                    } elseif (!\str_contains($childPart, '?') && !\str_ends_with($childPart, '.php')) {
                        $bareGenericSlugs[$childPart] = true;
                    }
                } else {
                    $genericTargets[$t] = true;
                    $lowerT = \strtolower(\trim($t));
                    if (\str_contains($lowerT, '?')) {
                        $parts = \explode('?', $lowerT, 2);
                        $base = $parts[0];
                        $queryParams = [];
                        \parse_str($parts[1], $queryParams);
                        $normalizedParams = [];
                        foreach ($queryParams as $qk => $qv) {
                            if (\is_string($qk) && \is_string($qv)) {
                                $normalizedParams[\strtolower(\trim($qk))] = \strtolower(\trim($qv));
                            }
                        }
                        if ($normalizedParams !== []) {
                            $genericTargetsWithQuery[] = [
                                'base' => $base,
                                'params' => $normalizedParams,
                            ];
                        }
                    }
                    if (\str_starts_with($lowerT, 'admin.php?page=')) {
                        $extracted = \substr($lowerT, 15);
                        if ($extracted !== '') {
                            $bareGenericSlugs[$extracted] = true;
                        }
                    } elseif (!\str_contains($lowerT, '?') && !\str_ends_with($lowerT, '.php')) {
                        $bareGenericSlugs[$lowerT] = true;
                    }
                }
            }

            /** @var array<string, bool> $removedSubmenuKeys */
            $removedSubmenuKeys = [];

            if (isset($GLOBALS['submenu']) && \is_array($GLOBALS['submenu'])) {
                /** @var array<string, mixed> $initialSubmenu */
                $initialSubmenu = $GLOBALS['submenu'];
                foreach ($initialSubmenu as $parentSlug => $subList) {
                    if (!\is_array($subList)) {
                        continue;
                    }
                    $parentStr = (string) $parentSlug;
                    foreach ($subList as $subItem) {
                        if (!\is_array($subItem) || !isset($subItem[2]) || !\is_string($subItem[2])) {
                            continue;
                        }
                        $childSlug = $subItem[2];
                        $normalizedChild = \str_replace('&amp;', '&', $childSlug);
                        $pairKey = $parentStr . ':' . $childSlug;
                        $normalizedPairKey = $parentStr . ':' . $normalizedChild;

                        $matchesExplicit = isset($explicitSubmenu[$pairKey])
                            || isset($explicitSubmenu[$normalizedPairKey]);
                        $matchesGeneric = isset($genericTargets[$childSlug])
                            || isset($genericTargets[$normalizedChild])
                            || isset($bareGenericSlugs[$childSlug])
                            || isset($bareGenericSlugs[$normalizedChild]);

                        $childBase = $normalizedChild;
                        $parsedParams = [];
                        if (\str_contains($normalizedChild, '?')) {
                            $parts = \explode('?', $normalizedChild, 2);
                            $childBase = $parts[0];
                            \parse_str($parts[1], $parsedParams);
                        }

                        $childBaseLower = \strtolower(\trim($childBase));

                        if (!$matchesGeneric && \str_contains($normalizedChild, '?')) {
                            if (isset($genericTargets[$childBase]) || isset($genericTargets[$childBaseLower])) {
                                $matchesGeneric = true;
                            }
                            if (
                                !$matchesExplicit && (
                                    isset($explicitSubmenu[$parentStr . ':' . $childBase])
                                    || isset($explicitSubmenu[$parentStr . ':' . $childBaseLower])
                                )
                            ) {
                                $matchesExplicit = true;
                            }
                        }

                        if (!$matchesGeneric && $parsedParams !== [] && $genericTargetsWithQuery !== []) {
                            foreach ($genericTargetsWithQuery as $tQueryTarget) {
                                if (
                                    $tQueryTarget['base'] === $childBaseLower
                                    || \str_ends_with($childBaseLower, '/' . $tQueryTarget['base'])
                                ) {
                                    $allMatched = true;
                                    foreach ($tQueryTarget['params'] as $pk => $pv) {
                                        $curVal = isset($parsedParams[$pk]) && \is_string($parsedParams[$pk])
                                            ? \strtolower(\trim($parsedParams[$pk]))
                                            : null;
                                        if ($curVal === null || $curVal !== $pv) {
                                            $allMatched = false;
                                            break;
                                        }
                                    }
                                    if ($allMatched) {
                                        $matchesGeneric = true;
                                        break;
                                    }
                                }
                            }
                        }

                        if ($parsedParams !== []) {
                            foreach (['page', 'post_type', 'taxonomy'] as $paramKey) {
                                $val = isset($parsedParams[$paramKey]) && \is_string($parsedParams[$paramKey])
                                    ? \strtolower(\trim($parsedParams[$paramKey]))
                                    : '';
                                if ($val === '') {
                                    continue;
                                }

                                $isTargetVal = isset($genericTargets[$val]) || isset($bareGenericSlugs[$val]);
                                if (!$matchesGeneric && $isTargetVal) {
                                    $matchesGeneric = true;
                                }
                                if (!$matchesExplicit && isset($explicitSubmenu[$parentStr . ':' . $val])) {
                                    $matchesExplicit = true;
                                }
                                if ($matchesGeneric && $matchesExplicit) {
                                    break;
                                }
                            }
                        }

                        if ($matchesExplicit || $matchesGeneric) {
                            $this->removeSubmenuPage($parentStr, $childSlug);
                            $removedSubmenuKeys[$pairKey] = true;
                            $removedSubmenuKeys[$normalizedPairKey] = true;
                            $removedSubmenuKeys[$childSlug] = true;
                            $removedSubmenuKeys[$normalizedChild] = true;
                        }
                    }
                }
            }

            /** @var array<string, bool> $preservedTopLevelSlugs */
            $preservedTopLevelSlugs = [];
            if (isset($GLOBALS['menu']) && \is_array($GLOBALS['menu'])) {
                /** @var array<int|string, array<int|string, mixed>> $wpMenuList */
                $wpMenuList = $GLOBALS['menu'];
                foreach ($wpMenuList as $menuPos => $menuItem) {
                    if (!isset($menuItem[2]) || !\is_string($menuItem[2])) {
                        continue;
                    }
                    $menuSlug = $menuItem[2];
                    $normalizedMenuSlug = \str_replace('&amp;', '&', $menuSlug);
                    $menuBase = $normalizedMenuSlug;
                    $menuParams = [];
                    if (\str_contains($normalizedMenuSlug, '?')) {
                        $parts = \explode('?', $normalizedMenuSlug, 2);
                        $menuBase = $parts[0];
                        \parse_str($parts[1], $menuParams);
                    }
                    $menuBaseLower = \strtolower(\trim($menuBase));

                    $isTargeted = isset($genericTargets[$menuSlug])
                        || isset($genericTargets[$normalizedMenuSlug])
                        || isset($bareGenericSlugs[$menuSlug])
                        || isset($bareGenericSlugs[$normalizedMenuSlug])
                        || isset($explicitSubmenu[$menuSlug . ':' . $menuSlug])
                        || isset($explicitSubmenu[$normalizedMenuSlug . ':' . $normalizedMenuSlug]);

                    if (!$isTargeted && \str_contains($normalizedMenuSlug, '?')) {
                        if (isset($genericTargets[$menuBase]) || isset($genericTargets[$menuBaseLower])) {
                            $isTargeted = true;
                        }
                    }

                    if (!$isTargeted && $menuParams !== []) {
                        foreach (['page', 'post_type', 'taxonomy'] as $paramKey) {
                            $val = isset($menuParams[$paramKey]) && \is_string($menuParams[$paramKey])
                                ? \strtolower(\trim($menuParams[$paramKey]))
                                : '';
                            if ($val !== '' && (isset($genericTargets[$val]) || isset($bareGenericSlugs[$val]))) {
                                $isTargeted = true;
                                break;
                            }
                        }
                    }

                    $menuHook = isset($menuItem[5]) && \is_string($menuItem[5]) ? $menuItem[5] : '';
                    $menuHookBase = \str_starts_with($menuHook, 'toplevel_page_')
                        ? \substr($menuHook, 14)
                        : $menuHook;

                    if (
                        !$isTargeted && $menuHook !== '' && (
                            isset($genericTargets[$menuHook])
                            || isset($genericTargets[$menuHookBase])
                            || isset($bareGenericSlugs[$menuHook])
                            || isset($bareGenericSlugs[$menuHookBase])
                        )
                    ) {
                        $isTargeted = true;
                    }

                    if (!$isTargeted) {
                        continue;
                    }

                    $isCoreSharedParent = \in_array($menuSlug, self::CORE_SHARED_PARENTS, true)
                        || \in_array($normalizedMenuSlug, self::CORE_SHARED_PARENTS, true)
                        || \in_array($menuBaseLower, self::CORE_SHARED_PARENTS, true)
                        || (
                            \str_ends_with($menuBaseLower, '.php')
                            && $menuBaseLower !== 'admin.php'
                            && $menuBaseLower !== 'index.php'
                        );

                    $hasAccessibleSubmenu = false;
                    if (isset($GLOBALS['submenu']) && \is_array($GLOBALS['submenu'])) {
                        $subToCheck = $GLOBALS['submenu'][$menuSlug]
                            ?? ($GLOBALS['submenu'][$normalizedMenuSlug] ?? null);
                        if (\is_array($subToCheck) && $subToCheck !== []) {
                            $hasAccessibleSubmenu = $this->hasAccessibleSubmenuItems(
                                $subToCheck,
                                $menuSlug,
                                $removedSubmenuKeys
                            );
                        }
                    }

                    if ($isCoreSharedParent && $hasAccessibleSubmenu) {
                        $preservedTopLevelSlugs[$menuSlug] = true;
                        continue;
                    }

                    $this->removeMenuPage($menuSlug);
                    if ($menuHookBase !== '' && $menuHookBase !== $menuSlug) {
                        $this->removeMenuPage($menuHookBase);
                    }
                    if (isset($GLOBALS['menu']) && \is_array($GLOBALS['menu'])) {
                        unset($GLOBALS['menu'][$menuPos]);
                    }
                    if (isset($GLOBALS['submenu']) && \is_array($GLOBALS['submenu'])) {
                        unset(
                            $GLOBALS['submenu'][$menuSlug],
                            $GLOBALS['submenu'][$normalizedMenuSlug],
                            $GLOBALS['submenu'][$menuHookBase]
                        );
                    }
                }
            }

            foreach (\array_keys($genericTargets) as $target) {
                if (!isset($preservedTopLevelSlugs[$target])) {
                    $this->removeMenuPage($target);
                    if (isset($GLOBALS['submenu']) && \is_array($GLOBALS['submenu'])) {
                        unset($GLOBALS['submenu'][$target]);
                    }
                }
            }

            if (isset($GLOBALS['submenu']) && \is_array($GLOBALS['submenu'])) {
                foreach ($GLOBALS['submenu'] as $k => $v) {
                    if (!\is_array($v) || $v === []) {
                        unset($GLOBALS['submenu'][$k]);
                    }
                }
            }
        }

        $this->handleSidebarMenuOrder();
        $this->handleSidebarSeparators();
        $this->cleanupSidebarSeparators();
        if (isset($GLOBALS['menu']) && \is_array($GLOBALS['menu'])) {
            \ksort($GLOBALS['menu'], \SORT_NUMERIC);
        }
    }

    public function handleSidebarSeparators(): void
    {
        $raw = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER');
        if ($raw === false || \trim($raw) === '') {
            return;
        }

        $menu = &$GLOBALS['menu'];
        if (!\is_array($menu)) {
            return;
        }

        $targets = [];
        foreach (\explode(',', \trim($raw)) as $token) {
            $t = \trim($token);
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
                if (!\is_array($item)) {
                    continue;
                }
                $rawSlug = $item[2] ?? '';
                $slug = \is_scalar($rawSlug) ? (string) $rawSlug : '';
                $rawHook = $item[5] ?? '';
                $hook = \is_scalar($rawHook) ? (string) $rawHook : '';
                if ($slug === $target || $hook === $target) {
                    $foundPos = $pos;
                    $matchedSlug = $slug;
                    break;
                }
            }

            if ($foundPos === null || $matchedSlug === '') {
                continue;
            }

            $basePos = \is_numeric($foundPos) ? (float) $foundPos : 100.0;
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

    public function cleanupSidebarSeparators(): void
    {
        if (!isset($GLOBALS['menu']) || !\is_array($GLOBALS['menu'])) {
            return;
        }

        \ksort($GLOBALS['menu'], \SORT_NUMERIC);

        $hasSeenRegularItem = false;
        $lastKeptKey = null;
        $lastKeptIsSeparator = false;

        foreach ($GLOBALS['menu'] as $pos => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $isSeparator = (isset($item[4]) && \is_string($item[4]) && \str_contains($item[4], 'wp-menu-separator'))
                || (isset($item[2]) && \is_string($item[2]) && \str_starts_with($item[2], 'separator'));

            if ($isSeparator) {
                if (!$hasSeenRegularItem || $lastKeptIsSeparator) {
                    unset($GLOBALS['menu'][$pos]);
                    continue;
                }
                $lastKeptKey = $pos;
                $lastKeptIsSeparator = true;
            } else {
                $hasSeenRegularItem = true;
                $lastKeptKey = $pos;
                $lastKeptIsSeparator = false;
            }
        }

        if ($lastKeptIsSeparator && $lastKeptKey !== null) {
            unset($GLOBALS['menu'][$lastKeptKey]);
        }
    }

    public function handleSidebarMenuOrder(): void
    {
        $raw = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_ORDER');
        if ($raw === false || \trim($raw) === '') {
            return;
        }

        $menu = &$GLOBALS['menu'];
        if (!\is_array($menu)) {
            return;
        }

        /** @var list<array{target: string, pos: float}> $rules */
        $rules = [];
        foreach (\explode(',', \trim($raw)) as $chunk) {
            $parts = \explode(':', \trim($chunk), 2);
            if (\count($parts) === 2 && \is_numeric(\trim($parts[1]))) {
                $target = \trim($parts[0]);
                if ($target !== '') {
                    $rules[] = [
                        'target' => $target,
                        'pos' => (float) \trim($parts[1]),
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
                if (!\is_array($item)) {
                    continue;
                }
                $rawSlug = $item[2] ?? '';
                $slug = \is_scalar($rawSlug) ? (string) $rawSlug : '';
                $rawHook = $item[5] ?? '';
                $hook = \is_scalar($rawHook) ? (string) $rawHook : '';

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
                $key = ($offset === 0 && (float) (int) $pos === $pos)
                    ? (int) $pos
                    : \sprintf('%.2f', $pos + ($offset * 0.01));

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
                $key = \sprintf('%.2f', $pos + ($offset * 0.01));
                $offset++;
            } while (isset($menu[$key]) || isset($assignedKeys[(string) $key]));

            $assignedKeys[(string) $key] = true;
            $menu[$key] = $disp['item'];
        }

        \ksort($menu, \SORT_NUMERIC);
    }

    public function filterCustomMenuOrder(mixed $custom): bool
    {
        $rawSeps = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER');
        if ($rawSeps !== false && \trim($rawSeps) !== '') {
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
                $targetPos = \array_search($targetSlug, $reordered, true);
                $sepPos = \array_search($sepSlug, $reordered, true);
                if ($targetPos !== false) {
                    if ($sepPos !== false) {
                        \array_splice($reordered, (int) $sepPos, 1);
                        $targetPos = \array_search($targetSlug, $reordered, true);
                    }
                    \array_splice($reordered, (int) $targetPos + 1, 0, [$sepSlug]);
                }
            }
            return $reordered;
        }

        return $menuOrder;
    }

    public function enforceRouteRestrictions(string $pagenow, ?object $currentUser = null): void
    {
        $user = $currentUser ?? $this->getCurrentUser();
        if ($user !== null && $this->isSuperAdmin($user)) {
            return;
        }

        $sidebarControl = $this->getSidebarMenuControlForCurrentUser($user);
        $blockedPages = $sidebarControl['block'];
        if ($blockedPages === []) {
            return;
        }

        $lowerPagenow = \strtolower($pagenow);
        $normalizedBlocked = [];
        $bareBlockedSlugs = [];
        foreach ($blockedPages as $bt) {
            $childSlug = $this->isExplicitSubmenuTarget($bt) ? \explode(':', $bt, 2)[1] : $bt;
            $lowerChild = \strtolower(\trim($childSlug));
            $normalizedBlocked[] = $lowerChild;
            $normalizedBlocked[] = \strtolower(\trim($bt));

            if (\str_starts_with($lowerChild, 'admin.php?page=')) {
                $extracted = \trim(\substr($lowerChild, 15));
                if ($extracted !== '') {
                    $bareBlockedSlugs[] = $extracted;
                }
            } elseif (!\str_contains($lowerChild, '.') && !\str_contains($lowerChild, '?')) {
                if ($lowerChild !== '') {
                    $bareBlockedSlugs[] = $lowerChild;
                }
            }
        }
        $normalizedBlocked = \array_values(\array_unique($normalizedBlocked));
        $bareBlockedSlugs = \array_values(\array_unique($bareBlockedSlugs));

        if ($lowerPagenow === 'options.php') {
            if (\in_array('options.php', $normalizedBlocked, true)) {
                $this->dieForbidden('Sorry, you are not allowed to access this page.');
                return;
            }

            $rawCandidates = [$_POST['option_page'] ?? null, $_GET['option_page'] ?? null];
            $candidates = [];
            foreach ($rawCandidates as $raw) {
                if (\is_string($raw)) {
                    $trimmed = \strtolower(\trim($raw));
                    if ($trimmed !== '' && $trimmed !== '0') {
                        $candidates[] = $trimmed;
                    }
                }
            }
            $candidates = \array_values(\array_unique($candidates));

            if ($candidates !== []) {
                foreach ($candidates as $candidate) {
                    if ($candidate === 'options') {
                        $hasBlockedOptions = false;
                        foreach ($normalizedBlocked as $nb) {
                            if (\str_starts_with($nb, 'options-')) {
                                $hasBlockedOptions = true;
                                break;
                            }
                        }
                        if ($hasBlockedOptions) {
                            $this->dieForbidden('Sorry, you are not allowed to access this page.');
                            return;
                        }
                    } else {
                        $mappedTarget = 'options-' . $candidate . '.php';
                        if (
                            \in_array($mappedTarget, $normalizedBlocked, true)
                            || \in_array($candidate, $normalizedBlocked, true)
                        ) {
                            $this->dieForbidden('Sorry, you are not allowed to access this page.');
                            return;
                        }
                    }
                }
            } elseif (\in_array('options-general.php', $normalizedBlocked, true)) {
                $this->dieForbidden('Sorry, you are not allowed to access this page.');
                return;
            }
        }

        if (\in_array($lowerPagenow, $normalizedBlocked, true)) {
            $this->dieForbidden('Sorry, you are not allowed to access this page.');
            return;
        }

        $currentQueryParams = [];
        $queryString = isset($_SERVER['QUERY_STRING']) && \is_string($_SERVER['QUERY_STRING'])
            ? \trim($_SERVER['QUERY_STRING'])
            : '';
        if ($queryString !== '') {
            \parse_str($queryString, $currentQueryParams);
        }
        foreach ($_GET as $k => $v) {
            if (\is_string($k)) {
                $currentQueryParams[$k] = $v;
            }
        }
        foreach ($_POST as $k => $v) {
            if (\is_string($k)) {
                $currentQueryParams[$k] = $v;
            }
        }

        $normalizedQueryParams = [];
        foreach ($currentQueryParams as $paramKey => $paramVal) {
            if (\is_string($paramKey) && (\is_string($paramVal) || \is_numeric($paramVal))) {
                $normalizedQueryParams[\strtolower(\trim($paramKey))] = \strtolower(\trim((string) $paramVal));
            }
        }

        if (
            ($lowerPagenow === 'edit.php' || $lowerPagenow === 'post-new.php')
            && (!isset($normalizedQueryParams['post_type']) || $normalizedQueryParams['post_type'] === '')
        ) {
            $normalizedQueryParams['post_type'] = 'post';
        }

        if ($bareBlockedSlugs !== []) {
            $pageVal = $normalizedQueryParams['page'] ?? '';
            $postTypeVal = $normalizedQueryParams['post_type'] ?? '';
            $taxonomyVal = $normalizedQueryParams['taxonomy'] ?? '';

            foreach ($bareBlockedSlugs as $bareSlug) {
                if (
                    ($pageVal !== '' && $pageVal === $bareSlug)
                    || ($postTypeVal !== '' && $postTypeVal === $bareSlug)
                    || ($taxonomyVal !== '' && $taxonomyVal === $bareSlug)
                    || $lowerPagenow === $bareSlug . '.php'
                ) {
                    $this->dieForbidden('Sorry, you are not allowed to access this page.');
                    return;
                }
            }
        }

        foreach ($blockedPages as $target) {
            $checkTarget = $this->isExplicitSubmenuTarget($target) ? \explode(':', $target, 2)[1] : $target;
            if (!\str_contains($checkTarget, '?')) {
                continue;
            }

            $targetParts = \explode('?', $checkTarget, 2);
            $targetPage = \strtolower(\trim($targetParts[0]));
            if ($targetPage !== '' && $targetPage !== $lowerPagenow) {
                continue;
            }

            $targetQueryParams = [];
            \parse_str($targetParts[1], $targetQueryParams);
            if ($targetQueryParams === []) {
                continue;
            }

            $matchesAllTargetParams = true;
            foreach ($targetQueryParams as $paramKey => $paramVal) {
                if (!\is_string($paramKey) || !\is_string($paramVal)) {
                    $matchesAllTargetParams = false;
                    break;
                }

                $lowerKey = \strtolower(\trim($paramKey));
                $lowerVal = \strtolower(\trim($paramVal));
                $currentVal = $normalizedQueryParams[$lowerKey] ?? null;

                if ($currentVal === null || $currentVal !== $lowerVal) {
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

    public function handleAdminHead(?object $user = null): void
    {
        $this->handleAdminMenu();
        $this->cleanupSidebarSeparators();
        $this->renderSidebarMenuStyles($user);
    }

    public function renderSidebarMenuStyles(?object $user = null): void
    {
        $sidebarControl = $this->getSidebarMenuControlForCurrentUser($user);
        if ($sidebarControl['hide'] === []) {
            return;
        }

        $selectors = [];
        foreach ($sidebarControl['hide'] as $target) {
            $rawSlug = $this->isExplicitSubmenuTarget($target) ? \explode(':', $target, 2)[1] : $target;
            $rawSlug = \trim($rawSlug);
            if ($rawSlug === '' || !\preg_match('/^[a-zA-Z0-9_\.\~\&\=\?\/\:\-]+$/', $rawSlug)) {
                continue;
            }

            if (\str_starts_with($rawSlug, 'admin.php?page=')) {
                $pageSlug = \substr($rawSlug, 15);
                if ($pageSlug !== '') {
                    $selectors[] = \sprintf('#adminmenu a[href*="page=%s"]', $pageSlug);
                    $selectors[] = \sprintf('#adminmenu li:has(> a[href*="page=%s"])', $pageSlug);
                    $selectors[] = \sprintf('li[data-slug="%s"]', $pageSlug);
                    $selectors[] = \sprintf('[data-menu-slug="%s"]', $pageSlug);
                }
                continue;
            }

            if (\str_contains($rawSlug, '.') || \str_contains($rawSlug, '?')) {
                $selectors[] = \sprintf('#adminmenu a[href*="%s"]', $rawSlug);
                $selectors[] = \sprintf('#adminmenu a[href$="%s"]', $rawSlug);
                $selectors[] = \sprintf('#adminmenu li:has(> a[href*="%s"])', $rawSlug);
                $selectors[] = \sprintf('#adminmenu li:has(> a[href$="%s"])', $rawSlug);
                continue;
            }

            $selectors[] = \sprintf('#adminmenu a[href*="page=%s"]', $rawSlug);
            $selectors[] = \sprintf('#adminmenu a[href*="post_type=%s"]', $rawSlug);
            $selectors[] = \sprintf('#adminmenu a[href*="taxonomy=%s"]', $rawSlug);
            $selectors[] = \sprintf('#adminmenu a[href$="%s"]', $rawSlug);
            $selectors[] = \sprintf('#adminmenu li:has(> a[href*="page=%s"])', $rawSlug);
            $selectors[] = \sprintf('#adminmenu li:has(> a[href*="post_type=%s"])', $rawSlug);
            $selectors[] = \sprintf('#adminmenu li:has(> a[href*="taxonomy=%s"])', $rawSlug);
            $selectors[] = \sprintf('#adminmenu li:has(> a[href$="%s"])', $rawSlug);
            $selectors[] = \sprintf('li[data-slug="%s"]', $rawSlug);
            $selectors[] = \sprintf('[data-menu-slug="%s"]', $rawSlug);
        }

        if ($selectors !== []) {
            $uniqueSelectors = \array_values(\array_unique($selectors));
            $this->echoHtml(\sprintf(
                '<style id="wp-admin-control-sidebar-menu">%s { display: none !important; }</style>',
                \implode(', ', $uniqueSelectors)
            ));
        }
    }

    public function removeMenuPage(string $menuSlug): void
    {
        if ($this->isExplicitSubmenuTarget($menuSlug)) {
            $parts = \explode(':', $menuSlug, 2);
            $this->removeSubmenuPage($parts[0], $parts[1]);
            return;
        }

        if (\is_callable($this->menuRemover)) {
            ($this->menuRemover)($menuSlug);
            return;
        }

        if (\function_exists('remove_menu_page')) {
            \remove_menu_page($menuSlug);
        }
    }

    public function removeSubmenuPage(string $menuSlug, string $submenuSlug): void
    {
        if (\is_callable($this->submenuRemover)) {
            ($this->submenuRemover)($menuSlug, $submenuSlug);
            return;
        }

        if (\function_exists('remove_submenu_page')) {
            \remove_submenu_page($menuSlug, $submenuSlug);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getSeparatorAfterMap(): array
    {
        return $this->separatorAfterMap;
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

    /**
     * @return list<string>
     */
    private function getSidebarMenuControlRawParts(): array
    {
        $rawParts = [];
        $rawMain = $this->getEnv('WP_ADMIN_SIDEBAR_MENU_CONTROL');
        $allEnv = $this->getAllEnv();

        if ($rawMain === false && isset($allEnv['WP_ADMIN_SIDEBAR_MENU_CONTROL'])) {
            $rawMain = (string) $allEnv['WP_ADMIN_SIDEBAR_MENU_CONTROL'];
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
                if (\str_starts_with($keyStr, 'WP_ADMIN_SIDEBAR_MENU_CONTROL_')) {
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

    private function isSuperAdmin(?object $user): bool
    {
        if ($this->superAdminChecker !== null) {
            return ($this->superAdminChecker)($user);
        }

        return false;
    }

    private function dieForbidden(string $message): void
    {
        if (\is_callable($this->dieForbiddenHandler)) {
            ($this->dieForbiddenHandler)($message);
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

    private function echoHtml(string $html): void
    {
        if (\is_callable($this->htmlEchoer)) {
            ($this->htmlEchoer)($html);
            return;
        }

        echo $html;
    }
}
