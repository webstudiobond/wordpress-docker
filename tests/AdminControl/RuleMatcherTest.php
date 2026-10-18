<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Security\RuleMatcher;

final class RuleMatcherTest extends TestCase
{
    public function testParseRulesWithEmptyOrWhitespaceString(): void
    {
        $matcher = new RuleMatcher();

        $this->assertSame([], $matcher->parseRules(''));
        $this->assertSame([], $matcher->parseRules('   ; ;   ; '));
    }

    public function testParseRulesWithSimpleTarget(): void
    {
        $matcher = new RuleMatcher();
        $rules = $matcher->parseRules('users.php');

        $this->assertCount(1, $rules);
        $this->assertSame(['users.php'], $rules[0]['targets']);
        $this->assertTrue($rules[0]['isAll']);
        $this->assertSame('hide', $rules[0]['action']);
        $this->assertFalse($rules[0]['superadmins']);
        $this->assertFalse($rules[0]['invertedSuperadmins']);
    }

    public function testParseRulesWithMultipleTargetsAndRoles(): void
    {
        $matcher = new RuleMatcher();
        $rules = $matcher->parseRules('users.php, plugins.php|role:editor,author|action:block');

        $this->assertCount(1, $rules);
        $this->assertSame(['users.php', 'plugins.php'], $rules[0]['targets']);
        $this->assertSame(['editor', 'author'], $rules[0]['roles']);
        $this->assertSame('block', $rules[0]['action']);
        $this->assertFalse($rules[0]['isAll']);
    }

    public function testParseRulesWithCompactModifiers(): void
    {
        $matcher = new RuleMatcher();
        $rules = $matcher->parseRules('tools.php|hide:superadmins|block:!superadmins,!editor');

        $this->assertCount(2, $rules);

        $this->assertSame('hide', $rules[0]['action']);
        $this->assertTrue($rules[0]['superadmins']);
        $this->assertFalse($rules[0]['invertedSuperadmins']);

        $this->assertSame('block', $rules[1]['action']);
        $this->assertTrue($rules[1]['invertedSuperadmins']);
        $this->assertSame(['editor'], $rules[1]['invertedRoles']);
    }

    public function testParseRulesWithUserIdsAndInversions(): void
    {
        $matcher = new RuleMatcher();
        $rules = $matcher->parseRules('dashboard|user:10,!20|!role:administrator');

        $this->assertCount(1, $rules);
        $this->assertSame([10], $rules[0]['users']);
        $this->assertSame([20], $rules[0]['invertedUsers']);
        $this->assertSame(['administrator'], $rules[0]['invertedRoles']);
    }

    public function testIsUserRestrictedByRuleReturnsFalseForNullUser(): void
    {
        $matcher = new RuleMatcher();
        $rule = $matcher->parseRules('users.php')[0];

        $this->assertFalse($matcher->isUserRestrictedByRule($rule, null));
    }

    public function testIsUserRestrictedByRuleReturnsFalseForSuperAdmin(): void
    {
        $salt = 'test-secret-salt-for-superadmin-verification';
        $user = (object) [
            'ID' => 1,
            'user_login' => 'superadmin',
            'roles' => ['administrator'],
        ];
        $payload = '1:superadmin';
        $hash = \hash_hmac('sha512', $payload, $salt);

        $matcher = new RuleMatcher(
            fileReader: static fn(string $path): string => \str_contains($path, 'salt') ? $salt : $hash,
        );

        $rule = $matcher->parseRules('users.php|all')[0];

        $this->assertTrue($matcher->isSuperAdmin($user));
        $this->assertFalse($matcher->isUserRestrictedByRule($rule, $user));
    }

    public function testIsUserRestrictedByRuleMatchesRoleAndInvertedUser(): void
    {
        $matcher = new RuleMatcher();
        $rule = $matcher->parseRules('tools.php|role:editor|!user:5')[0];

        $restrictedEditor = (object) [
            'ID' => 2,
            'user_login' => 'regular_editor',
            'roles' => ['editor'],
        ];
        $exemptEditor = (object) [
            'ID' => 5,
            'user_login' => 'exempt_editor',
            'roles' => ['editor'],
        ];

        $this->assertTrue($matcher->isUserRestrictedByRule($rule, $restrictedEditor));
        $this->assertFalse($matcher->isUserRestrictedByRule($rule, $exemptEditor));
    }

