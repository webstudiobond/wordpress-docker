<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use stdClass;
use WpTranslationUpdatesDisabler;

final class WpTranslationUpdatesDisablerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WP_TRANSLATION_DISABLE_AUTO_UPDATES');
        putenv('WP_TRANSLATION_BLOCKED_SLUGS');
        parent::tearDown();
    }

    public function testDefaultStateAllowsAutoUpdatesAndBlocksNoSlugs(): void
    {
        $disabler = new WpTranslationUpdatesDisabler();

        $this->assertFalse($disabler->isAutoUpdateDisabled());
        $this->assertSame([], $disabler->getBlockedSlugs());
        $this->assertFalse($disabler->isSlugBlocked('plugin-alpha'));
        $this->assertFalse($disabler->isSlugBlocked(''));
    }

    public function testAutoUpdateDisabledViaEnvironmentVariable(): void
    {
        $enabledValues = ['true', '1', 'on', 'yes', 'TRUE', '  yes  '];

        foreach ($enabledValues as $val) {
            $disabler = new WpTranslationUpdatesDisabler(
                envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_DISABLE_AUTO_UPDATES' ? $val : ''
            );
            $this->assertTrue($disabler->isAutoUpdateDisabled());
            $this->assertFalse($disabler->filterAutoUpdate(true, (object) ['slug' => 'plugin-alpha']));
        }
    }

    public function testAutoUpdateAllowedWhenEnvironmentVariableFalseOrEmpty(): void
    {
        $disabledValues = ['false', '0', 'off', 'no', 'FALSE', '', '   '];

        foreach ($disabledValues as $val) {
            $disabler = new WpTranslationUpdatesDisabler(
                envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_DISABLE_AUTO_UPDATES' ? $val : ''
            );
            $this->assertFalse($disabler->isAutoUpdateDisabled());
            $this->assertTrue($disabler->filterAutoUpdate(true, (object) ['slug' => 'plugin-alpha']));
        }
    }

    public function testParsesBlockedSlugsFromEnvironmentVariable(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_BLOCKED_SLUGS'
                ? ' plugin-alpha , plugin-beta , , plugin-alpha '
                : ''
        );

        $this->assertSame(['plugin-alpha', 'plugin-beta'], $disabler->getBlockedSlugs());
        $this->assertTrue($disabler->isSlugBlocked('plugin-alpha'));
        $this->assertTrue($disabler->isSlugBlocked('  plugin-beta  '));
        $this->assertFalse($disabler->isSlugBlocked('plugin-gamma'));
    }

    public function testConstructorExplicitSlugsOverrideEnvironment(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => 'plugin-alpha',
            blockedSlugs: ['theme-delta', '  plugin-epsilon  ']
        );

        $this->assertSame(['theme-delta', 'plugin-epsilon'], $disabler->getBlockedSlugs());
        $this->assertTrue($disabler->isSlugBlocked('theme-delta'));
        $this->assertFalse($disabler->isSlugBlocked('plugin-alpha'));
    }

    public function testWildcardBlocksAllNonEmptySlugs(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_BLOCKED_SLUGS' ? '*' : ''
        );

        $this->assertTrue($disabler->isSlugBlocked('plugin-alpha'));
        $this->assertTrue($disabler->isSlugBlocked('default'));
        $this->assertFalse($disabler->isSlugBlocked('   '));
    }

    public function testFilterAutoUpdatePreservesValueWhenNoSlugsBlocked(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => '   '
        );

        $item = (object) ['slug' => 'plugin-alpha'];
        $this->assertTrue($disabler->filterAutoUpdate(true, $item));
        $this->assertFalse($disabler->filterAutoUpdate(false, $item));
    }

    public function testFilterAutoUpdateBlocksAllWhenWildcardConfigured(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_BLOCKED_SLUGS' ? '*' : ''
        );

        $item = (object) ['slug' => 'plugin-alpha'];
        $this->assertFalse($disabler->filterAutoUpdate(true, $item));
        $this->assertFalse($disabler->filterAutoUpdate(true, null));
    }

    public function testFilterAutoUpdateSelectivelyBlocksObjectAndArrayItems(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_BLOCKED_SLUGS'
                ? 'plugin-alpha,theme-beta'
                : ''
        );

        $blockedObject = (object) ['slug' => 'plugin-alpha'];
        $blockedArray = ['slug' => 'theme-beta'];
        $allowedObject = (object) ['slug' => 'plugin-gamma'];
        $invalidItem = (object) ['slug' => 123];

        $this->assertFalse($disabler->filterAutoUpdate(true, $blockedObject));
        $this->assertFalse($disabler->filterAutoUpdate(true, $blockedArray));
        $this->assertTrue($disabler->filterAutoUpdate(true, $allowedObject));
        $this->assertFalse($disabler->filterAutoUpdate(false, $allowedObject));
        $this->assertTrue($disabler->filterAutoUpdate(true, $invalidItem));
        $this->assertTrue($disabler->filterAutoUpdate(true, 'invalid-scalar'));
    }

    public function testFilterTransientReturnsUnmodifiedWhenNoSlugsBlocked(): void
    {
        $disabler = new WpTranslationUpdatesDisabler();
        $transient = new stdClass();
        $transient->translations = [
            ['slug' => 'plugin-alpha'],
        ];

        $result = $disabler->filterTransient($transient);
        $this->assertSame($transient, $result);
        $this->assertCount(1, $transient->translations);
    }

    public function testFilterTransientHandlesInvalidTransientStructures(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_BLOCKED_SLUGS' ? 'plugin-alpha' : ''
        );

        $this->assertFalse($disabler->filterTransient(false));
        $this->assertNull($disabler->filterTransient(null));

        $withoutTranslations = new stdClass();
        $this->assertSame($withoutTranslations, $disabler->filterTransient($withoutTranslations));

        $nonArrayTranslations = new stdClass();
        $nonArrayTranslations->translations = 'invalid';
        $this->assertSame($nonArrayTranslations, $disabler->filterTransient($nonArrayTranslations));
    }

    public function testFilterTransientClearsAllWhenWildcardConfigured(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_BLOCKED_SLUGS' ? '*' : ''
        );

        $transient = new stdClass();
        $transient->translations = [
            ['slug' => 'plugin-alpha'],
            (object) ['slug' => 'plugin-beta'],
        ];

        $result = $disabler->filterTransient($transient);
        $this->assertSame($transient, $result);
        $this->assertSame([], $transient->translations);
    }

    public function testFilterTransientRemovesOnlyBlockedSlugsAndReindexes(): void
    {
        $disabler = new WpTranslationUpdatesDisabler(
            envGetter: fn(string $name): string => $name === 'WP_TRANSLATION_BLOCKED_SLUGS'
                ? 'plugin-alpha, theme-beta'
                : ''
        );

        $allowedItem = (object) ['slug' => 'plugin-gamma'];
        $missingSlugItem = ['type' => 'plugin', 'slug' => '   '];

        $transient = new stdClass();
        $transient->translations = [
            0 => ['slug' => 'plugin-alpha'],
            1 => $allowedItem,
            2 => (object) ['slug' => 'theme-beta'],
            3 => $missingSlugItem,
        ];

        $result = $disabler->filterTransient($transient);
        $this->assertSame($transient, $result);
        $this->assertSame([$allowedItem, $missingSlugItem], $transient->translations);
    }

    public function testRegisterHooksReturnsEarlyWhenAddFilterDoesNotExist(): void
    {
        $disabler = new WpTranslationUpdatesDisabler();
        $disabler->registerHooks();

        $this->assertArrayNotHasKey('test_registered_filters', $GLOBALS);
    }

    public function testRegisterHooksWithInjectedRegistrar(): void
    {
        /** @var list<array{string, callable, int, int}> $registered */
        $registered = [];

        $registrar = function (
            string $tag,
            callable $callback,
            int $priority,
            int $acceptedArgs
        ) use (&$registered): void {
            $registered[] = [$tag, $callback, $priority, $acceptedArgs];
        };

        $disabler = new WpTranslationUpdatesDisabler();
        $disabler->registerHooks($registrar);

        $this->assertCount(5, $registered);
        $this->assertSame('auto_update_translation', $registered[0][0]);
        $this->assertSame(10, $registered[0][2]);
        $this->assertSame(2, $registered[0][3]);

        $this->assertSame('async_update_translation', $registered[1][0]);
        $this->assertSame(10, $registered[1][2]);
        $this->assertSame(2, $registered[1][3]);

        $this->assertSame('site_transient_update_plugins', $registered[2][0]);
        $this->assertSame('site_transient_update_themes', $registered[3][0]);
        $this->assertSame('site_transient_update_core', $registered[4][0]);
    }

    #[Depends('testRegisterHooksReturnsEarlyWhenAddFilterDoesNotExist')]
    public function testRegisterHooksWithoutRegistrarUsesGlobalAddFilter(): void
    {
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_filters'] = [];

        $disabler = new WpTranslationUpdatesDisabler();
        $disabler->registerHooks();

        $this->assertArrayHasKey('auto_update_translation', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('async_update_translation', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('site_transient_update_plugins', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('site_transient_update_themes', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('site_transient_update_core', $GLOBALS['test_registered_filters']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenAddFilterExists(): void
    {
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_filters'] = [];
        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-translation-updates-disabler.php.example';

        $this->assertArrayHasKey('auto_update_translation', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('async_update_translation', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('site_transient_update_plugins', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('site_transient_update_themes', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('site_transient_update_core', $GLOBALS['test_registered_filters']);
    }
}
