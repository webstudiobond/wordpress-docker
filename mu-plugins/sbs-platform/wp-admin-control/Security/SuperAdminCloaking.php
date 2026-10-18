<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Security;

/**
 * Enforces zero-knowledge cloaking of super-administrator accounts and hardens user administration interfaces.
 *
 * Conceals super-administrators from user listings, REST endpoints, and role metrics to prevent enumeration.
 * Dynamically revokes user management capabilities and excises administrative action controls based on sidebar rules.
 */
final class SuperAdminCloaking
{
    /**
     * @var list<string>
     */
    public const array PROTECTED_USER_CAPABILITIES = [
        'edit_user',
        'delete_user',
        'promote_user',
        'remove_user',
        'switch_to_user',
    ];

    /**
     * @var list<int>|null
     */
    private ?array $resolvedSuperAdminIds = null;

    private bool $isResolvingSuperAdmins = false;

    private bool $isBufferingStarted = false;

    /**
     * @param (callable(?object): bool)|null $superAdminChecker
     * @param (callable(): list<int>)|null $superAdminIdsResolver
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(int): (?object))|null $userDataGetter
     * @param (callable(array<string, mixed>): list<object>)|null $usersGetter
     * @param (callable(int, string, bool): mixed)|null $userMetaGetter
     * @param (callable(): list<string>)|null $blockedTargetsGetter
     * @param (callable(): string)|null $pageNowGetter
     * @param (callable(string): void)|null $htmlEchoer
     * @param (callable(callable): bool)|null $bufferStarter
     * @param (callable(string): void)|null $dieInvalidUserHandler
     * @param (callable(): bool)|null $adminChecker
     * @param (callable(): bool)|null $authorArchiveChecker
     * @param (callable(): int)|null $queriedObjectIdGetter
     * @param (callable(int): void)|null $statusHeaderSender
     * @param (callable(): void)|null $nocacheHeadersSender
     */
    public function __construct(
        private readonly mixed $superAdminChecker = null,
        private readonly mixed $superAdminIdsResolver = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $userDataGetter = null,
        private readonly mixed $usersGetter = null,
        private readonly mixed $userMetaGetter = null,
        private readonly mixed $blockedTargetsGetter = null,
        private readonly mixed $pageNowGetter = null,
        private readonly mixed $htmlEchoer = null,
        private readonly mixed $bufferStarter = null,
        private readonly mixed $dieInvalidUserHandler = null,
        private readonly mixed $adminChecker = null,
        private readonly mixed $authorArchiveChecker = null,
        private readonly mixed $queriedObjectIdGetter = null,
        private readonly mixed $statusHeaderSender = null,
        private readonly mixed $nocacheHeadersSender = null,
    ) {
    }

    public function isSuperAdmin(?object $user = null): bool
    {
        if (\is_callable($this->superAdminChecker)) {
            return ($this->superAdminChecker)($user);
        }

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

        if (\is_callable($this->superAdminIdsResolver)) {
            $resolved = ($this->superAdminIdsResolver)();
            $this->resolvedSuperAdminIds = \array_values(\array_unique($resolved));
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

            $this->resolvedSuperAdminIds = \array_values(\array_unique($ids));
            return $this->resolvedSuperAdminIds;
        } finally {
            $this->isResolvingSuperAdmins = false;
        }
    }

    /**
     * @return list<string>
     */
    public function getBlockedTargetsForCurrentUser(): array
    {
        if (\is_callable($this->blockedTargetsGetter)) {
            return ($this->blockedTargetsGetter)();
        }

        return [];
    }

