<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Security;

/**
 * Guards administrative user profile submissions against parameter tampering and unauthorized modifications.
 */
final class ProfilePostGuard
{
    /**
     * @param (callable(?object): list<string>)|null $fieldsResolver
     * @param (callable(): ?object)|null $currentUserGetter
     */
    public function __construct(
        private readonly mixed $fieldsResolver = null,
        private readonly mixed $currentUserGetter = null,
    ) {
    }

    /**
     * @param list<string>|null $fieldsToRemove
     */
    public function handleProfilePostProtection(
        ?object $currentUser,
        string $pagenow,
        ?array $fieldsToRemove = null
    ): void {
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
            $targetUserId = \is_numeric($rawId) ? (int) $rawId : 0;
        }

        if ($targetUserId <= 0) {
            return;
        }

        $isEditingSelf = ($targetUserId === $currentUserId);
        if (!$isEditingSelf) {
            return;
        }

        $resolvedFields = $fieldsToRemove;
        if ($resolvedFields === null && $this->fieldsResolver !== null) {
            /** @var list<string> $resolvedFields */
            $resolvedFields = (array) ($this->fieldsResolver)($currentUser);
        }

        if ($resolvedFields === null || $resolvedFields === []) {
            return;
        }

        $this->enforceProfilePostProtection($currentUser, $resolvedFields);
    }

    /**
     * @param list<string> $fieldsToRemove
     */
    public function enforceProfilePostProtection(object $currentUser, array $fieldsToRemove): void
    {
        if (\array_intersect(['email', 'user_email'], $fieldsToRemove) !== []) {
            $currentEmail = (string) ($currentUser->user_email ?? '');
            $_POST['email'] = $currentEmail;
            $_REQUEST['email'] = $currentEmail;
        }

        if (\in_array('nickname', $fieldsToRemove, true)) {
            $currentNickname = (string) ($currentUser->nickname ?? '');
            if ($currentNickname === '') {
                $currentNickname = (string) ($currentUser->display_name ?? '');
            }
            if ($currentNickname === '') {
                $currentNickname = (string) ($currentUser->user_login ?? '');
            }
            $_POST['nickname'] = $currentNickname;
            $_REQUEST['nickname'] = $currentNickname;
        }

        if (\array_intersect(['color_scheme', 'admin_color', 'admin-color'], $fieldsToRemove) !== []) {
            $currentColor = (string) ($currentUser->admin_color ?? 'fresh');
            if ($currentColor === '') {
                $currentColor = 'fresh';
            }
            $_POST['admin_color'] = $currentColor;
            $_REQUEST['admin_color'] = $currentColor;
        }

        if (\array_intersect(['syntax_highlighting', 'syntax-highlighting'], $fieldsToRemove) !== []) {
            $currentSyntax = (string) ($currentUser->syntax_highlighting ?? 'true');
            if ($currentSyntax === '') {
                $currentSyntax = 'true';
            }
            $_POST['syntax_highlighting'] = $currentSyntax;
            $_REQUEST['syntax_highlighting'] = $currentSyntax;
        }

        if (\array_intersect(['toolbar', 'admin_bar', 'admin_bar_front'], $fieldsToRemove) !== []) {
            $_POST['admin_bar_front'] = '0';
            $_REQUEST['admin_bar_front'] = '0';
        }

        foreach ($fieldsToRemove as $target) {
            if (
                \in_array(
                    $target,
                    [
                        'email',
                        'user_email',
                        'nickname',
                        'color_scheme',
                        'admin_color',
                        'admin-color',
                        'syntax_highlighting',
                        'syntax-highlighting',
                        'toolbar',
                        'admin_bar',
                        'admin_bar_front',
                    ],
                    true
                )
            ) {
                continue;
            }
            $cleanTarget = \preg_replace('/[^a-z0-9_-]/', '', $target) ?? '';
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

    public function filterRestPreInsertUser(mixed $preparedUser, mixed $request = null): mixed
    {
        if (!\is_object($preparedUser)) {
            return $preparedUser;
        }

        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            return $preparedUser;
        }

        $targetUserId = (int) ($preparedUser->ID ?? 0);
        $currentUserId = (int) ($currentUser->ID ?? 0);
        if ($targetUserId <= 0 || $currentUserId <= 0 || $targetUserId !== $currentUserId) {
            return $preparedUser;
        }

        $fieldsToRemove = null;
        if ($this->fieldsResolver !== null) {
            /** @var list<string> $fieldsToRemove */
            $fieldsToRemove = (array) ($this->fieldsResolver)($currentUser);
        }

        if ($fieldsToRemove === null || $fieldsToRemove === []) {
            return $preparedUser;
        }

        if (\array_intersect(['email', 'user_email'], $fieldsToRemove) !== []) {
            $email = (string) ($currentUser->user_email ?? '');
            if (\property_exists($preparedUser, 'user_email')) {
                $preparedUser->user_email = $email;
            }
            if (\property_exists($preparedUser, 'email')) {
                $preparedUser->email = $email;
            }
        }

        if (\in_array('nickname', $fieldsToRemove, true) && \property_exists($preparedUser, 'nickname')) {
            $currentNickname = (string) ($currentUser->nickname ?? '');
            if ($currentNickname === '') {
                $currentNickname = (string) ($currentUser->display_name ?? '');
            }
            if ($currentNickname === '') {
                $currentNickname = (string) ($currentUser->user_login ?? '');
            }
            $preparedUser->nickname = $currentNickname;
        }

        if (
            \array_intersect(['first_name', 'first-name'], $fieldsToRemove) !== []
            && \property_exists($preparedUser, 'first_name')
        ) {
            $preparedUser->first_name = (string) ($currentUser->first_name ?? '');
        }

        if (
            \array_intersect(['last_name', 'last-name'], $fieldsToRemove) !== []
            && \property_exists($preparedUser, 'last_name')
        ) {
            $preparedUser->last_name = (string) ($currentUser->last_name ?? '');
        }

        if (
            \array_intersect(['display_name', 'display-name'], $fieldsToRemove) !== []
            && \property_exists($preparedUser, 'display_name')
        ) {
            $preparedUser->display_name = (string) ($currentUser->display_name ?? '');
        }

        if (\array_intersect(['url', 'website'], $fieldsToRemove) !== []) {
            $url = (string) ($currentUser->user_url ?? '');
            if (\property_exists($preparedUser, 'user_url')) {
                $preparedUser->user_url = $url;
            }
            if (\property_exists($preparedUser, 'url')) {
                $preparedUser->url = $url;
            }
        }

        $descriptionAliases = ['description', 'bio', 'about', 'about_yourself', 'about-yourself'];
        if (
            \array_intersect($descriptionAliases, $fieldsToRemove) !== []
            && \property_exists($preparedUser, 'description')
        ) {
            $preparedUser->description = (string) ($currentUser->description ?? '');
        }

        if (
            \array_intersect(['locale', 'language'], $fieldsToRemove) !== []
            && \property_exists($preparedUser, 'locale')
        ) {
            $preparedUser->locale = (string) ($currentUser->locale ?? '');
        }

        foreach ($fieldsToRemove as $target) {
            $cleanTarget = \preg_replace('/[^a-z0-9_-]/', '', $target) ?? '';
            if ($cleanTarget === '') {
                continue;
            }
            if (
                \in_array(
                    $cleanTarget,
                    [
                        'email',
                        'user_email',
                        'nickname',
                        'first_name',
                        'first-name',
                        'last_name',
                        'last-name',
                        'display_name',
                        'display-name',
                        'url',
                        'website',
                        'user_url',
                        'description',
                        'bio',
                        'about',
                        'about_yourself',
                        'about-yourself',
                        'locale',
                        'language',
                    ],
                    true
                )
            ) {
                continue;
            }
            if (\property_exists($preparedUser, $cleanTarget)) {
                unset($preparedUser->{$cleanTarget});
            }
        }

        return $preparedUser;
    }

    private function getCurrentUser(): ?object
    {
        if ($this->currentUserGetter !== null) {
            /** @var ?object $user */
            $user = ($this->currentUserGetter)();
            return \is_object($user) ? $user : null;
        }

        $user = \function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        return \is_object($user) ? $user : null;
    }
}
