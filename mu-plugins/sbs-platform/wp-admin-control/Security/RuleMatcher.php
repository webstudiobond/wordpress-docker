<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Security;

/**
 * Parses and evaluates administrative access rules, roles, and super-administrator permissions.
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
final class RuleMatcher
{
    public const string DEFAULT_SUPER_ADMIN_SECRETS_PATH = '/run/secrets/wp_super_admins';
    public const string DEFAULT_SALT_SECRETS_PATH = '/run/secrets/auth_salt';

    private ?string $cachedSalt = null;

    /**
     * @var list<string>|null
     */
    private ?array $cachedSuperAdminHashes = null;

    /**
     * @var array<int, bool>
     */
    private array $superAdminCheckCache = [];

    /**
     * @param (callable(string): (string|false))|null $fileReader
     * @param (callable(): (?object))|null $currentUserGetter
     */
    public function __construct(
        private readonly ?string $customSuperAdminFile = null,
        private readonly ?string $customSaltFile = null,
        private readonly mixed $fileReader = null,
        private readonly mixed $currentUserGetter = null,
    ) {
    }

    public function isSuperAdmin(?object $user = null): bool
    {
        $userObj = $user ?? $this->getCurrentUser();
        if ($userObj === null) {
            return false;
        }

        $userId = (int) ($userObj->ID ?? 0);
        $userLogin = (string) ($userObj->user_login ?? '');
        if ($userId <= 0 || \trim($userLogin) === '') {
            return false;
        }

        if (isset($this->superAdminCheckCache[$userId])) {
            return $this->superAdminCheckCache[$userId];
        }

        $salt = $this->getActiveSalt();
        if ($salt === '') {
            $this->superAdminCheckCache[$userId] = false;
            return false;
        }

        $hashes = $this->getSuperAdminHashes();
        if ($hashes === []) {
            $this->superAdminCheckCache[$userId] = false;
            return false;
        }

        $payload = (string) $userId . ':' . \strtolower(\trim($userLogin));
        $computedHash = \hash_hmac('sha512', $payload, $salt);

        foreach ($hashes as $targetHash) {
            if (\hash_equals($targetHash, $computedHash)) {
                $this->superAdminCheckCache[$userId] = true;
                return true;
            }
        }

        $this->superAdminCheckCache[$userId] = false;
        return false;
    }

    /**
     * @return list<AdminRule>
     */
    public function parseRules(string $rawRules): array
    {
        $statements = \explode(';', $rawRules);
        $parsed = [];

        foreach ($statements as $statement) {
            $trimmedStatement = \trim($statement);
            if ($trimmedStatement === '') {
                continue;
            }

            $segments = \explode('|', $trimmedStatement);
            $rawTargets = \trim($segments[0]);
            if ($rawTargets === '') {
                continue;
            }

            $targets = [];
            foreach (\explode(',', $rawTargets) as $target) {
                $trimmedTarget = \trim($target);
                if ($trimmedTarget !== '') {
                    $targets[] = $trimmedTarget;
                }
            }

            if ($targets === []) {
                continue;
            }

            $targets = \array_values(\array_unique($targets));
            $modifierSegments = \array_slice($segments, 1);
            if ($modifierSegments === []) {
                $parsed[] = [
                    'targets' => $targets,
                    'isAll' => true,
                    'superadmins' => false,
                    'invertedSuperadmins' => false,
                    'roles' => [],
                    'invertedRoles' => [],
                    'users' => [],
                    'invertedUsers' => [],
                    'action' => 'hide',
                ];
                continue;
            }

            $hasCompactModifiers = false;
            foreach ($modifierSegments as $modifier) {
                $trimmedMod = \trim($modifier);
                if (
                    (\str_starts_with($trimmedMod, 'hide:') && $trimmedMod !== 'hide:')
                    || (\str_starts_with($trimmedMod, 'block:') && $trimmedMod !== 'block:')
                ) {
                    $hasCompactModifiers = true;
                    break;
                }
            }

            if ($hasCompactModifiers) {
                foreach ($modifierSegments as $modifier) {
                    $trimmedMod = \trim($modifier);
                    if ($trimmedMod === '') {
                        continue;
                    }

                    $action = 'hide';
                    $rawAudience = '';
                    if (\str_starts_with($trimmedMod, 'hide:')) {
                        $action = 'hide';
                        $rawAudience = \substr($trimmedMod, 5);
                    } elseif (\str_starts_with($trimmedMod, 'block:')) {
                        $action = 'block';
                        $rawAudience = \substr($trimmedMod, 6);
                    } else {
                        continue;
                    }

                    $roles = [];
                    $invertedRoles = [];
                    $users = [];
                    $invertedUsers = [];
                    $superadmins = false;
                    $invertedSuperadmins = false;
                    $isAll = false;
                    $currentGroupType = null;
                    $currentGroupInverted = false;

                    foreach (\explode(',', $rawAudience) as $item) {
                        $trimmedItem = \trim($item);
                        if ($trimmedItem === '') {
                            continue;
                        }
                        if (
                            $trimmedItem === 'all'
                            || \in_array(\strtolower($trimmedItem), ['true', '1', 'on', 'yes'], true)
                        ) {
                            $isAll = true;
                            continue;
                        }

                        if (\str_starts_with($trimmedItem, '!role:')) {
                            $currentGroupType = 'role';
                            $currentGroupInverted = true;
                            $val = \substr($trimmedItem, 6);
                        } elseif (\str_starts_with($trimmedItem, 'role:')) {
                            $currentGroupType = 'role';
                            $currentGroupInverted = false;
                            $val = \substr($trimmedItem, 5);
                        } elseif (\str_starts_with($trimmedItem, '!user:')) {
                            $currentGroupType = 'user';
                            $currentGroupInverted = true;
                            $val = \substr($trimmedItem, 6);
                        } elseif (\str_starts_with($trimmedItem, 'user:')) {
                            $currentGroupType = 'user';
                            $currentGroupInverted = false;
                            $val = \substr($trimmedItem, 5);
                        } elseif (\str_contains($trimmedItem, ':')) {
                            $currentGroupType = null;
                            $currentGroupInverted = false;
                            continue;
                        } else {
                            $val = $trimmedItem;
                        }

                        $val = \trim($val);
                        if ($val === '') {
                            continue;
                        }

                        if ($currentGroupType !== null) {
                            if (\str_starts_with($val, '!')) {
                                $isInverted = true;
                                $val = \trim(\substr($val, 1));
                            } else {
                                $isInverted = $currentGroupInverted;
                            }
                        } else {
                            if (\str_starts_with($val, '!')) {
                                $isInverted = true;
                                $val = \trim(\substr($val, 1));
                            } else {
                                $isInverted = false;
                            }
                        }

                        if ($val === '') {
                            continue;
                        }

                        if ($val === 'superadmins') {
                            if ($isInverted) {
                                $invertedSuperadmins = true;
                            } else {
                                $superadmins = true;
                            }
                            continue;
                        }

                        if ($currentGroupType === 'user') {
                            $uid = (int) $val;
                            if ($uid > 0) {
                                if ($isInverted) {
                                    $invertedUsers[] = $uid;
                                } else {
                                    $users[] = $uid;
                                }
                            }
                        } elseif ($currentGroupType === 'role') {
                            $r = \strtolower($val);
                            if ($isInverted) {
                                $invertedRoles[] = $r;
                            } else {
                                $roles[] = $r;
                            }
                        } else {
                            if (\ctype_digit($val)) {
                                $uid = (int) $val;
                                if ($uid > 0) {
                                    if ($isInverted) {
                                        $invertedUsers[] = $uid;
                                    } else {
                                        $users[] = $uid;
                                    }
                                }
                            } else {
                                $r = \strtolower($val);
                                if ($isInverted) {
                                    $invertedRoles[] = $r;
                                } else {
                                    $roles[] = $r;
                                }
                            }
                        }
                    }

                    $parsed[] = [
                        'targets' => $targets,
                        'isAll' => $isAll,
                        'superadmins' => $superadmins,
                        'invertedSuperadmins' => $invertedSuperadmins,
                        'roles' => \array_values(\array_unique($roles)),
                        'invertedRoles' => \array_values(\array_unique($invertedRoles)),
                        'users' => \array_values(\array_unique($users)),
                        'invertedUsers' => \array_values(\array_unique($invertedUsers)),
                        'action' => $action,
                    ];
                }
                continue;
            }

            $isAll = false;
            $superadmins = false;
            $invertedSuperadmins = false;
            $roles = [];
            $invertedRoles = [];
            $users = [];
            $invertedUsers = [];
            $action = 'hide';
            $hasAudienceModifier = false;

            foreach ($modifierSegments as $modifier) {
                $trimmedMod = \trim($modifier);
                if ($trimmedMod === '') {
                    continue;
                }

                if ($trimmedMod === 'all' || \in_array(\strtolower($trimmedMod), ['true', '1', 'on', 'yes'], true)) {
                    $isAll = true;
                    $hasAudienceModifier = true;
                    continue;
                }

                if ($trimmedMod === 'superadmins') {
                    $superadmins = true;
                    $hasAudienceModifier = true;
                    continue;
                }

                if ($trimmedMod === '!superadmins') {
                    $invertedSuperadmins = true;
                    $hasAudienceModifier = true;
                    continue;
                }

                if ($trimmedMod === 'action:block' || $trimmedMod === 'block') {
                    $action = 'block';
                    continue;
                }

                if ($trimmedMod === 'action:hide' || $trimmedMod === 'hide') {
                    $action = 'hide';
                    continue;
                }

                if (\str_starts_with($trimmedMod, 'role:')) {
                    foreach (\explode(',', \substr($trimmedMod, 5)) as $role) {
                        $trimmedRole = \trim($role);
                        if ($trimmedRole === '') {
                            continue;
                        }
                        if (\str_starts_with($trimmedRole, '!')) {
                            $r = \strtolower(\substr($trimmedRole, 1));
                            if ($r !== '') {
                                $invertedRoles[] = $r;
                                $hasAudienceModifier = true;
                            }
                        } else {
                            $roles[] = \strtolower($trimmedRole);
                            $hasAudienceModifier = true;
                        }
                    }
                } elseif (\str_starts_with($trimmedMod, '!role:')) {
                    foreach (\explode(',', \substr($trimmedMod, 6)) as $role) {
                        $r = \strtolower(\trim($role));
                        if ($r !== '') {
                            $invertedRoles[] = $r;
                            $hasAudienceModifier = true;
                        }
                    }
                } elseif (\str_starts_with($trimmedMod, 'user:')) {
                    foreach (\explode(',', \substr($trimmedMod, 5)) as $userIdStr) {
                        $trimmedUser = \trim($userIdStr);
                        if ($trimmedUser === '') {
                            continue;
                        }
                        if (\str_starts_with($trimmedUser, '!')) {
                            $uid = (int) \substr($trimmedUser, 1);
                            if ($uid > 0) {
                                $invertedUsers[] = $uid;
                                $hasAudienceModifier = true;
                            }
                        } else {
                            $uid = (int) $trimmedUser;
                            if ($uid > 0) {
                                $users[] = $uid;
                                $hasAudienceModifier = true;
                            }
                        }
                    }
                } elseif (\str_starts_with($trimmedMod, '!user:')) {
                    foreach (\explode(',', \substr($trimmedMod, 6)) as $userIdStr) {
                        $uid = (int) \trim($userIdStr);
                        if ($uid > 0) {
                            $invertedUsers[] = $uid;
                            $hasAudienceModifier = true;
                        }
                    }
                } else {
                    foreach (\explode(',', $trimmedMod) as $item) {
                        $trimmedItem = \trim($item);
                        if ($trimmedItem === '' || \str_contains($trimmedItem, ':')) {
                            continue;
                        }

                        $isInverted = \str_starts_with($trimmedItem, '!');
                        $value = \trim($isInverted ? \substr($trimmedItem, 1) : $trimmedItem);
                        if ($value === '') {
                            continue;
                        }

                        if ($value === 'superadmins') {
                            if ($isInverted) {
                                $invertedSuperadmins = true;
                            } else {
                                $superadmins = true;
                            }
                            $hasAudienceModifier = true;
                            continue;
                        }

                        if (\ctype_digit($value)) {
                            $uid = (int) $value;
                            if ($uid > 0) {
                                if ($isInverted) {
                                    $invertedUsers[] = $uid;
                                } else {
                                    $users[] = $uid;
                                }
                                $hasAudienceModifier = true;
                            }
                        } else {
                            $r = \strtolower($value);
                            if ($isInverted) {
                                $invertedRoles[] = $r;
                            } else {
                                $roles[] = $r;
                            }
                            $hasAudienceModifier = true;
                        }
                    }
                }
            }

            if (!$hasAudienceModifier) {
                $isAll = true;
            }

            $parsed[] = [
                'targets' => $targets,
                'isAll' => $isAll,
                'superadmins' => $superadmins,
                'invertedSuperadmins' => $invertedSuperadmins,
                'roles' => \array_values(\array_unique($roles)),
                'invertedRoles' => \array_values(\array_unique($invertedRoles)),
                'users' => \array_values(\array_unique($users)),
                'invertedUsers' => \array_values(\array_unique($invertedUsers)),
                'action' => $action,
            ];
        }

        return $parsed;
    }

    /**
     * @param AdminRule $rule
     */
    public function isUserRestrictedByRule(array $rule, ?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return false;
        }

        $userId = (int) ($user->ID ?? 0);
        $userRoles = $this->extractUserRoles($user);

        if ($userId > 0 && \in_array($userId, $rule['invertedUsers'], true)) {
            return false;
        }

        if ($userId > 0 && \in_array($userId, $rule['users'], true)) {
            return true;
        }

        if (\array_intersect($userRoles, $rule['invertedRoles']) !== []) {
            return false;
        }

        if (\array_intersect($userRoles, $rule['roles']) !== []) {
            return true;
        }

        if ($rule['roles'] === [] && !$rule['superadmins']) {
            if ($rule['invertedRoles'] !== []) {
                return true;
            }
            if ($rule['invertedSuperadmins']) {
                return true;
            }
            if ($rule['invertedUsers'] !== [] && $rule['users'] === []) {
                return true;
            }
        }

        return $rule['isAll'];
    }

    /**
     * @param AdminRule $rule
     */
    public function isUserMatchingCosmeticRule(array $rule, ?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        $isCurrentSuperAdmin = $this->isSuperAdmin($user);
        if ($isCurrentSuperAdmin && $rule['invertedSuperadmins']) {
            return false;
        }

        if ($isCurrentSuperAdmin && $rule['superadmins']) {
            return true;
        }

        $userId = (int) ($user->ID ?? 0);
        $userRoles = $this->extractUserRoles($user);

        if ($userId > 0 && \in_array($userId, $rule['invertedUsers'], true)) {
            return false;
        }

        if ($userId > 0 && \in_array($userId, $rule['users'], true)) {
            return true;
        }

        if (\array_intersect($userRoles, $rule['invertedRoles']) !== []) {
            return false;
        }

        if (\array_intersect($userRoles, $rule['roles']) !== []) {
            return true;
        }

        if ($rule['roles'] === [] && !$rule['superadmins']) {
            if ($rule['invertedRoles'] !== []) {
                return true;
            }
            if (!$isCurrentSuperAdmin && $rule['invertedSuperadmins'] && $rule['users'] === []) {
                return true;
            }
            if ($rule['invertedUsers'] !== [] && $rule['users'] === []) {
                return true;
            }
        }

        return $rule['isAll'];
    }

    /**
     * @return list<string>
     */
    public function extractUserRoles(object $user): array
    {
        $rawRoles = $user->roles ?? [];
        if (!\is_array($rawRoles)) {
            return [];
        }

        $roles = [];
        foreach ($rawRoles as $r) {
            if (\is_string($r) && \trim($r) !== '') {
                $roles[] = \strtolower(\trim($r));
            }
        }

        return \array_values(\array_unique($roles));
    }

    public function getActiveSalt(): string
    {
        if ($this->cachedSalt !== null) {
            return $this->cachedSalt;
        }

        $path = $this->customSaltFile ?? self::DEFAULT_SALT_SECRETS_PATH;
        $content = $this->readFile($path);
        if ($content !== false && \trim($content) !== '') {
            $this->cachedSalt = \trim($content);
            return $this->cachedSalt;
        }

        $this->cachedSalt = '';
        return '';
    }

    /**
     * @return list<string>
     */
    public function getSuperAdminHashes(): array
    {
        if ($this->cachedSuperAdminHashes !== null) {
            return $this->cachedSuperAdminHashes;
        }

        $path = $this->customSuperAdminFile ?? self::DEFAULT_SUPER_ADMIN_SECRETS_PATH;
        $content = $this->readFile($path);
        if ($content !== false && \trim($content) !== '') {
            $lines = \preg_split('/[\r\n,]+/', $content);
            $hashes = [];
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $trimmed = \strtolower(\trim($line));
                    if ($trimmed !== '' && \preg_match('/^[a-f0-9]{128}$/', $trimmed) === 1) {
                        $hashes[] = $trimmed;
                    }
                }
            }
            if ($hashes !== []) {
                $this->cachedSuperAdminHashes = \array_values(\array_unique($hashes));
                return $this->cachedSuperAdminHashes;
            }
        }

        $this->cachedSuperAdminHashes = [];
        return [];
    }

    private function readFile(string $path): string|false
    {
        if (\is_callable($this->fileReader)) {
            return ($this->fileReader)($path);
        }

        return \is_file($path) ? \file_get_contents($path) : false;
    }

    private function getCurrentUser(): ?object
    {
        if (\is_callable($this->currentUserGetter)) {
            return ($this->currentUserGetter)();
        }

        if (\function_exists('wp_get_current_user')) {
            $u = wp_get_current_user();
            return \is_object($u) ? $u : null;
        }

        return null;
    }
}
