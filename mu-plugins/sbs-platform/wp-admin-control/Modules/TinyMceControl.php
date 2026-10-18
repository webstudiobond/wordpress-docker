<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Modules;

/**
 * Manages classic TinyMCE editor toolbar sanitization and button removals.
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
final class TinyMceControl
{
    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(?object): list<string>)|null $buttonsResolver
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $buttonsResolver = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getButtonsToRemoveForUser(?object $user = null): array
    {
        if ($this->buttonsResolver !== null) {
            /** @var list<string> */
            return ($this->buttonsResolver)($user);
        }

        $raw = $this->getEnv('WP_ADMIN_TINYMCE_BUTTONS_REMOVE');
        if ($raw === false || \trim($raw) === '') {
            return [];
        }

        if ($this->ruleParser === null || $this->userMatcher === null) {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = ($this->ruleParser)($raw);
        $buttons = [];
        foreach ($rules as $rule) {
            if (($this->userMatcher)($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = \strtolower(\trim($target));
                    if ($trimmed !== '') {
                        $buttons[] = $trimmed;
                    }
                }
            }
        }

        return \array_values(\array_unique($buttons));
    }

    /**
     * @param array<int|string, mixed> $buttons
     * @param string|null $editorId
     * @param list<string>|null $buttonsToRemove
     * @return list<string>
     */
    public function filterMceButtons(
        array $buttons,
        ?string $editorId = null,
        ?array $buttonsToRemove = null
    ): array {
        $toRemove = $buttonsToRemove ?? $this->getButtonsToRemoveForUser();
        if ($toRemove === []) {
            $result = [];
            foreach ($buttons as $button) {
                if (\is_string($button)) {
                    $result[] = $button;
                }
            }
            return $result;
        }

        $filtered = [];
        foreach ($buttons as $button) {
            if (!\is_string($button)) {
                continue;
            }
            $trimmed = \trim($button);
            if ($trimmed === '') {
                continue;
            }
            if (!\in_array(\strtolower($trimmed), $toRemove, true)) {
                $filtered[] = $trimmed;
            }
        }

        return $filtered;
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