    public function isTargetBlocked(string $target): bool
    {
        $normalizedTarget = \strtolower(\trim($target));
        if ($normalizedTarget === '') {
            return false;
        }

        $blocked = $this->getBlockedTargetsForCurrentUser();
        foreach ($blocked as $item) {
            $itemNormalized = \strtolower(\trim($item));
            if (
                $itemNormalized === $normalizedTarget
                || (
                    !\str_starts_with($itemNormalized, 'http://')
                    && !\str_starts_with($itemNormalized, 'https://')
                    && \str_ends_with($itemNormalized, ':' . $normalizedTarget)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function isDeleteBlocked(): bool
    {
        $blocked = $this->getBlockedTargetsForCurrentUser();
        foreach ($blocked as $target) {
            $t = \strtolower(\trim($target));
            if (
                $t === 'users.php?action=delete'
                || $t === 'users.php?action=dodelete'
                || $t === 'users.php:delete'
                || $t === 'delete-user'
                || $t === 'delete_user'
                || (\str_starts_with($t, 'users.php?') && \str_contains($t, 'action=delete'))
            ) {
                return true;
            }
        }

        return false;
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
        if (\is_array($existingInclude) && $existingInclude !== []) {
            $cleanedInclude = [];
            foreach ($existingInclude as $item) {
                if (\is_numeric($item)) {
                    $itemInt = (int) $item;
                    if (!\in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $this->callObjectMethod($query, 'set', ['include', $cleanedInclude === [] ? [0] : $cleanedInclude]);
        } elseif (\is_string($existingInclude) && \trim($existingInclude) !== '') {
            $cleanedInclude = [];
            foreach (\explode(',', $existingInclude) as $item) {
                if (\is_numeric(\trim($item))) {
                    $itemInt = (int) \trim($item);
                    if (!\in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $this->callObjectMethod($query, 'set', ['include', $cleanedInclude === [] ? [0] : $cleanedInclude]);
        }

        $existing = $this->callObjectMethod($query, 'get', ['exclude']);
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (\is_array($existing)) {
            foreach ($existing as $item) {
                if (\is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        } elseif (\is_string($existing) && \trim($existing) !== '') {
            foreach (\explode(',', $existing) as $item) {
                if (\is_numeric(\trim($item))) {
                    $excludeList[] = (int) \trim($item);
                }
            }
        }

        $merged = \array_values(\array_unique(\array_merge($excludeList, $superAdminIds)));
        $this->callObjectMethod($query, 'set', ['exclude', $merged]);
    }

    /**
     * @param array<string, mixed> $views
     * @return array<string, mixed>
     */
    public function filterViewsUsers(array $views): array
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $views;
        }

        $superAdminIds = $this->resolveSuperAdminIds();
        $superAdminCount = \count($superAdminIds);
        if ($superAdminCount === 0) {
            return $views;
        }

        $visibleTotalAll = PHP_INT_MAX;
        if (isset($views['all']) && \is_string($views['all'])) {
            if (\preg_match('/<span class="count">\(([\d,]+)\)<\/span>/', $views['all'], $matches)) {
                $rawAll = (int) \str_replace(',', '', $matches[1]);
                $visibleTotalAll = \max(0, $rawAll - $superAdminCount);
            }
        } elseif (isset($views['administrator']) && \is_string($views['administrator'])) {
            if (\preg_match('/<span class="count">\(([\d,]+)\)<\/span>/', $views['administrator'], $matches)) {
                $rawAdmin = (int) \str_replace(',', '', $matches[1]);
                $visibleTotalAll = \max(0, $rawAdmin - $superAdminCount);
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
            if (!\is_string($html)) {
                $adjusted[$key] = $html;
                continue;
            }

            $adjusted[$key] = (string) \preg_replace_callback(
                '/<span class="count">\(([\d,]+)\)<\/span>/',
                function (array $matches) use (
                    $key,
                    $superAdminCount,
                    $visibleTotalAll,
                    $superAdminsWith2Fa,
                    $superAdminsWithPasskey,
                    $superAdminsWithRemembered
                ): string {
                    $currentVal = (int) \str_replace(',', '', $matches[1]);
                    $normKey = \strtolower($key);

                    if ($normKey === 'all' || $normKey === 'administrator') {
                        $newVal = \max(0, $currentVal - $superAdminCount);
                    } elseif (
                        \str_contains($normKey, '2fa-inactive')
                        || \str_contains($normKey, '2fa_inactive')
                        || \str_contains($normKey, 'two-factor-inactive')
                        || \str_contains($normKey, 'two_factor_inactive')
                    ) {
                        $superAdminsWithout2Fa = \max(0, $superAdminCount - $superAdminsWith2Fa);
                        $newVal = \max(0, $currentVal - $superAdminsWithout2Fa);
                        $newVal = \min($newVal, $visibleTotalAll);
                    } elseif (
                        \str_contains($normKey, 'passkey-inactive')
                        || \str_contains($normKey, 'passkey_inactive')
                    ) {
                        $superAdminsWithoutPasskey = \max(0, $superAdminCount - $superAdminsWithPasskey);
                        $newVal = \max(0, $currentVal - $superAdminsWithoutPasskey);
                        $newVal = \min($newVal, $visibleTotalAll);
                    } elseif (
                        \str_contains($normKey, '2fa')
                        || \str_contains($normKey, 'two-factor')
                        || \str_contains($normKey, 'two_factor')
                    ) {
                        $newVal = \max(0, $currentVal - $superAdminsWith2Fa);
                        $newVal = \min($newVal, $visibleTotalAll);
                    } elseif (
                        \str_contains($normKey, 'passkey')
                    ) {
                        $newVal = \max(0, $currentVal - $superAdminsWithPasskey);
                        $newVal = \min($newVal, $visibleTotalAll);
                    } elseif (
                        \str_contains($normKey, 'remembered-devices')
                        || \str_contains($normKey, 'remembered_devices')
                    ) {
                        $newVal = \max(0, $currentVal - $superAdminsWithRemembered);
                        $newVal = \min($newVal, $visibleTotalAll);
                    } else {
                        $newVal = \min($currentVal, $visibleTotalAll);
                    }

                    return '<span class="count">(' . \number_format($newVal) . ')</span>';
                },
                $html
            );
        }

        return $adjusted;
    }

    /**
     * @param array<string, string> $actions
     * @param mixed $user
     * @return array<string, string>
     */
    public function filterUserRowActions(array $actions, mixed $user): array
    {
        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return $actions;
        }

        if (\is_object($user) && $this->isSuperAdmin($user)) {
            return [];
        }

        $userId = 0;
        if (\is_object($user) && isset($user->ID) && \is_numeric($user->ID)) {
            $userId = (int) $user->ID;
        }
        $currentUserId = (int) ($currentUser->ID ?? 0);

        if ($this->isDeleteBlocked()) {
            unset($actions['delete']);
        }

        if ($userId > 0 && $userId !== $currentUserId && $this->isTargetBlocked('user-edit.php')) {
            unset($actions['edit']);
        }

        if ($userId > 0 && $userId === $currentUserId && $this->isTargetBlocked('profile.php')) {
            unset($actions['edit']);
        }

        return $actions;
    }

    public function filterManageUsersCustomColumn(string $output, string $columnName, int $userId): string
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $output;
        }

        if (\in_array($userId, $this->resolveSuperAdminIds(), true)) {
            return '';
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $preparedArgs
     * @param mixed $request
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
        if (\is_array($existingInclude) && $existingInclude !== []) {
            $cleanedInclude = [];
            foreach ($existingInclude as $item) {
                if (\is_numeric($item)) {
                    $itemInt = (int) $item;
                    if (!\in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $preparedArgs['include'] = $cleanedInclude === [] ? [0] : $cleanedInclude;
        } elseif (\is_string($existingInclude) && \trim($existingInclude) !== '') {
            $cleanedInclude = [];
            foreach (\explode(',', $existingInclude) as $item) {
                if (\is_numeric(\trim($item))) {
                    $itemInt = (int) \trim($item);
                    if (!\in_array($itemInt, $superAdminIds, true)) {
                        $cleanedInclude[] = $itemInt;
                    }
                }
            }
            $preparedArgs['include'] = $cleanedInclude === [] ? [0] : $cleanedInclude;
        }

        $existingExclude = $preparedArgs['exclude'] ?? null;
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (\is_array($existingExclude)) {
            foreach ($existingExclude as $item) {
                if (\is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        }

        $preparedArgs['exclude'] = \array_values(\array_unique(\array_merge($excludeList, $superAdminIds)));

        return $preparedArgs;
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function filterBulkActionsUsers(array $actions): array
    {
        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return $actions;
        }

        if ($this->isDeleteBlocked()) {
            unset($actions['delete']);
        }

        return $actions;
    }

    public function filterRestPreDispatch(mixed $result, mixed $server, mixed $request): mixed
    {
        if ($result !== null) {
            return $result;
        }

        if (!\is_object($request) || !$this->isMethodCallable($request, 'get_route')) {
            return $result;
        }

        $rawRoute = $this->callObjectMethod($request, 'get_route');
        $route = \is_string($rawRoute) ? $rawRoute : '';
        if (\preg_match('#^/wp/v2/users/(\d+)(?:/.*)?$#', $route, $matches) === 1) {
            $targetId = (int) $matches[1];
            if ($targetId > 0) {
                $targetUser = $this->getUserData($targetId);
                if ($targetUser !== null && $this->isSuperAdmin($targetUser)) {
                    $currentUser = $this->getCurrentUser();
                    if (!$this->isSuperAdmin($currentUser)) {
                        return new \WP_Error(
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

    public function handleTemplateRedirect(): void
    {
        if ($this->isAdmin() || $this->isSuperAdmin($this->getCurrentUser())) {
            return;
        }

        if ($this->isAuthorArchive()) {
            $authorId = $this->getQueriedObjectId();
            if ($authorId > 0 && \in_array($authorId, $this->resolveSuperAdminIds(), true)) {
                $this->sendStatusHeader(404);
                $this->sendNocacheHeaders();
                $wpQuery = $GLOBALS['wp_query'] ?? null;
                if (\is_object($wpQuery) && $this->isMethodCallable($wpQuery, 'set_404')) {
                    $this->callObjectMethod($wpQuery, 'set_404');
                }
            }
        }
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
        if (!\is_string($cap) || $cap === '') {
            return $caps;
        }

        $currentUser = $userId > 0 ? ($this->getUserData($userId) ?? $this->getCurrentUser()) : null;
        $isCurrentSuperAdmin = $this->isSuperAdmin($currentUser);

        if (\in_array($cap, self::PROTECTED_USER_CAPABILITIES, true)) {
            $targetUserId = isset($args[0]) && \is_numeric($args[0]) ? (int) $args[0] : 0;
            if ($targetUserId > 0) {
                $targetUser = $this->getUserData($targetUserId);
                if ($targetUser !== null && $this->isSuperAdmin($targetUser) && !$isCurrentSuperAdmin) {
                    return ['do_not_allow'];
                }
            }
        }

        if ($isCurrentSuperAdmin) {
            return $caps;
        }

        if ($cap === 'create_users' || $cap === 'promote_users') {
            if ($this->isTargetBlocked('user-new.php')) {
                return ['do_not_allow'];
            }
        }

        if ($cap === 'delete_users' || $cap === 'delete_user') {
            if ($this->isDeleteBlocked()) {
                return ['do_not_allow'];
            }
        }

        if ($cap === 'edit_users') {
            if ($this->isTargetBlocked('user-edit.php')) {
                return ['do_not_allow'];
            }
        }

        if ($cap === 'edit_user') {
            $targetUserId = isset($args[0]) && \is_numeric($args[0]) ? (int) $args[0] : 0;
            $currentUserId = (int) ($currentUser->ID ?? 0);
            if ($targetUserId > 0 && $targetUserId !== $currentUserId && $this->isTargetBlocked('user-edit.php')) {
                return ['do_not_allow'];
            }
            if ($targetUserId > 0 && $targetUserId === $currentUserId && $this->isTargetBlocked('profile.php')) {
                return ['do_not_allow'];
            }
        }

        return $caps;
    }

    public function handleAdminInit(): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return;
        }

        $pagenow = $this->getPageNow();
        $this->enforceUserActionProtection($pagenow);

        if ($pagenow === 'users.php' && $this->isTargetBlocked('user-new.php')) {
            $this->startOutputBuffering();
        }
    }

    public function enforceUserActionProtection(string $pagenow): void
    {
        if ($pagenow !== 'users.php' && $pagenow !== 'user-edit.php') {
            return;
        }

        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return;
        }

        if ($pagenow === 'user-edit.php') {
            $rawTargetId = $_REQUEST['user_id']
                ?? $_REQUEST['user']
                ?? $_GET['user_id']
                ?? $_GET['user']
                ?? $_POST['user_id']
                ?? 0;
            $targetUserId = \is_numeric($rawTargetId) ? (int) $rawTargetId : 0;
            if ($targetUserId > 0) {
                $targetUser = $this->getUserData($targetUserId);
                if ($targetUser !== null && $this->isSuperAdmin($targetUser)) {
                    $this->dieInvalidUser('Invalid user ID.');
                    return;
                }
            }
        }

        $rawAction = $_REQUEST['action'] ?? '';
        $action = \is_string($rawAction) ? \strtolower(\trim($rawAction)) : '';
        if ($action === '' || $action === '-1') {
            $rawAction2 = $_REQUEST['action2'] ?? '';
            $action = \is_string($rawAction2) ? \strtolower(\trim($rawAction2)) : '';
        }

        if ($action !== '' && $action !== '-1') {
            $targetUserIds = [];
            if (isset($_REQUEST['user_id']) && \is_numeric($_REQUEST['user_id'])) {
                $targetUserIds[] = (int) $_REQUEST['user_id'];
            }
            if (isset($_REQUEST['user']) && \is_numeric($_REQUEST['user'])) {
                $targetUserIds[] = (int) $_REQUEST['user'];
            }
            if (isset($_REQUEST['users'])) {
                if (\is_array($_REQUEST['users'])) {
                    $rawUsers = $_REQUEST['users'];
                } elseif (\is_string($_REQUEST['users'])) {
                    $rawUsers = \explode(',', $_REQUEST['users']);
                } else {
                    $rawUsers = [];
                }
                foreach ($rawUsers as $u) {
                    if (\is_numeric($u)) {
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

    public function dieInvalidUser(string $message = 'Invalid user ID.'): void
    {
        if (\is_callable($this->dieInvalidUserHandler)) {
            ($this->dieInvalidUserHandler)($message);
            return;
        }

        if (\function_exists('wp_die')) {
            \wp_die(\function_exists('__') ? (string) \__($message) : $message);
        }
    }

    public function handleAdminHead(): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return;
        }

        $pagenow = $this->getPageNow();
        if ($pagenow === 'users.php' && $this->isTargetBlocked('user-new.php')) {
            $this->echoHtml(
                '<style id="wp-admin-control-hide-user-new">'
                . '.wrap .page-title-action[href*="user-new.php"], '
                . '.wrap a.page-title-action { display: none !important; }'
                . '</style>'
            );
        }
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

    public function filterBuffer(string $buffer): string
    {
        if (!$this->isTargetBlocked('user-new.php')) {
            return $buffer;
        }

        $pattern1 = '/<a\s+[^>]*\bclass=["\'][^"\']*\bpage-title-action\b[^"\']*["\']'
            . '[^>]*\bhref=["\'][^"\']*user-new\.php[^"\']*["\'][^>]*>.*?<\/a>/is';
        $cleaned = (string) \preg_replace($pattern1, '', $buffer);

        $pattern2 = '/<a\s+[^>]*\bhref=["\'][^"\']*user-new\.php[^"\']*["\']'
            . '[^>]*\bclass=["\'][^"\']*\bpage-title-action\b[^"\']*["\'][^>]*>.*?<\/a>/is';
        return (string) \preg_replace($pattern2, '', $cleaned);
    }

    public function isMetaValueActive(mixed $val): bool
    {
        if (\is_array($val)) {
            return $val !== [];
        }

        if (\is_string($val)) {
            $trimmed = \strtolower(\trim($val));
            if (
                $trimmed === ''
                || $trimmed === '0'
                || $trimmed === 'false'
                || $trimmed === 'no'
                || $trimmed === 'off'
                || $trimmed === 'disabled'
                || $trimmed === 'none'
                || $trimmed === 'null'
            ) {
                return false;
            }

            if (\is_numeric($trimmed)) {
                return ((int) $trimmed) > 0;
            }

            return true;
        }

        if (\is_bool($val)) {
            return $val;
        }

        if (\is_numeric($val)) {
            return ((int) $val) > 0;
        }

        return $val !== null;
    }

    /**
     * @param list<string> $metaKeys
     */
    private function countSuperAdminsWithMeta(array $metaKeys): int
    {
        $superAdminIds = $this->resolveSuperAdminIds();
        $count = 0;
        foreach ($superAdminIds as $adminId) {
            $hasAnyMeta = false;
            foreach ($metaKeys as $metaKey) {
                $val = $this->getUserMeta($adminId, $metaKey, true);
                if ($this->isMetaValueActive($val)) {
                    $hasAnyMeta = true;
                    break;
                }
            }
            if ($hasAnyMeta) {
                $count++;
            }
        }

        return $count;
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

    private function getUserData(int $userId): ?object
    {
        if (\is_callable($this->userDataGetter)) {
            $user = ($this->userDataGetter)($userId);
            return \is_object($user) ? $user : null;
        }

        $user = \function_exists('get_userdata') ? \get_userdata($userId) : null;
        return \is_object($user) ? $user : null;
    }

    /**
     * @param array<string, mixed> $args
     * @return list<object>
     */
    private function getUsers(array $args): array
    {
        if (\is_callable($this->usersGetter)) {
            return ($this->usersGetter)($args);
        }

        $result = [];
        $rawUsers = \function_exists('get_users') ? \get_users($args) : [];
        if (\is_iterable($rawUsers)) {
            foreach ($rawUsers as $user) {
                if (\is_object($user)) {
                    $result[] = $user;
                }
            }
        }

        return $result;
    }

    private function getUserMeta(int $userId, string $key, bool $single = false): mixed
    {
        if (\is_callable($this->userMetaGetter)) {
            return ($this->userMetaGetter)($userId, $key, $single);
        }

        return \function_exists('get_user_meta') ? \get_user_meta($userId, $key, $single) : '';
    }

    private function getPageNow(): string
    {
        if (\is_callable($this->pageNowGetter)) {
            return ($this->pageNowGetter)();
        }

        $raw = $GLOBALS['pagenow'] ?? '';
        return \is_string($raw) ? \trim($raw) : '';
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

    private function isAdmin(): bool
    {
        if (\is_callable($this->adminChecker)) {
            return ($this->adminChecker)();
        }

        return \function_exists('is_admin') && \is_admin();
    }

    private function isAuthorArchive(): bool
    {
        if (\is_callable($this->authorArchiveChecker)) {
            return ($this->authorArchiveChecker)();
        }

        return \function_exists('is_author') && \is_author();
    }

    private function getQueriedObjectId(): int
    {
        if (\is_callable($this->queriedObjectIdGetter)) {
            return ($this->queriedObjectIdGetter)();
        }

        if (\function_exists('get_queried_object_id')) {
            $val = \get_queried_object_id();
            if (\is_numeric($val) && (int) $val > 0) {
                return (int) $val;
            }
        }

        return 0;
    }

    private function sendStatusHeader(int $code): void
    {
        if (\is_callable($this->statusHeaderSender)) {
            ($this->statusHeaderSender)($code);
            return;
        }

        if (\function_exists('status_header')) {
            \status_header($code);
        }
    }

    private function sendNocacheHeaders(): void
    {
        if (\is_callable($this->nocacheHeadersSender)) {
            ($this->nocacheHeadersSender)();
            return;
        }

        if (\function_exists('nocache_headers')) {
            \nocache_headers();
        }
    }
}
