<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Modules\TinyMceControl;

/**
 * Validates TinyMCE editor toolbar filtering, button resolution, and audience matching.
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
final class TinyMceControlTest extends TestCase
{
    public function testGetButtonsToRemoveWithCustomResolver(): void
    {
        $control = new TinyMceControl(
            buttonsResolver: static fn(?object $user): array => ['bullist', 'numlist']
        );

        $this->assertSame(['bullist', 'numlist'], $control->getButtonsToRemoveForUser());
    }

    public function testGetButtonsToRemoveWhenEnvNotSetOrEmpty(): void
    {
        $scenarios = [
            'false' => false,
            'empty' => '',
            'whitespace' => '   ',
        ];

        foreach ($scenarios as $envVal) {
            $control = new TinyMceControl(
                envGetter: static fn(string $name): string|false => $envVal
            );
            $this->assertSame([], $control->getButtonsToRemoveForUser());
        }
    }

    public function testGetButtonsToRemoveWhenParserOrMatcherMissing(): void
    {
        $controlNoParser = new TinyMceControl(
            envGetter: static fn(string $name): string => 'bullist,numlist'
        );
        $this->assertSame([], $controlNoParser->getButtonsToRemoveForUser());

        $controlNoMatcher = new TinyMceControl(
            envGetter: static fn(string $name): string => 'bullist,numlist',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['bullist'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]]
        );
        $this->assertSame([], $controlNoMatcher->getButtonsToRemoveForUser());
    }

    public function testGetButtonsToRemoveWithParsedRulesAndMatching(): void
    {
        $currentUser = (object) ['ID' => 42, 'user_login' => 'editor_user'];

        /** @var list<AdminRule> $rules */
        $rules = [
            [
                'targets' => ['bold', 'italic', '  '],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ],
            [
                'targets' => ['link', 'unlink'],
                'isAll' => false,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => ['administrator'],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ],
            [
                'targets' => ['BOLD', 'underline'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ],
        ];

        $control = new TinyMceControl(
            envGetter: static fn(string $name): string => 'raw-rule-string',
            currentUserGetter: static fn(): object => $currentUser,
            ruleParser: static fn(string $raw): array => $rules,
            userMatcher: static function (array $rule, ?object $user): bool {
                return $rule['isAll'];
            }
        );

        $removed = $control->getButtonsToRemoveForUser();
        $this->assertSame(['bold', 'italic', 'underline'], $removed);

        $explicitUser = (object) ['ID' => 99];
        $removedExplicit = $control->getButtonsToRemoveForUser($explicitUser);
        $this->assertSame(['bold', 'italic', 'underline'], $removedExplicit);
    }

    public function testFilterMceButtonsWhenNoButtonsToRemove(): void
    {
        $control = new TinyMceControl(
            buttonsResolver: static fn(?object $user): array => []
        );

        $input = ['bold', 123, 'italic', null, 'link'];
        $filtered = $control->filterMceButtons($input);

        $this->assertSame(['bold', 'italic', 'link'], $filtered);
    }

    public function testFilterMceButtonsWithExplicitRemovalList(): void
    {
        $control = new TinyMceControl();

        $input = ['bold', 'italic', 'link', '   ', 456, 'unlink', 'BOLD'];
        $toRemove = ['bold', 'link'];

        $filtered = $control->filterMceButtons(
            buttons: $input,
            editorId: 'content',
            buttonsToRemove: $toRemove
        );

        $this->assertSame(['italic', 'unlink'], $filtered);
    }

    public function testFallbackGetEnvAndCurrentUser(): void
    {
        $control = new TinyMceControl(
            envGetter: static fn(string $name): string => 'bold',
            ruleParser: static fn(string $raw): array => [[
                'targets' => ['bold'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ]],
            userMatcher: static function (array $rule, ?object $user): bool {
                return $user === null;
            }
        );

        $this->assertSame(['bold'], $control->getButtonsToRemoveForUser());

        $defaultControl = new TinyMceControl();
        $this->assertSame([], $defaultControl->getButtonsToRemoveForUser());
    }
}