    public function testIsUserMatchingCosmeticRuleEvaluatesAccurately(): void
    {
        $matcher = new RuleMatcher();
        $rule = $matcher->parseRules('menu|role:editor')[0];

        $editor = (object) [
            'ID' => 2,
            'user_login' => 'editor_user',
            'roles' => ['editor'],
        ];
        $subscriber = (object) [
            'ID' => 3,
            'user_login' => 'sub_user',
            'roles' => ['subscriber'],
        ];

        $this->assertFalse($matcher->isUserMatchingCosmeticRule($rule, null));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($rule, $editor));
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($rule, $subscriber));
    }

    public function testIsSuperAdminHandlesInvalidUserOrMissingSecrets(): void
    {
        $matcherEmpty = new RuleMatcher(
            fileReader: static fn(string $path): false => false,
        );

        $this->assertFalse($matcherEmpty->isSuperAdmin(null));
        $this->assertFalse($matcherEmpty->isSuperAdmin((object) ['ID' => 0, 'user_login' => 'admin']));
        $this->assertFalse($matcherEmpty->isSuperAdmin((object) ['ID' => 1, 'user_login' => '']));
        $this->assertFalse($matcherEmpty->isSuperAdmin((object) ['ID' => 1, 'user_login' => 'admin']));
    }

    public function testExtractUserRolesNormalizesCaseAndHandlesNonArray(): void
    {
        $matcher = new RuleMatcher();

        $this->assertSame([], $matcher->extractUserRoles((object) []));
        $this->assertSame([], $matcher->extractUserRoles((object) ['roles' => 'not_array']));

        $user = (object) ['roles' => ['Administrator', '  Editor  ', '', 'EDITOR']];
        $this->assertSame(['administrator', 'editor'], $matcher->extractUserRoles($user));
    }

    public function testParseRulesWithCompactUserPrefixAndEmptyTokens(): void
    {
        $matcher = new RuleMatcher();
        $rules = $matcher->parseRules('tools.php|hide:user:15,role:,!user:20,!role:|block:user:');

        $this->assertCount(2, $rules);
        $this->assertSame([15], $rules[0]['users']);
        $this->assertSame([20], $rules[0]['invertedUsers']);
        $this->assertSame([], $rules[1]['users']);
    }

    public function testParseRulesWithShorthandModifiersWithoutRoleOrUserPrefix(): void
    {
        $matcher = new RuleMatcher();
        $rules = $matcher->parseRules(
            'widget|editor,author,,! ,!administrator,5,!8,superadmins; '
            . 'menu|!superadmins,editor'
        );

        $this->assertCount(2, $rules);

        $this->assertSame(['widget'], $rules[0]['targets']);
        $this->assertFalse($rules[0]['isAll']);
        $this->assertTrue($rules[0]['superadmins']);
        $this->assertFalse($rules[0]['invertedSuperadmins']);
        $this->assertSame(['editor', 'author'], $rules[0]['roles']);
        $this->assertSame(['administrator'], $rules[0]['invertedRoles']);
        $this->assertSame([5], $rules[0]['users']);
        $this->assertSame([8], $rules[0]['invertedUsers']);

        $this->assertSame(['menu'], $rules[1]['targets']);
        $this->assertTrue($rules[1]['invertedSuperadmins']);
        $this->assertSame(['editor'], $rules[1]['roles']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsSuperAdminResolvesCurrentUserViaWordPressStub(): void
    {
        if (\function_exists('ensure_admin_control_stubs')) {
            ensure_admin_control_stubs();
        }

        $matcher = new RuleMatcher();
        $this->assertFalse($matcher->isSuperAdmin());
    }

    public function testSuperAdminCachingAndBranches(): void
    {
        $salt = 'valid-test-salt-string-for-hash-hmac-321';
        $user = (object) ['ID' => 7, 'user_login' => 'valid_admin'];
        $wrongUser = (object) ['ID' => 9, 'user_login' => 'wrong_admin'];
        $hash = \hash_hmac('sha512', '7:valid_admin', $salt);

        $matcher = new RuleMatcher(
            fileReader: static fn(string $path): string => \str_contains($path, 'salt') ? $salt : $hash
        );

        $this->assertSame($salt, $matcher->getActiveSalt());
        $this->assertSame($salt, $matcher->getActiveSalt());
        $this->assertSame([$hash], $matcher->getSuperAdminHashes());
        $this->assertSame([$hash], $matcher->getSuperAdminHashes());

        $this->assertTrue($matcher->isSuperAdmin($user));
        $this->assertTrue($matcher->isSuperAdmin($user));
        $this->assertFalse($matcher->isSuperAdmin($wrongUser));
        $this->assertFalse($matcher->isSuperAdmin($wrongUser));

        $emptySaltMatcher = new RuleMatcher(
            fileReader: static fn(string $path): string => \str_contains($path, 'salt') ? '' : $hash
        );
        $this->assertFalse($emptySaltMatcher->isSuperAdmin($user));

        $emptyHashesMatcher = new RuleMatcher(
            fileReader: static fn(string $path): string => \str_contains($path, 'salt') ? $salt : "invalid\nshort"
        );
        $this->assertFalse($emptyHashesMatcher->isSuperAdmin($user));
    }

    public function testIsUserRestrictedByRuleAdditionalBranches(): void
    {
        $matcher = new RuleMatcher();

        $invSuperadminRule = $matcher->parseRules('settings|!superadmins')[0];
        $regularUser = (object) ['ID' => 4, 'roles' => ['editor']];
        $this->assertTrue($matcher->isUserRestrictedByRule($invSuperadminRule, $regularUser));

        $userIdRule = $matcher->parseRules('settings|user:4')[0];
        $this->assertTrue($matcher->isUserRestrictedByRule($userIdRule, $regularUser));

        $invertedOnlyRule = $matcher->parseRules('settings|!role:administrator')[0];
        $this->assertTrue($matcher->isUserRestrictedByRule($invertedOnlyRule, $regularUser));

        $adminUser = (object) ['ID' => 1, 'roles' => ['administrator']];
        $this->assertFalse($matcher->isUserRestrictedByRule($invertedOnlyRule, $adminUser));

        $invUserOnlyRule = $matcher->parseRules('settings|!user:99')[0];
        $this->assertTrue($matcher->isUserRestrictedByRule($invUserOnlyRule, $regularUser));

        $allRule = $matcher->parseRules('settings|all')[0];
        $this->assertTrue($matcher->isUserRestrictedByRule($allRule, $regularUser));
    }

    public function testIsUserMatchingCosmeticRuleAllBranches(): void
    {
        $salt = 'test-cosmetic-salt-1234567890';
        $superUser = (object) ['ID' => 1, 'user_login' => 'super'];
        $superHash = \hash_hmac('sha512', '1:super', $salt);

        $matcher = new RuleMatcher(
            fileReader: static fn(string $path): string => \str_contains($path, 'salt') ? $salt : $superHash
        );

        $allRule = $matcher->parseRules('menu|all')[0];
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($allRule, null));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($allRule, $superUser));

        $invSuperRule = $matcher->parseRules('menu|!superadmins')[0];
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($invSuperRule, $superUser));

        $superRule = $matcher->parseRules('menu|superadmins')[0];
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($superRule, $superUser));

        $normalUser = (object) ['ID' => 10, 'roles' => ['editor']];
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($invSuperRule, $normalUser));

        $invUserRule = $matcher->parseRules('menu|!user:10')[0];
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($invUserRule, $normalUser));

        $invRoleRule = $matcher->parseRules('menu|!role:editor')[0];
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($invRoleRule, $normalUser));

        $userMatchRule = $matcher->parseRules('menu|user:10')[0];
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($userMatchRule, $normalUser));

        $roleMatchRule = $matcher->parseRules('menu|role:editor')[0];
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($roleMatchRule, $normalUser));

        $invertedOnlyRule = $matcher->parseRules('menu|!user:99')[0];
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($invertedOnlyRule, $normalUser));

        $noMatchRule = $matcher->parseRules('menu|role:author')[0];
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($noMatchRule, $normalUser));
    }

    public function testParseRulesEdgeCasesAndKeywords(): void
    {
        $matcher = new RuleMatcher();

        $emptySegments = $matcher->parseRules('  ;  ; ,,, |role:editor ; ');
        $this->assertSame([], $emptySegments);

        $compactIgnored = $matcher->parseRules('page|hide:1,true,on,yes,!role:author|other:value');
        $this->assertCount(1, $compactIgnored);
        $this->assertTrue($compactIgnored[0]['isAll']);
        $this->assertSame(['author'], $compactIgnored[0]['invertedRoles']);

        $shorthandBooleans = $matcher->parseRules('page|true|action:hide; page|action:block; page|block; page|hide');
        $this->assertCount(4, $shorthandBooleans);
        $this->assertTrue($shorthandBooleans[0]['isAll']);
        $this->assertSame('hide', $shorthandBooleans[0]['action']);
        $this->assertTrue($shorthandBooleans[1]['isAll']);
        $this->assertSame('block', $shorthandBooleans[1]['action']);
        $this->assertTrue($shorthandBooleans[2]['isAll']);
        $this->assertSame('block', $shorthandBooleans[2]['action']);
        $this->assertTrue($shorthandBooleans[3]['isAll']);
        $this->assertSame('hide', $shorthandBooleans[3]['action']);

        $roleInversions = $matcher->parseRules('page|role:!admin,editor|!role:author,,|user:!5,6|!user:7,,');
        $this->assertCount(1, $roleInversions);
        $this->assertSame(['editor'], $roleInversions[0]['roles']);
        $this->assertSame(['admin', 'author'], $roleInversions[0]['invertedRoles']);
        $this->assertSame([6], $roleInversions[0]['users']);
        $this->assertSame([5, 7], $roleInversions[0]['invertedUsers']);

        $plainTargetNoMod = $matcher->parseRules('just_target');
        $this->assertCount(1, $plainTargetNoMod);
        $this->assertTrue($plainTargetNoMod[0]['isAll']);
    }

    public function testDefaultFileReaderFallback(): void
    {
        $matcher = new RuleMatcher(
            customSuperAdminFile: '/non/existent/path/for/superadmins.txt',
            customSaltFile: '/non/existent/path/for/salt.txt',
            fileReader: null
        );

        $this->assertSame('', $matcher->getActiveSalt());
        $this->assertSame([], $matcher->getSuperAdminHashes());
    }

    public function testGetCurrentUserFallbackAndFileReadBranches(): void
    {
        $customUser = (object) ['ID' => 99, 'user_login' => 'custom_user'];
        $matcherWithGetter = new RuleMatcher(
            currentUserGetter: static fn(): object => $customUser
        );
        $this->assertFalse($matcherWithGetter->isSuperAdmin());

        $matcherRealFile = new RuleMatcher(
            customSaltFile: __FILE__,
            fileReader: null
        );
        $this->assertNotSame('', $matcherRealFile->getActiveSalt());
    }

    public function testParseRulesFullCoverageBranches(): void
    {
        $matcher = new RuleMatcher();

        $input = ' |hide:all; '
            . 'target1|hide:all| |block:all; '
            . 'target2|hide:all,,editor; '
            . 'target3|hide:!; '
            . 'target4|hide:editor; '
            . 'target5| |role:editor; '
            . 'target6|role:editor,,author; '
            . 'target7|user:1,,2';

        $rules = $matcher->parseRules($input);
        $this->assertNotEmpty($rules);
    }

    public function testParseRulesCompactMixedNegationsAndOrderEquivalence(): void
    {
        $matcher = new RuleMatcher();

        $rulesA = $matcher->parseRules('upload.php,media-new.php|hide:!editor,4');
        $rulesB = $matcher->parseRules('upload.php,media-new.php|hide:4,!editor');

        $this->assertCount(1, $rulesA);
        $this->assertCount(1, $rulesB);

        $this->assertSame(['editor'], $rulesA[0]['invertedRoles']);
        $this->assertSame([4], $rulesA[0]['users']);
        $this->assertSame([], $rulesA[0]['roles']);
        $this->assertSame([], $rulesA[0]['invertedUsers']);

        $this->assertSame($rulesA[0]['invertedRoles'], $rulesB[0]['invertedRoles']);
        $this->assertSame($rulesA[0]['users'], $rulesB[0]['users']);

        $blockRulesA = $matcher->parseRules('upload.php|block:!author,editor');
        $blockRulesB = $matcher->parseRules('upload.php|block:editor,!author');

        $this->assertSame(['author'], $blockRulesA[0]['invertedRoles']);
        $this->assertSame(['editor'], $blockRulesA[0]['roles']);
        $this->assertSame($blockRulesA[0]['invertedRoles'], $blockRulesB[0]['invertedRoles']);
        $this->assertSame($blockRulesA[0]['roles'], $blockRulesB[0]['roles']);
    }

    public function testParseRulesGroupPrefixNegations(): void
    {
        $matcher = new RuleMatcher();

        $rulesRole = $matcher->parseRules('upload.php|block:!role:author,editor');
        $this->assertSame(['author', 'editor'], $rulesRole[0]['invertedRoles']);
        $this->assertSame([], $rulesRole[0]['roles']);

        $rulesRoleInvertedItem = $matcher->parseRules('upload.php|hide:role:!admin,editor');
        $this->assertSame(['admin'], $rulesRoleInvertedItem[0]['invertedRoles']);
        $this->assertSame(['editor'], $rulesRoleInvertedItem[0]['roles']);

        $rulesUser = $matcher->parseRules('options-connectors.php|hide:!user:2,3');
        $this->assertSame([2, 3], $rulesUser[0]['invertedUsers']);
        $this->assertSame([], $rulesUser[0]['users']);

        $rulesPosRole = $matcher->parseRules('options.php|hide:role:author,editor');
        $this->assertSame(['author', 'editor'], $rulesPosRole[0]['roles']);
        $this->assertSame([], $rulesPosRole[0]['invertedRoles']);

        $rulesPosUser = $matcher->parseRules('options.php|hide:user:2,3');
        $this->assertSame([2, 3], $rulesPosUser[0]['users']);
        $this->assertSame([], $rulesPosUser[0]['invertedUsers']);
    }

    public function testParseRulesCompactSingleUserNegations(): void
    {
        $matcher = new RuleMatcher();

        $rulesMixed = $matcher->parseRules('options-connectors.php|hide:!2,3');
        $this->assertSame([2], $rulesMixed[0]['invertedUsers']);
        $this->assertSame([3], $rulesMixed[0]['users']);

        $rulesBoth = $matcher->parseRules('options-connectors.php|hide:!2,!3');
        $this->assertSame([2, 3], $rulesBoth[0]['invertedUsers']);
        $this->assertSame([], $rulesBoth[0]['users']);
    }

    public function testParseRulesIgnoresUnknownColonModifiersInCompactAndPipeModes(): void
    {
        $matcher = new RuleMatcher();

        $pipeUnknown = $matcher->parseRules('upload.php|action:readonly');
        $this->assertTrue($pipeUnknown[0]['isAll']);
        $this->assertSame([], $pipeUnknown[0]['roles']);

        $compactUnknown = $matcher->parseRules('upload.php|hide:!editor,action:readonly,4');
        $this->assertSame(['editor'], $compactUnknown[0]['invertedRoles']);
        $this->assertSame([4], $compactUnknown[0]['users']);
        $this->assertSame([], $compactUnknown[0]['roles']);

        $pipeMixed = $matcher->parseRules('upload.php|custom:prefix,editor');
        $this->assertSame(['editor'], $pipeMixed[0]['roles']);
        $this->assertFalse($pipeMixed[0]['isAll']);
    }

    public function testEvaluatorDirectUserInclusionOverridesRoleInversion(): void
    {
        $salt = 'salt-for-superadmin-evaluator-test-string';
        $superUser = (object) [
            'ID' => 1,
            'user_login' => 'superadmin_hero',
            'roles' => ['administrator'],
        ];
        $superHash = \hash_hmac('sha512', '1:superadmin_hero', $salt);

        $matcher = new RuleMatcher(
            fileReader: static fn(string $path): string => \str_contains($path, 'salt') ? $salt : $superHash
        );

        $cosmeticRule = $matcher->parseRules('upload.php|hide:!editor,4')[0];
        $blockRule = $matcher->parseRules('upload.php|block:!editor,4')[0];

        $editor4 = (object) ['ID' => 4, 'roles' => ['editor']];
        $editor10 = (object) ['ID' => 10, 'roles' => ['editor']];
        $subscriber12 = (object) ['ID' => 12, 'roles' => ['subscriber']];
        $author14 = (object) ['ID' => 14, 'roles' => ['author']];

        $this->assertTrue($matcher->isUserMatchingCosmeticRule($cosmeticRule, $editor4));
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($cosmeticRule, $editor10));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($cosmeticRule, $subscriber12));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($cosmeticRule, $author14));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($cosmeticRule, $superUser));

        $this->assertFalse($matcher->isUserRestrictedByRule($blockRule, $superUser));
        $this->assertTrue($matcher->isUserRestrictedByRule($blockRule, $editor4));
        $this->assertFalse($matcher->isUserRestrictedByRule($blockRule, $editor10));
        $this->assertTrue($matcher->isUserRestrictedByRule($blockRule, $subscriber12));
    }

    public function testEvaluatorSuperAdminExemptionInCosmeticRuleWithAllAndInvertedSuperadmins(): void
    {
        $salt = 'salt-for-cosmetic-all-superadmin-test';
        $superUser = (object) [
            'ID' => 1,
            'user_login' => 'super_boss',
            'roles' => ['administrator'],
        ];
        $superHash = \hash_hmac('sha512', '1:super_boss', $salt);

        $matcher = new RuleMatcher(
            fileReader: static fn(string $path): string => \str_contains($path, 'salt') ? $salt : $superHash
        );

        $rule = $matcher->parseRules('settings|all|!superadmins')[0];
        $normalEditor = (object) ['ID' => 20, 'roles' => ['editor']];

        $this->assertFalse($matcher->isUserMatchingCosmeticRule($rule, $superUser));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($rule, $normalEditor));
    }

    public function testEvaluatorSpecificUserExemptionWithPositiveRole(): void
    {
        $matcher = new RuleMatcher();
        $rule = $matcher->parseRules('tools.php|role:editor|!user:5')[0];

        $exemptEditor = (object) ['ID' => 5, 'roles' => ['editor']];
        $restrictedEditor = (object) ['ID' => 6, 'roles' => ['editor']];
        $subscriber = (object) ['ID' => 7, 'roles' => ['subscriber']];

        $this->assertFalse($matcher->isUserRestrictedByRule($rule, $exemptEditor));
        $this->assertTrue($matcher->isUserRestrictedByRule($rule, $restrictedEditor));
        $this->assertFalse($matcher->isUserRestrictedByRule($rule, $subscriber));

        $this->assertFalse($matcher->isUserMatchingCosmeticRule($rule, $exemptEditor));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($rule, $restrictedEditor));
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($rule, $subscriber));

        $compactRule = $matcher->parseRules('tools.php|hide:editor,!5')[0];
        $this->assertSame(['editor'], $compactRule['roles']);
        $this->assertSame([5], $compactRule['invertedUsers']);
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($compactRule, $exemptEditor));
        $this->assertTrue($matcher->isUserMatchingCosmeticRule($compactRule, $restrictedEditor));
        $this->assertFalse($matcher->isUserMatchingCosmeticRule($compactRule, $subscriber));
    }
}
