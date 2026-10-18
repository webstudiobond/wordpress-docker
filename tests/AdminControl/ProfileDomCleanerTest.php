<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Dom\ProfileDomCleaner;
use WordPressDocker\Tests\WpCoreCleanupTest;

final class ProfileDomCleanerTest extends TestCase
{
    private int $initialObLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialObLevel = \ob_get_level();
    }

    protected function tearDown(): void
    {
        while (\ob_get_level() > $this->initialObLevel) {
            \ob_end_clean();
        }
        parent::tearDown();
    }

    public function testBufferingLifecycle(): void
    {
        $cleaner = new ProfileDomCleaner(fn(): array => ['email']);
        $this->assertFalse($cleaner->isBufferingStarted());

        $cleaner->startOutputBuffering();
        $this->assertTrue($cleaner->isBufferingStarted());

        $cleaner->startOutputBuffering();
        $this->assertTrue($cleaner->isBufferingStarted());
    }

    public function testFilterBuffer(): void
    {
        $cleanerWithoutResolver = new ProfileDomCleaner();
        $sampleHtml = '<form id="your-profile"><tr class="user-url-wrap"><td>Site</td></tr></form>';

        $this->assertSame($sampleHtml, $cleanerWithoutResolver->filterBuffer($sampleHtml));

        $filteredExplicit = $cleanerWithoutResolver->filterBuffer($sampleHtml, fn(): array => ['url']);
        $this->assertStringNotContainsString('user-url-wrap', $filteredExplicit);

        $cleanerWithResolver = new ProfileDomCleaner(fn(): array => ['url']);
        $filteredImplicit = $cleanerWithResolver->filterBuffer($sampleHtml);
        $this->assertStringNotContainsString('user-url-wrap', $filteredImplicit);
    }

    public function testRemoveProfileFieldsFromHtmlWithEmptyOrMissingForm(): void
    {
        $cleaner = new ProfileDomCleaner();

        $plainText = '<div>No form here</div>';
        $this->assertSame($plainText, $cleaner->removeProfileFieldsFromHtml($plainText, ['email']));

        $emptyFields = '<form id="your-profile"><div>Content</div></form>';
        $this->assertSame($emptyFields, $cleaner->removeProfileFieldsFromHtml($emptyFields, []));

        $whitespaceForm = '<form id="your-profile">   </form>';
        $this->assertSame($whitespaceForm, $cleaner->removeProfileFieldsFromHtml($whitespaceForm, ['email']));

        $createuserForm = '<form id="createuser"><tr class="user-url-wrap"><td>Site</td></tr></form>';
        $cleanedCreateUser = $cleaner->removeProfileFieldsFromHtml($createuserForm, ['url']);
        $this->assertStringNotContainsString('user-url-wrap', $cleanedCreateUser);
    }

    public function testRemoveProfileFieldsFromHtmlWithDocLoaderFallbacks(): void
    {
        $sampleHtml = '<form id="your-profile"><div>Some Field</div></form>';

        $nullDocCleaner = new ProfileDomCleaner(docLoader: fn(string $h): ?\DOMDocument => null);
        $this->assertSame($sampleHtml, $nullDocCleaner->removeProfileFieldsFromHtml($sampleHtml, ['email']));

        $emptyDocCleaner = new ProfileDomCleaner(docLoader: fn(string $h): \DOMDocument => new \DOMDocument());
        $this->assertSame($sampleHtml, $emptyDocCleaner->removeProfileFieldsFromHtml($sampleHtml, ['email']));
    }

    public function testRemoveProfileFieldsFromHtmlWithStandardAliases(): void
    {
        $cleaner = new ProfileDomCleaner();

        $form = <<<'HTML'
<form id="your-profile">
  <h2>Name</h2>
  <table>
    <tr class="user-user-login-wrap"><th>Username</th><td><input id="user_login" name="user_login" /></td></tr>
    <tr class="user-first-name-wrap"><th>First Name</th><td><input id="first_name" name="first_name" /></td></tr>
  </table>
  <h2>Contact Info</h2>
  <table>
    <tr class="user-url-wrap"><th>Website</th><td><input id="url" name="url" /></td></tr>
  </table>
  <h2>About Yourself</h2>
  <table>
    <tr class="user-description-wrap"><th>Bio</th><td><textarea name="description">Bio</textarea></td></tr>
    <tr class="user-profile-picture-wrap">
      <th>Avatar</th><td><img src="https://example.org/avatar.png" alt="Avatar" /></td>
    </tr>
  </table>
  <div id="wfls-user-settings">
    <h2>Wordfence 2FA</h2>
    <table>
      <tr id="wfls-user-2fa-status"><th>Wordfence 2FA</th><td>Enabled</td></tr>
    </table>
  </div>
</form>
HTML;

        $cleaned = $cleaner->removeProfileFieldsFromHtml(
            $form,
            ['user_login', 'url', 'bio', 'profile_picture', 'wfls', '', '   ']
        );

        $this->assertStringNotContainsString('user-user-login-wrap', $cleaned);
        $this->assertStringNotContainsString('user-url-wrap', $cleaned);
        $this->assertStringNotContainsString('Contact Info', $cleaned);
        $this->assertStringNotContainsString('user-description-wrap', $cleaned);
        $this->assertStringNotContainsString('user-profile-picture-wrap', $cleaned);
        $this->assertStringNotContainsString('About Yourself', $cleaned);
        $this->assertStringNotContainsString('wfls-user-settings', $cleaned);
        $this->assertStringNotContainsString('Wordfence 2FA', $cleaned);
        $this->assertStringContainsString('first_name', $cleaned);
        $this->assertStringContainsString('Name', $cleaned);
    }

    public function testTableAndContainerPrecedingHeadingVariations(): void
    {
        $cleaner = new ProfileDomCleaner();

        $nonHeadingPreceding = <<<'HTML'
<form id="your-profile">
  <div class="notice">Some non-heading notice</div>
  <table>
    <tr class="user-url-wrap"><th>Website</th><td><input id="url" /></td></tr>
  </table>
  <table>
    <tr class="user-description-wrap"><th>Bio</th><td><textarea name="description"></textarea></td></tr>
  </table>
</form>
HTML;
        $cleaned1 = $cleaner->removeProfileFieldsFromHtml($nonHeadingPreceding, ['url', 'description']);
        $this->assertStringContainsString('Some non-heading notice', $cleaned1);
        $this->assertStringNotContainsString('<table>', $cleaned1);

        $containerVariations = <<<'HTML'
<form id="your-profile">
  <h2>Yoast SEO</h2>
  <div id="yoast-seo-settings">
    <table>
      <tr class="user-custom-wrap"><td>Yoast Option</td></tr>
    </table>
  </div>
  <h3>Application Passwords</h3>
  <div class="application-passwords extra-class">
    <table>
      <tr class="user-passwords-wrap"><td>Password Option</td></tr>
    </table>
  </div>
  <div class="application-passwords">
    <table>
      <tr><td>Remaining Data</td></tr>
    </table>
  </div>
</form>
HTML;
        $cleaned2 = $cleaner->removeProfileFieldsFromHtml($containerVariations, ['custom', 'passwords']);
        $this->assertStringNotContainsString('yoast-seo-settings', $cleaned2);
        $this->assertStringNotContainsString('Yoast SEO', $cleaned2);
        $this->assertStringNotContainsString('Application Passwords', $cleaned2);
        $this->assertStringContainsString('Remaining Data', $cleaned2);
    }

    public function testBuildProfileFieldXPathQueries(): void
    {
        $cleaner = new ProfileDomCleaner();

        foreach (['user_login', 'user-login', 'username'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('user_login', $queries[2]);
        }

        foreach (['url', 'website'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('url', $queries[1]);
        }

        foreach (['description', 'bio'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('description', $queries[2]);
        }

        foreach (['profile_picture', 'avatar', 'profile-picture'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('user-profile-picture-wrap', $queries[0]);
        }

        foreach (['about', 'about_yourself', 'about-yourself'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertCount(5, $queries);
        }

        foreach (['wordfence', 'wfls', 'wordfence_2fa', 'wordfence_passkeys'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('wfls-user-settings', $queries[0]);
        }

        foreach (['yoast', 'yoast_seo', 'yoast-seo', 'wpseo'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('yoast', $queries[1]);
        }

        foreach (['elementor_ai', 'elementor-ai', 'elementor_enable_ai'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('Elementor - AI', $queries[2]);
        }

        foreach (['elementor'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('elementor', $queries[0]);
        }

        foreach (['ai_features', 'ai-features', 'ai_generator_consent', 'ai-generator-consent'] as $alias) {
            $queries = $cleaner->buildProfileFieldXPathQueries($alias);
            $this->assertNotEmpty($queries);
            $this->assertStringContainsString('ai-generator-consent', $queries[0]);
        }

        $customQueries = $cleaner->buildProfileFieldXPathQueries('billing_address');
        $this->assertNotEmpty($customQueries);
        $this->assertStringContainsString('billing_address', $customQueries[0]);

        $emptyQueries = $cleaner->buildProfileFieldXPathQueries('!@#$%^&*()');
        $this->assertSame([], $emptyQueries);
    }

    public function testYoastAndElementorRealWorldHtmlCleaning(): void
    {
        $cleaner = new ProfileDomCleaner();

        $form = <<<'HTML'
<form id="your-profile">
  <table>
    <tr>
      <th style="padding:0px">
        <h2>Elementor - AI</h2>
      </th>
    </tr>
    <tr class="user-elementor_enable_ai-wrap">
      <th><label for="elementor_enable_ai">Enable AI</label></th>
      <td><input type="checkbox" id="elementor_enable_ai" name="elementor_enable_ai" /></td>
    </tr>
  </table>
  <div class="yoast yoast-settings">
    <h2 id="wordpress-seo">Yoast SEO settings</h2>
    <input class="yoast-settings__checkbox double" type="checkbox"
      id="wpseo_keyword_analysis_disable" name="wpseo_keyword_analysis_disable"
      aria-describedby="wpseo_keyword_analysis_disable_desc  secondary_desc invalid&quot;id" value="on">
    <label class="yoast-label-strong" for="wpseo_keyword_analysis_disable">Disable SEO analysis</label><br>
    <p class="description" id="wpseo_keyword_analysis_disable_desc">
      Removes the focus keyphrase section from the metabox and disables all SEO suggestions.
    </p>
    <input class="yoast-settings__checkbox double" type="checkbox"
      id="wpseo_content_analysis_disable" name="wpseo_content_analysis_disable"
      aria-describedby="wpseo_content_analysis_disable_desc" value="on">
    <label class="yoast-label-strong" for="wpseo_content_analysis_disable">Disable readability analysis</label><br>
    <p class="description" id="wpseo_content_analysis_disable_desc">
      Removes readability analysis section from the metabox and disables suggestions.
    </p>
    <input class="yoast-settings__checkbox double" type="checkbox"
      id="wpseo_inclusive_language_analysis_disable" name="wpseo_inclusive_language_analysis_disable"
      aria-describedby="wpseo_inclusive_language_analysis_disable_desc" value="on">
    <label class="yoast-label-strong"
      for="wpseo_inclusive_language_analysis_disable">Disable inclusive language analysis</label><br>
    <p class="description" id="wpseo_inclusive_language_analysis_disable_desc">
      Removes the inclusive language analysis section from the metabox.
    </p>
  </div>
  <div class="yoast yoast-settings">
    <label for="ai-generator-consent-button">AI features</label>
    <div id="ai-generator-consent" style="display:inline-block; margin-top: 28px; padding-left:5px;">
      <button class="button" id="ai-generator-consent-button" type="button">Grant consent</button>
    </div>
  </div>
</form>
HTML;

        $fieldsToRemove = [
            'elementor_enable_ai',
            'wpseo_keyword_analysis_disable',
            'wpseo_content_analysis_disable',
            'wpseo_inclusive_language_analysis_disable',
            'ai-generator-consent',
        ];

        $cleaned = $cleaner->removeProfileFieldsFromHtml($form, $fieldsToRemove);

        $this->assertStringNotContainsString('Elementor - AI', $cleaned);
        $this->assertStringNotContainsString('Yoast SEO settings', $cleaned);
        $this->assertStringNotContainsString('AI features', $cleaned);
        $this->assertStringNotContainsString('wpseo_keyword_analysis_disable', $cleaned);
        $this->assertStringNotContainsString('wpseo_keyword_analysis_disable_desc', $cleaned);
        $this->assertStringNotContainsString('wpseo_content_analysis_disable_desc', $cleaned);
        $this->assertStringNotContainsString('wpseo_inclusive_language_analysis_disable_desc', $cleaned);
        $this->assertStringNotContainsString('Disable SEO analysis', $cleaned);
        $this->assertStringNotContainsString('Grant consent', $cleaned);
        $this->assertStringNotContainsString('yoast-settings', $cleaned);
    }

    public function testTableWithActiveAndInactiveHeadingRows(): void
    {
        $cleaner = new ProfileDomCleaner();

        $form = <<<'HTML'
<form id="your-profile">
  <table>
    <tr><th><h2>Section 1 - Inactive</h2></th></tr>
    <tr class="user-field1-wrap"><td><input id="field1" name="field1" /></td></tr>
    <tr><th><h2>Section 2 - Active</h2></th></tr>
    <tr class="user-field2-wrap"><td><input id="field2" name="field2" /></td></tr>
  </table>
</form>
HTML;

        $cleaned = $cleaner->removeProfileFieldsFromHtml($form, ['field1']);
        $this->assertStringNotContainsString('Section 1 - Inactive', $cleaned);
        $this->assertStringContainsString('Section 2 - Active', $cleaned);
        $this->assertStringContainsString('field2', $cleaned);
    }

    public function testBufferStarterCallable(): void
    {
        $started = false;
        $cleaner = new ProfileDomCleaner(
            bufferStarter: static function (callable $handler) use (&$started): void {
                $started = true;
            }
        );

        $cleaner->startOutputBuffering();
        $this->assertTrue($started);
    }

    public function testGetProfileFieldsToRemoveForUserBranches(): void
    {
        $emptyEnvCleaner = new ProfileDomCleaner(
            envGetter: static fn(string $name): false => false
        );
        $this->assertSame([], $emptyEnvCleaner->getProfileFieldsToRemoveForUser());

        $noParserCleaner = new ProfileDomCleaner(
            envGetter: static fn(string $name): string => 'bio,url'
        );
        $this->assertSame([], $noParserCleaner->getProfileFieldsToRemoveForUser());

        $user = (object) ['ID' => 12];
        $rule = [
            'targets' => ['Bio', '  website  ', 'Custom_Field', ''],
            'isAll' => true,
            'superadmins' => false,
            'invertedSuperadmins' => false,
            'roles' => [],
            'invertedRoles' => [],
            'users' => [],
            'invertedUsers' => [],
            'action' => 'hide',
        ];

        $matchedCleaner = new ProfileDomCleaner(
            envGetter: static fn(string $name): string => 'bio,website,custom_field',
            currentUserGetter: static fn(): object => $user,
            ruleParser: static fn(string $raw): array => [$rule],
            userMatcher: static fn(array $r, ?object $u): bool => $u === $user
        );

        $fields = $matchedCleaner->getProfileFieldsToRemoveForUser();
        $this->assertSame(['bio', 'website', 'custom_field'], $fields);
    }

    public function testFilterUserContactMethods(): void
    {
        $cleanerNoFields = new ProfileDomCleaner(
            envGetter: static fn(string $name): false => false
        );
        $methods = ['jabber' => 'Jabber', 'url' => 'Website'];
        $this->assertSame($methods, $cleanerNoFields->filterUserContactMethods($methods));

        $rule = [
            'targets' => ['website', 'twitter'],
            'isAll' => true,
            'superadmins' => false,
            'invertedSuperadmins' => false,
            'roles' => [],
            'invertedRoles' => [],
            'users' => [],
            'invertedUsers' => [],
            'action' => 'hide',
        ];

        $cleanerWithFields = new ProfileDomCleaner(
            envGetter: static fn(string $name): string => 'website,twitter',
            ruleParser: static fn(string $raw): array => [$rule],
            userMatcher: static fn(array $r, ?object $u): bool => true
        );

        $initialMethods = ['url' => 'URL', 'website' => 'Web', 'Twitter' => 'Tw', 'email' => 'Mail'];
        $filtered = $cleanerWithFields->filterUserContactMethods($initialMethods);
        $this->assertSame(['email' => 'Mail'], $filtered);

        $ruleUrl = [
            'targets' => ['url'],
            'isAll' => true,
            'superadmins' => false,
            'invertedSuperadmins' => false,
            'roles' => [],
            'invertedRoles' => [],
            'users' => [],
            'invertedUsers' => [],
            'action' => 'hide',
        ];
        $cleanerUrl = new ProfileDomCleaner(
            envGetter: static fn(string $name): string => 'url',
            ruleParser: static fn(string $raw): array => [$ruleUrl],
            userMatcher: static fn(array $r, ?object $u): bool => true
        );
        $filteredUrl = $cleanerUrl->filterUserContactMethods(['website' => 'Site', 'phone' => '123']);
        $this->assertSame(['phone' => '123'], $filteredUrl);
    }

    public function testFilterShowAdminBar(): void
    {
        $rule = [
            'targets' => ['toolbar'],
            'isAll' => true,
            'superadmins' => false,
            'invertedSuperadmins' => false,
            'roles' => [],
            'invertedRoles' => [],
            'users' => [],
            'invertedUsers' => [],
            'action' => 'hide',
        ];

        $cleanerToolbar = new ProfileDomCleaner(
            envGetter: static fn(string $name): string => 'toolbar',
            ruleParser: static fn(string $raw): array => [$rule],
            userMatcher: static fn(array $r, ?object $u): bool => true
        );
        $this->assertFalse($cleanerToolbar->filterShowAdminBar(true));

        $cleanerAllowed = new ProfileDomCleaner(
            envGetter: static fn(string $name): false => false
        );
        $this->assertTrue($cleanerAllowed->filterShowAdminBar(true));
    }

    public function testHandleAdminInitAndHead(): void
    {
        $emptyCleaner = new ProfileDomCleaner(
            envGetter: static fn(string $name): false => false
        );
        $emptyCleaner->handleAdminInit();
        $emptyCleaner->handleAdminHead();

        $removedActions = [];
        $sanitizedFields = [];
        $echoedHtml = [];

        $rule = [
            'targets' => ['color_scheme', 'bio', 'custom_setting'],
            'isAll' => true,
            'superadmins' => false,
            'invertedSuperadmins' => false,
            'roles' => [],
            'invertedRoles' => [],
            'users' => [],
            'invertedUsers' => [],
            'action' => 'hide',
        ];

        $cleaner = new ProfileDomCleaner(
            bufferStarter: static fn(callable $h): bool => true,
            pageNowGetter: static fn(): string => 'profile.php',
            postGuardSanitizer: static function (array $fields) use (&$sanitizedFields): void {
                $sanitizedFields = $fields;
            },
            actionRemover: static function (
                string $tag,
                callable|string $func,
                int $prio
            ) use (&$removedActions): void {
                $removedActions[] = [$tag, $func, $prio];
            },
            htmlEchoer: static function (string $html) use (&$echoedHtml): void {
                $echoedHtml[] = $html;
            },
            envGetter: static fn(string $name): string => 'color_scheme,bio,custom_setting',
            ruleParser: static fn(string $raw): array => [$rule],
            userMatcher: static fn(array $r, ?object $u): bool => true
        );

        $cleaner->handleAdminInit();
        $this->assertSame([['admin_color_scheme_picker', 'admin_color_scheme_picker', 10]], $removedActions);
        $this->assertSame(['color_scheme', 'bio', 'custom_setting'], $sanitizedFields);

        $cleaner->handleAdminHead();
        $this->assertCount(1, $echoedHtml);
        $this->assertStringContainsString('wp-admin-control-clean-profile-sections', $echoedHtml[0]);
        $this->assertStringContainsString('user-description-wrap', $echoedHtml[0]);
        $this->assertStringContainsString('tr.user-custom_setting-wrap', $echoedHtml[0]);
    }

    #[DependsExternal(WpCoreCleanupTest::class, 'testGlobalWordPressFunctionFallbacks')]
    public function testDefaultFallbacksAndGlobalStubs(): void
    {
        ensure_admin_control_stubs();

        $backupPagenow = $GLOBALS['pagenow'] ?? null;
        $backupCurrentUser = $GLOBALS['test_current_user'] ?? null;

        try {
            $GLOBALS['pagenow'] = 'profile.php';
            $GLOBALS['test_current_user'] = (object) ['ID' => 5, 'roles' => ['editor']];

            $rule = [
                'targets' => ['color_scheme', 'bio'],
                'isAll' => true,
                'superadmins' => false,
                'invertedSuperadmins' => false,
                'roles' => [],
                'invertedRoles' => [],
                'users' => [],
                'invertedUsers' => [],
                'action' => 'hide',
            ];

            $cleaner = new ProfileDomCleaner(
                bufferStarter: static fn(callable $h): bool => true,
                envGetter: static fn(string $name): string => 'color_scheme,bio',
                ruleParser: static fn(string $raw): array => [$rule],
                userMatcher: static fn(array $r, ?object $u): bool => true
            );

            $this->assertSame(['color_scheme', 'bio'], $cleaner->getProfileFieldsToRemoveForUser());
            $cleaner->handleAdminInit();

            \ob_start();
            $cleaner->handleAdminHead();
            $output = \ob_get_clean();

            $this->assertStringContainsString('wp-admin-control-clean-profile-sections', $output);
        } finally {
            $GLOBALS['pagenow'] = $backupPagenow;
            $GLOBALS['test_current_user'] = $backupCurrentUser;
        }
    }
}
