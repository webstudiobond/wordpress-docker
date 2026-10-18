<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Enforces role-based content access restrictions across administrative views, queries, and REST endpoints.
 *
 * Prevents unauthorized editing, publishing, deletion, or enumeration of targeted posts, pages, and custom post types.
 *
 * @phpstan-import-type AdminRule from \WordPressDocker\AdminControl\Security\RuleMatcher
 */
final class ContentAccessControl
{
    /**
     * @var list<string>
     */
    public const array PROTECTED_POST_CAPABILITIES = [
        'edit_post',
        'delete_post',
        'edit_page',
        'delete_page',
    ];

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
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(int): (?object))|null $userDataGetter
     * @param (callable(?object): bool)|null $superAdminChecker
     * @param (callable(): list<int>)|null $superAdminIdsResolver
     * @param (callable(string, string|int): (?object))|null $userByGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userRestrictedChecker
     * @param (callable(int): (?object))|null $postGetter
     * @param (callable(string): (int|object|null))|null $postResolver
     * @param (callable(): bool)|null $adminChecker
     * @param (callable(): (?object))|null $currentScreenGetter
     * @param (callable(int, string): int)|null $userPostsCountGetter
     * @param (callable(): string)|null $pageNowGetter
     * @param (callable(string): list<string>)|null $hiddenTargetsGetter
     * @param (callable(string): void)|null $dieForbiddenHandler
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $userDataGetter = null,
        private readonly mixed $superAdminChecker = null,
        private readonly mixed $superAdminIdsResolver = null,
        private readonly mixed $userByGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userRestrictedChecker = null,
        private readonly mixed $postGetter = null,
        private readonly mixed $postResolver = null,
        private readonly mixed $adminChecker = null,
        private readonly mixed $currentScreenGetter = null,
        private readonly mixed $userPostsCountGetter = null,
        private readonly mixed $pageNowGetter = null,
        private readonly mixed $hiddenTargetsGetter = null,
        private readonly mixed $dieForbiddenHandler = null,
    ) {
    }

    /**
     * @return list<AdminRule>
     */
    public function getContentAccessRules(): array
    {
        if ($this->cachedContentAccessRules === null) {
            $raw = $this->getEnv('WP_ADMIN_CONTENT_ACCESS_RULES');
            $this->cachedContentAccessRules = ($raw !== false && \trim($raw) !== '' && $this->ruleParser !== null)
                ? ($this->ruleParser)(\trim($raw))
                : [];
        }

        return $this->cachedContentAccessRules;
    }

    public function resolveContentTargetToPostId(string $target): ?int
    {
        $target = \trim($target);
        if ($target === '') {
            return null;
        }

        if (\array_key_exists($target, $this->resolvedContentTargetIds)) {
            return $this->resolvedContentTargetIds[$target];
        }

        if (\str_starts_with($target, 'id:')) {
            $rawId = \substr($target, 3);
            $id = \ctype_digit($rawId) ? (int) $rawId : 0;
            $res = $id > 0 ? $id : null;
            $this->resolvedContentTargetIds[$target] = $res;
            return $res;
        }

        if (\ctype_digit($target)) {
            $id = (int) $target;
            $res = $id > 0 ? $id : null;
            $this->resolvedContentTargetIds[$target] = $res;
            return $res;
        }

        if (\is_callable($this->postResolver)) {
            $resolved = ($this->postResolver)($target);
            $res = null;
            if (\is_numeric($resolved)) {
                $id = (int) $resolved;
                $res = $id > 0 ? $id : null;
            } elseif (\is_object($resolved) && isset($resolved->ID) && \is_numeric($resolved->ID)) {
                $id = (int) $resolved->ID;
                $res = $id > 0 ? $id : null;
            }
            $this->resolvedContentTargetIds[$target] = $res;
            return $res;
        }

        $postType = null;
        $slug = $target;
        if (\str_starts_with($target, 'slug:')) {
            $slug = \substr($target, 5);
        } elseif (\str_contains($target, ':')) {
            $parts = \explode(':', $target, 2);
            $postType = $parts[0] !== '' ? $parts[0] : null;
            $slug = $parts[1];
        }

        $resolvedId = null;
        if (\function_exists('get_posts')) {
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
            $posts = \get_posts($queryArgs);
            if (
                \is_array($posts)
                && isset($posts[0])
                && \is_object($posts[0])
                && isset($posts[0]->ID)
                && \is_numeric($posts[0]->ID)
            ) {
                $id = (int) $posts[0]->ID;
                if ($id > 0) {
                    $resolvedId = $id;
                }
            }
        }

        if ($resolvedId === null && \function_exists('get_page_by_path')) {
            $page = \get_page_by_path($slug, 'OBJECT', $postType ?? 'page');
            if (\is_object($page) && isset($page->ID) && \is_numeric($page->ID)) {
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
    public function getRestrictedPostIdsForCurrentUser(): array
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
    public function getRestrictedPostIdsForUser(object $user): array
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
            if ($userId > 0) {
                $this->cachedRestrictedPostIdsByUser[$userId] = [];
            }
            return [];
        }

        $restrictedIds = [];
        foreach ($rules as $rule) {
            if ($this->userRestrictedChecker !== null && !($this->userRestrictedChecker)($rule, $user)) {
                continue;
            }

            foreach ($rule['targets'] as $target) {
                $postId = $this->resolveContentTargetToPostId($target);
                if ($postId !== null && $postId > 0) {
                    $restrictedIds[] = $postId;
                }
            }
        }

        $uniqueIds = \array_values(\array_unique($restrictedIds));
        if ($userId > 0) {
            $this->cachedRestrictedPostIdsByUser[$userId] = $uniqueIds;
        }

        return $uniqueIds;
    }

    public function isPostRestrictedForCurrentUser(int $postId): bool
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null || $this->isSuperAdmin($currentUser)) {
            return false;
        }

        return $this->isPostRestrictedForUser($postId, $currentUser);
    }

    public function isPostRestrictedForUser(int $postId, object $user): bool
    {
        if ($postId <= 0 || $this->isSuperAdmin($user)) {
            return false;
        }

        $restrictedIds = $this->getRestrictedPostIdsForUser($user);
        if (\in_array($postId, $restrictedIds, true)) {
            return true;
        }

        $post = $this->getPost($postId);
        if ($post === null) {
            return false;
        }

        $postName = \is_string($post->post_name ?? null) ? $post->post_name : '';
        $postType = \is_string($post->post_type ?? null) ? $post->post_type : 'post';

        foreach ($this->getContentAccessRules() as $rule) {
            if ($this->userRestrictedChecker !== null && !($this->userRestrictedChecker)($rule, $user)) {
                continue;
            }

            foreach ($rule['targets'] as $target) {
                if (\str_starts_with($target, 'slug:')) {
                    $targetSlug = \substr($target, 5);
                    if ($targetSlug !== '' && $targetSlug === $postName) {
                        $this->resolvedContentTargetIds[$target] = $postId;
                        return true;
                    }
                } elseif (\str_contains($target, ':')) {
                    [$targetType, $targetSlug] = \explode(':', $target, 2);
                    if ($targetType === $postType && $targetSlug === $postName) {
                        $this->resolvedContentTargetIds[$target] = $postId;
                        return true;
                    }
                } elseif ($target !== '' && $target === $postName) {
                    $this->resolvedContentTargetIds[$target] = $postId;
                    return true;
                }
            }
        }

        return false;
    }

    public function enforcePostAccessRestrictions(string $pagenow): void
    {
        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return;
        }

        if ($pagenow === 'post.php') {
            $rawPostId = $_GET['post'] ?? ($_POST['post_ID'] ?? ($_POST['post'] ?? 0));
            $postId = \is_numeric($rawPostId) ? (int) $rawPostId : 0;
            if ($postId > 0 && $this->isPostRestrictedForCurrentUser($postId)) {
                $this->dieForbidden('Sorry, you are not allowed to edit this item.');
            }
        }
    }

    public function dieForbidden(string $message = 'Sorry, you are not allowed to edit this item.'): void
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

    public function shouldHideSuperadminPosts(): bool
    {
        $raw = $this->getEnv('WP_ADMIN_HIDE_SUPERADMIN_POSTS');
        if ($raw === false || \trim($raw) === '') {
            return false;
        }

        return \in_array(\strtolower(\trim($raw)), ['true', '1', 'on', 'yes'], true);
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

        if (!\in_array($cap, self::PROTECTED_POST_CAPABILITIES, true)) {
            return $caps;
        }

        $targetPostId = isset($args[0]) && \is_numeric($args[0]) ? (int) $args[0] : 0;
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

        $superAdminIds = $this->resolveSuperAdminIds();

        if (!$this->isAdmin()) {
            if ($superAdminIds !== []) {
                $this->sanitizeAuthorQuery($query, $superAdminIds, false);
            }
            return;
        }

        if (
            $this->isMethodCallable($query, 'is_main_query')
            && !(bool) $this->callObjectMethod($query, 'is_main_query')
        ) {
            return;
        }

        if ($this->getPageNow() !== 'edit.php') {
            return;
        }

        if ($superAdminIds !== [] && $this->sanitizeAuthorQuery($query, $superAdminIds, true)) {
            return;
        }

        $restrictedPostIds = $this->getRestrictedPostIdsForCurrentUser();
        if ($restrictedPostIds === []) {
            return;
        }

        $existingPostIn = $this->callObjectMethod($query, 'get', ['post__in']);
        if (\is_array($existingPostIn) && $existingPostIn !== []) {
            $cleanedPostIn = [];
            $hadNumeric = false;
            foreach ($existingPostIn as $item) {
                if (\is_numeric($item)) {
                    $hadNumeric = true;
                    $itemInt = (int) $item;
                    if (!\in_array($itemInt, $restrictedPostIds, true)) {
                        $cleanedPostIn[] = $itemInt;
                    }
                }
            }
            if ($hadNumeric) {
                $this->callObjectMethod(
                    $query,
                    'set',
                    ['post__in', $cleanedPostIn === [] ? [0] : \array_values(\array_unique($cleanedPostIn))]
                );
            }
        }

        $existing = $this->callObjectMethod($query, 'get', ['post__not_in']);
        /** @var list<int> $notInList */
        $notInList = [];
        if (\is_array($existing)) {
            foreach ($existing as $item) {
                if (\is_numeric($item)) {
                    $notInList[] = (int) $item;
                }
            }
        }

        $this->callObjectMethod(
            $query,
            'set',
            ['post__not_in', \array_values(\array_unique(\array_merge($notInList, $restrictedPostIds)))]
        );
    }

    /**
     * @param object $query
     * @param list<int> $superAdminIds
     * @param bool $isAdmin
     * @return bool
     */
    private function sanitizeAuthorQuery(object $query, array $superAdminIds, bool $isAdmin): bool
    {
        $rawAuthorName = $this->callObjectMethod($query, 'get', ['author_name']);
        $authorName = \is_string($rawAuthorName) ? \trim($rawAuthorName) : '';
        if ($authorName !== '') {
            $authorUser = $this->getUserBy('slug', $authorName) ?? $this->getUserBy('login', $authorName);
            if ($authorUser !== null && $this->isSuperAdmin($authorUser)) {
                if ($isAdmin) {
                    $this->callObjectMethod($query, 'set', ['post__in', [0]]);
                } else {
                    $this->callObjectMethod($query, 'set_404');
                }
                return true;
            }
        }

        $rawAuthor = $this->callObjectMethod($query, 'get', ['author']);
        if ($rawAuthor !== null && $rawAuthor !== '') {
            $authorTokens = [];
            if (\is_array($rawAuthor)) {
                $authorTokens = $rawAuthor;
            } elseif (\is_string($rawAuthor) || \is_numeric($rawAuthor)) {
                $authorTokens = \explode(',', (string) $rawAuthor);
            }

            $positiveAuthorIds = [];
            $hasSuperAdmin = false;
            $hasNonSuperAdmin = false;

            foreach ($authorTokens as $token) {
                if (!\is_string($token) && !\is_numeric($token)) {
                    continue;
                }
                $trimmed = \trim((string) $token);
                if (\is_numeric($trimmed)) {
                    $id = (int) $trimmed;
                    if ($id > 0) {
                        if (\in_array($id, $superAdminIds, true)) {
                            $hasSuperAdmin = true;
                        } else {
                            $hasNonSuperAdmin = true;
                            $positiveAuthorIds[] = $id;
                        }
                    }
                }
            }

            if ($hasSuperAdmin) {
                if (!$hasNonSuperAdmin) {
                    if ($isAdmin) {
                        $this->callObjectMethod($query, 'set', ['post__in', [0]]);
                    } else {
                        $this->callObjectMethod($query, 'set_404');
                    }
                    return true;
                }
                $this->callObjectMethod($query, 'set', ['author', \implode(',', $positiveAuthorIds)]);
            }
        }

        $rawAuthorIn = $this->callObjectMethod($query, 'get', ['author__in']);
        if (\is_array($rawAuthorIn) && $rawAuthorIn !== []) {
            $cleanedAuthorIn = [];
            $hadSuperAdminIn = false;
            foreach ($rawAuthorIn as $item) {
                if (\is_numeric($item)) {
                    $id = (int) $item;
                    if (\in_array($id, $superAdminIds, true)) {
                        $hadSuperAdminIn = true;
                    } else {
                        $cleanedAuthorIn[] = $id;
                    }
                }
            }
            if ($hadSuperAdminIn) {
                if ($cleanedAuthorIn === []) {
                    if ($isAdmin) {
                        $this->callObjectMethod($query, 'set', ['post__in', [0]]);
                    } else {
                        $this->callObjectMethod($query, 'set_404');
                    }
                    return true;
                }
                $this->callObjectMethod(
                    $query,
                    'set',
                    ['author__in', \array_values(\array_unique($cleanedAuthorIn))]
                );
            }
        }

        if ($this->shouldHideSuperadminPosts()) {
            $existingAuthorNotIn = $this->callObjectMethod($query, 'get', ['author__not_in']);
            /** @var list<int> $authorNotInList */
            $authorNotInList = [];
            if (\is_array($existingAuthorNotIn)) {
                foreach ($existingAuthorNotIn as $item) {
                    if (\is_numeric($item)) {
                        $authorNotInList[] = (int) $item;
                    }
                }
            }
            $this->callObjectMethod(
                $query,
                'set',
                ['author__not_in', \array_values(\array_unique(\array_merge($authorNotInList, $superAdminIds)))]
            );
        }

        return false;
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
                $postType = \is_string($post->post_type ?? null) ? $post->post_type : 'post';
                if ($postType !== $type) {
                    continue;
                }
                $postStatus = \is_string($post->post_status ?? null) ? $post->post_status : 'publish';
                if ($postStatus === 'publish') {
                    $deductions++;
                }
            } else {
                if ($type === 'post') {
                    $deductions++;
                }
            }
        }

        if ($deductions > 0 && isset($counts->publish) && \is_numeric($counts->publish)) {
            $counts->publish = (string) \max(0, (int) $counts->publish - $deductions);
        }

        return $counts;
    }

    /**
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
        $postType = \is_object($screen) && isset($screen->post_type) && \is_string($screen->post_type)
            ? $screen->post_type
            : (isset($_GET['post_type']) && \is_string($_GET['post_type']) ? $_GET['post_type'] : 'post');

        $restrictedMineCount = 0;
        foreach ($restrictedPostIds as $postId) {
            $post = $this->getPost($postId);
            if ($post === null) {
                continue;
            }

            $type = isset($post->post_type) ? (string) $post->post_type : '';
            $author = isset($post->post_author) ? (int) $post->post_author : 0;
            $status = isset($post->post_status) ? (string) $post->post_status : '';

            if ($type === $postType && $author === $userId && !\in_array($status, ['trash', 'auto-draft'], true)) {
                $restrictedMineCount++;
            }
        }

        if ($restrictedMineCount <= 0) {
            return $views;
        }

        $rawCount = 0;
        if (
            isset($views['mine'])
            && \preg_match('/<span class="count">\(([0-9,]+)\)<\/span>/', $views['mine'], $matches) === 1
        ) {
            $rawCount = (int) \str_replace(',', '', $matches[1]);
        } else {
            $rawCount = $this->getUserPostsCount($userId, $postType);
        }

        $adjustedCount = \max(0, $rawCount - $restrictedMineCount);

        $allCount = null;
        if (
            isset($views['all'])
            && \preg_match('/<span class="count">\(([0-9,]+)\)<\/span>/', $views['all'], $allMatches) === 1
        ) {
            $allCount = (int) \str_replace(',', '', $allMatches[1]);
        }

        if ($adjustedCount === 0 || ($allCount !== null && $adjustedCount >= $allCount)) {
            unset($views['mine']);
            return $views;
        }

        $isTrash = isset($_GET['post_status']) && $_GET['post_status'] === 'trash';
        $mineUrl = $postType === 'post' ? 'edit.php' : 'edit.php?post_type=' . \urlencode($postType);
        if ($isTrash) {
            $mineUrl .= (\str_contains($mineUrl, '?') ? '&' : '?') . 'post_status=trash';
        }
        $mineUrl .= (\str_contains($mineUrl, '?') ? '&' : '?') . 'author=' . $userId;

        $isCurrent = isset($_GET['author']) && \is_numeric($_GET['author']) && (int) $_GET['author'] === $userId;
        $class = $isCurrent ? ' class="current" aria-current="page"' : '';
        $mineLabel = \function_exists('_nx')
            ? \_nx('Mine', 'Mine', $adjustedCount, 'posts')
            : 'Mine';

        $countFormatted = \function_exists('number_format_i18n')
            ? \number_format_i18n($adjustedCount)
            : \number_format($adjustedCount);

        $escapedUrl = \function_exists('esc_url')
            ? \esc_url($mineUrl)
            : \htmlspecialchars($mineUrl, ENT_QUOTES, 'UTF-8');

        $mineHtml = \sprintf(
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

    public function filterAuthorLink(string $link, int $authorId, string $authorNicename = ''): string
    {
        if ($this->isSuperAdmin($this->getCurrentUser())) {
            return $link;
        }

        if (\in_array($authorId, $this->resolveSuperAdminIds(), true)) {
            return \function_exists('home_url') ? (string) \home_url('/') : '/';
        }

        return $link;
    }

    /**
     * @param array<string, mixed> $preparedArgs
     * @param mixed $request
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
                if (\is_string($rawAuthor) && \str_contains($rawAuthor, ',')) {
                    $rawAuthor = \explode(',', $rawAuthor);
                }

                if (\is_array($rawAuthor)) {
                    $cleanedAuthor = [];
                    $hadNumeric = false;
                    foreach ($rawAuthor as $item) {
                        if (\is_numeric($item)) {
                            $hadNumeric = true;
                            $itemInt = (int) $item;
                            if (!\in_array($itemInt, $superAdminIds, true)) {
                                $cleanedAuthor[] = $itemInt;
                            }
                        }
                    }
                    if ($hadNumeric) {
                        $preparedArgs['author'] = $cleanedAuthor === []
                            ? [0]
                            : \array_values(\array_unique($cleanedAuthor));
                    }
                } elseif (\is_numeric($rawAuthor) && \in_array((int) $rawAuthor, $superAdminIds, true)) {
                    $preparedArgs['author'] = [0];
                }
            }

            if (isset($preparedArgs['author__in']) && \is_array($preparedArgs['author__in'])) {
                $cleanedAuthorIn = [];
                $hadNumericIn = false;
                foreach ($preparedArgs['author__in'] as $item) {
                    if (\is_numeric($item)) {
                        $hadNumericIn = true;
                        $itemInt = (int) $item;
                        if (!\in_array($itemInt, $superAdminIds, true)) {
                            $cleanedAuthorIn[] = $itemInt;
                        }
                    }
                }
                if ($hadNumericIn) {
                    $preparedArgs['author__in'] = $cleanedAuthorIn === []
                        ? [0]
                        : \array_values(\array_unique($cleanedAuthorIn));
                }
            }

            if ($this->shouldHideSuperadminPosts()) {
                $authorExclude = [];
                if (isset($preparedArgs['author_exclude']) && \is_array($preparedArgs['author_exclude'])) {
                    foreach ($preparedArgs['author_exclude'] as $item) {
                        if (\is_numeric($item)) {
                            $authorExclude[] = (int) $item;
                        }
                    }
                }
                $preparedArgs['author_exclude'] = \array_values(
                    \array_unique(\array_merge($authorExclude, $superAdminIds))
                );
            }
        }

        $restrictedPostIds = $this->getRestrictedPostIdsForCurrentUser();
        if ($restrictedPostIds === []) {
            return $preparedArgs;
        }

        foreach (['post__in', 'include'] as $inKey) {
            if (isset($preparedArgs[$inKey])) {
                $rawIn = $preparedArgs[$inKey];
                if (\is_string($rawIn) && \str_contains($rawIn, ',')) {
                    $rawIn = \explode(',', $rawIn);
                } elseif (\is_numeric($rawIn)) {
                    $rawIn = [(int) $rawIn];
                }

                if (\is_array($rawIn)) {
                    $cleanedIn = [];
                    $hadNumeric = false;
                    foreach ($rawIn as $item) {
                        if (\is_numeric($item)) {
                            $hadNumeric = true;
                            $itemInt = (int) $item;
                            if (!\in_array($itemInt, $restrictedPostIds, true)) {
                                $cleanedIn[] = $itemInt;
                            }
                        }
                    }
                    if ($hadNumeric) {
                        $preparedArgs[$inKey] = $cleanedIn === []
                            ? [0]
                            : \array_values(\array_unique($cleanedIn));
                    }
                }
            }
        }

        $existing = $preparedArgs['exclude'] ?? ($preparedArgs['post__not_in'] ?? []);
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (\is_array($existing)) {
            foreach ($existing as $item) {
                if (\is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        }

        $mergedExclude = \array_values(\array_unique(\array_merge($excludeList, $restrictedPostIds)));
        $preparedArgs['exclude'] = $mergedExclude;
        if (isset($preparedArgs['post__not_in'])) {
            $preparedArgs['post__not_in'] = $mergedExclude;
        }

        return $preparedArgs;
    }

    public function filterRestPreparePost(mixed $response, mixed $post, mixed $request): mixed
    {
        if (!\is_object($response)) {
            return $response;
        }

        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return $response;
        }

        $authorId = 0;
        if (\is_object($post) && isset($post->post_author) && \is_numeric($post->post_author)) {
            $authorId = (int) $post->post_author;
        }

        if (
            $authorId <= 0
            && isset($response->data)
            && \is_array($response->data)
            && isset($response->data['author'])
            && \is_numeric($response->data['author'])
        ) {
            $authorId = (int) $response->data['author'];
        }

        if ($authorId > 0 && \in_array($authorId, $this->resolveSuperAdminIds(), true)) {
            if (isset($response->data) && \is_array($response->data)) {
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
     * @param mixed $request
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
            if (\is_array($rawAuthor)) {
                $cleanedAuthor = [];
                foreach ($rawAuthor as $item) {
                    if (\is_numeric($item)) {
                        $itemInt = (int) $item;
                        if (!\in_array($itemInt, $superAdminIds, true)) {
                            $cleanedAuthor[] = $itemInt;
                        }
                    }
                }
                $preparedArgs['author'] = $cleanedAuthor === [] ? [0] : $cleanedAuthor;
            } elseif (\is_numeric($rawAuthor) && \in_array((int) $rawAuthor, $superAdminIds, true)) {
                $preparedArgs['author'] = [0];
            }
        }

        $existingExclude = $preparedArgs['author_exclude'] ?? [];
        /** @var list<int> $excludeList */
        $excludeList = [];
        if (\is_array($existingExclude)) {
            foreach ($existingExclude as $item) {
                if (\is_numeric($item)) {
                    $excludeList[] = (int) $item;
                }
            }
        }

        $preparedArgs['author_exclude'] = \array_values(\array_unique(\array_merge($excludeList, $superAdminIds)));
        return $preparedArgs;
    }

    public function filterRestPrepareComment(mixed $response, mixed $comment, mixed $request): mixed
    {
        if (!\is_object($response)) {
            return $response;
        }

        $currentUser = $this->getCurrentUser();
        if ($this->isSuperAdmin($currentUser)) {
            return $response;
        }

        $authorId = 0;
        if (\is_object($comment) && isset($comment->user_id) && \is_numeric($comment->user_id)) {
            $authorId = (int) $comment->user_id;
        }

        if (
            $authorId <= 0
            && isset($response->data)
            && \is_array($response->data)
            && isset($response->data['author'])
            && \is_numeric($response->data['author'])
        ) {
            $authorId = (int) $response->data['author'];
        }

        if ($authorId > 0 && \in_array($authorId, $this->resolveSuperAdminIds(), true)) {
            if (isset($response->data) && \is_array($response->data)) {
                $response->data['author'] = 0;
            }
            if ($this->isMethodCallable($response, 'remove_link')) {
                $this->callObjectMethod($response, 'remove_link', ['author']);
            }
        }

        return $response;
    }

    /**
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
            $norm = \strtolower(\trim($target));
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
            $postId = \is_numeric($rawPostId) ? (int) $rawPostId : 0;
            if ($postId > 0) {
                $existingPost = $this->getPost($postId);
                if (
                    $existingPost !== null
                    && isset($existingPost->post_author)
                    && \is_numeric($existingPost->post_author)
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

    public function getUserPostsCount(int $userId, string $postType): int
    {
        if (\is_callable($this->userPostsCountGetter)) {
            return ($this->userPostsCountGetter)($userId, $postType);
        }

        global $wpdb;
        if (!isset($wpdb) || !\is_object($wpdb) || !isset($wpdb->posts) || !\is_string($wpdb->posts)) {
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
            if (\is_string($sql)) {
                $count = $this->callObjectMethod($wpdb, 'get_var', [$sql]);
                return \is_numeric($count) ? (int) $count : 0;
            }
        }

        return 0;
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

    /**
     * @return list<string>
     */
    private function getHiddenTargetsForCurrentUser(string $envKey): array
    {
        if (\is_callable($this->hiddenTargetsGetter)) {
            return ($this->hiddenTargetsGetter)($envKey);
        }

        return [];
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

    private function getUserData(int $userId): ?object
    {
        if (\is_callable($this->userDataGetter)) {
            $user = ($this->userDataGetter)($userId);
            return \is_object($user) ? $user : null;
        }

        $user = \function_exists('get_userdata') ? \get_userdata($userId) : null;
        return \is_object($user) ? $user : null;
    }

    private function getUserBy(string $field, string|int $value): ?object
    {
        if (\is_callable($this->userByGetter)) {
            $user = ($this->userByGetter)($field, $value);
            return \is_object($user) ? $user : null;
        }

        $user = \function_exists('get_user_by') ? \get_user_by($field, $value) : null;
        return \is_object($user) ? $user : null;
    }

    /**
     * @return list<int>
     */
    private function resolveSuperAdminIds(): array
    {
        if (\is_callable($this->superAdminIdsResolver)) {
            return ($this->superAdminIdsResolver)();
        }

        return [];
    }

    private function isSuperAdmin(?object $user = null): bool
    {
        if (\is_callable($this->superAdminChecker)) {
            return ($this->superAdminChecker)($user);
        }

        return false;
    }

    private function getPost(int $postId): ?object
    {
        if ($postId <= 0) {
            return null;
        }

        if (\is_callable($this->postGetter)) {
            $post = ($this->postGetter)($postId);
            return \is_object($post) ? $post : null;
        }

        $post = \function_exists('get_post') ? \get_post($postId) : null;
        return \is_object($post) ? $post : null;
    }

    private function isAdmin(): bool
    {
        if (\is_callable($this->adminChecker)) {
            return ($this->adminChecker)();
        }

        return \function_exists('is_admin') && \is_admin();
    }

    private function getCurrentScreen(): ?object
    {
        if (\is_callable($this->currentScreenGetter)) {
            return ($this->currentScreenGetter)();
        }

        return \function_exists('get_current_screen') ? \get_current_screen() : null;
    }

    private function getPageNow(): string
    {
        if (\is_callable($this->pageNowGetter)) {
            return ($this->pageNowGetter)();
        }

        $raw = $GLOBALS['pagenow'] ?? '';
        return \is_string($raw) ? \trim($raw) : '';
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
}
