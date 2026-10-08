<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpTransliterator;

final class WpTransliteratorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WP_TRANSLIT_TABLE');
        putenv('WP_TRANSLIT_SLUGS');
        putenv('WP_TRANSLIT_FILENAMES');
        putenv('WP_TRANSLIT_OVERRIDES');
        unset(
            $GLOBALS['test_wp_locale'],
            $GLOBALS['test_registered_filters']
        );
        parent::tearDown();
    }

    public function testDefaultConfigurationAndFallbacksBeforeStubsLoaded(): void
    {
        $transliterator = new WpTransliterator();

        $this->assertTrue($transliterator->isSlugsEnabled());
        $this->assertTrue($transliterator->isFilenamesEnabled());
        $this->assertSame('universal', $transliterator->getConfiguredTable());
        $this->assertSame([], $transliterator->getCustomOverrides());

        $this->assertSame('', $transliterator->transliterate(''));
        $this->assertSame('', $transliterator->sanitizeTitle('', '', 'save'));
        $this->assertSame('Привет мир', $transliterator->sanitizeTitle('Привет мир', '', 'query'));
        $this->assertSame('Privet mir', $transliterator->sanitizeTitle('Привет мир', '', 'save'));

        $this->assertSame('', $transliterator->sanitizeFileName(''));
        $this->assertSame('Testovyj fajl.pdf', $transliterator->sanitizeFileName('Тестовый файл.pdf'));

        $macInput = "берЕ\u{0308}зовыИ\u{0306}-белозе\u{0308}рскии\u{0306}-І\u{0308}і\u{0308}-У\u{0306}у\u{0306}.png";
        $this->assertSame(
            'berYozovyJ-belozyorskij-Yii-Uu.png',
            $transliterator->sanitizeFileName($macInput)
        );

        $autoWithoutGetLocale = new WpTransliterator(
            envGetter: static fn(string $name): string => $name === 'WP_TRANSLIT_TABLE' ? 'auto' : ''
        );
        $this->assertSame('universal', $autoWithoutGetLocale->getConfiguredTable());

        $transliterator->registerHooks();
    }

    #[Depends('testDefaultConfigurationAndFallbacksBeforeStubsLoaded')]
    #[DependsExternal(WpAcfEditorControlTest::class, 'testFallbacksWhenGlobalFunctionsUnavailable')]
    #[DependsExternal(WpPerformanceTest::class, 'testFinishFastcgiReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testOnUpgraderProcessCompleteReturnsFalseWhenFunctionDoesNotExist')]
    #[DependsExternal(WpPerformanceTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(WpTelemetryBlockerTest::class, 'testRegisterHooksReturnsEarlyWhenAddActionDoesNotExist')]
    #[DependsExternal(
        WpTranslationUpdatesDisablerTest::class,
        'testRegisterHooksReturnsEarlyWhenAddFilterDoesNotExist'
    )]
    public function testGlobalWordPressFunctionFallbacks(): void
    {
        ensure_wordpress_filter_stubs();
        ensure_transliterator_stubs();

        $GLOBALS['test_registered_filters'] = [];
        $GLOBALS['test_wp_locale'] = 'uk';

        $transliterator = new WpTransliterator(
            envGetter: static fn(string $name): string => $name === 'WP_TRANSLIT_TABLE' ? 'auto' : ''
        );

        $this->assertSame('uk', $transliterator->getConfiguredTable());
        $this->assertSame('Kyiv-Yizhak', $transliterator->sanitizeTitle('Київ-Їжак', '', 'save'));
        $this->assertSame(
            'Kyiv-Zolty-Gruss.webp',
            $transliterator->sanitizeFileName('Київ-Żółty-Gruß.webp')
        );

        $customAccentTransliterator = new WpTransliterator(
            accentRemover: static fn(string $value): string => str_replace('é', 'e', $value)
        );
        $this->assertSame('Kafe-menyu.pdf', $customAccentTransliterator->sanitizeFileName('Кафé-меню.pdf'));

        $transliterator->registerHooks();

        $this->assertArrayHasKey('sanitize_title', $GLOBALS['test_registered_filters']);
        $this->assertSame(9, $GLOBALS['test_registered_filters']['sanitize_title'][0]['priority']);
        $this->assertSame(3, $GLOBALS['test_registered_filters']['sanitize_title'][0]['accepted_args']);

        $this->assertArrayHasKey('sanitize_file_name', $GLOBALS['test_registered_filters']);
        $this->assertSame(10, $GLOBALS['test_registered_filters']['sanitize_file_name'][0]['priority']);
        $this->assertSame(1, $GLOBALS['test_registered_filters']['sanitize_file_name'][0]['accepted_args']);
    }

    public function testAllSupportedTablesAndLocaleAliases(): void
    {
        $cases = [
            [
                'table' => 'ISO9',
                'input' => 'Щука Цапля Хлеб Ъ Ь Історія',
                'expected' => 'SHHuka CZaplya Xleb   Istoriya',
            ],
            [
                'table' => 'universal',
                'input' => 'Щука Ціна Хліб Київ Україна Ґанок Єнот Їжак '
                    . 'Ўладзімір Ђорђе Әлем Θεσσαλονίκη ՈՒրախ თბილისი שלום',
                'expected' => 'Shchuka Tsina Khlib Kyiv Ukraina Ganok Yenot Yizhak '
                    . 'Uladzimir Djordje Aelem THessalonike Urax thbilisi shlw',
            ],
            [
                'table' => 'multi',
                'input' => 'Київ Україна Ґанок Єнот Їжак',
                'expected' => 'Kyiv Ukraina Ganok Yenot Yizhak',
            ],
            [
                'table' => 'all',
                'input' => 'Київ Україна Ґанок Єнот Їжак',
                'expected' => 'Kyiv Ukraina Ganok Yenot Yizhak',
            ],
            [
                'table' => 'uk',
                'input' => "Київський ґанок, Україна, Згорани, Гадяч, Харків, Чернівці, Їжак, Єнот, б'є щуку",
                'expected' => 'Kyivskyi ganok, Ukraina, Zghorany, Hadiach, '
                    . 'Kharkiv, Chernivtsi, Yizhak, Yenot, bie shchuku',
            ],
            ['table' => 'bel', 'input' => 'Ўладзімір', 'expected' => 'Uladzimir'],
            ['table' => 'bg_BG', 'input' => 'Щъркел Ъгъл Ѫ', 'expected' => 'STHarkel Agal O'],
            ['table' => 'mk_MK', 'input' => 'Ѓорѓи Ѕвезда Џем', 'expected' => 'Gorgi Zvezda DHem'],
            ['table' => 'sr_RS', 'input' => 'Ђорђе Љубав Његош Џеп', 'expected' => 'Djordje Ljubav Njegos Dzep'],
            ['table' => 'kk', 'input' => 'Әлем Ғарыш Қазақ Ң Өмір', 'expected' => 'Aelem Gharysh Qazaq Ng Oemir'],
            ['table' => 'el', 'input' => 'Θεσσαλονίκη Ψυχή Φως', 'expected' => 'THessalonike PSukhe PHos'],
            ['table' => 'hy', 'input' => 'ՈՒրախ Ժամ և', 'expected' => 'Urax ZHam ew'],
            ['table' => 'ka_GE', 'input' => 'თბილისი ჟურნალი', 'expected' => 'thbilisi zhurnali'],
            ['table' => 'he_IL', 'input' => 'שלום תל אביב', 'expected' => 'shlw thl byb'],
            ['table' => 'unknown_table', 'input' => 'Привет', 'expected' => 'Privet'],
        ];

        foreach ($cases as $case) {
            $transliterator = new WpTransliterator(
                envGetter: static fn(string $name): string => $name === 'WP_TRANSLIT_TABLE' ? $case['table'] : ''
            );

            $this->assertSame($case['expected'], $transliterator->transliterate($case['input']));
        }

        $autoTransliterator = new WpTransliterator(
            envGetter: static fn(string $name): string => $name === 'WP_TRANSLIT_TABLE' ? 'auto' : '',
            localeGetter: static fn(): string => 'uk_UA'
        );
        $this->assertSame('uk', $autoTransliterator->getConfiguredTable());

        $autoFallbackTransliterator = new WpTransliterator(
            envGetter: static fn(string $name): string => $name === 'WP_TRANSLIT_TABLE' ? 'auto' : '',
            localeGetter: static fn(): string => 'fr_FR'
        );
        $this->assertSame('universal', $autoFallbackTransliterator->getConfiguredTable());
    }

    public function testCustomOverridesAndInjectedFilterAdder(): void
    {
        /** @var list<string> $addedFilters */
        $addedFilters = [];

        $transliterator = new WpTransliterator(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_TRANSLIT_TABLE' => 'ISO9',
                'WP_TRANSLIT_OVERRIDES' => ' ц:ts , Ц:TS , invalid_entry , :empty_source , ъ: ',
                default => '',
            },
            filterAdder: static function (string $tag) use (&$addedFilters): void {
                $addedFilters[] = $tag;
            }
        );

        $this->assertSame(
            ['ц' => 'ts', 'Ц' => 'TS', 'ъ' => ''],
            $transliterator->getCustomOverrides()
        );
        $this->assertSame('TSentr-tsvetov', $transliterator->transliterate('Центр-цветов'));

        $ukCustomOverrideTransliterator = new WpTransliterator(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_TRANSLIT_TABLE' => 'uk',
                'WP_TRANSLIT_OVERRIDES' => 'ї:yi',
                default => '',
            }
        );
        $this->assertSame('Kyyiv', $ukCustomOverrideTransliterator->transliterate('Київ'));

        $universalCustomOverrideTransliterator = new WpTransliterator(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_TRANSLIT_TABLE' => 'universal',
                'WP_TRANSLIT_OVERRIDES' => 'ї:yi',
                default => '',
            }
        );
        $this->assertSame('Ukrayina', $universalCustomOverrideTransliterator->transliterate('Україна'));

        $transliterator->registerHooks();
        $this->assertSame(['sanitize_title', 'sanitize_file_name'], $addedFilters);

        /** @var list<string> $disabledFilters */
        $disabledFilters = [];
        $disabledTransliterator = new WpTransliterator(
            envGetter: static fn(string $name): string => match ($name) {
                'WP_TRANSLIT_SLUGS' => 'false',
                'WP_TRANSLIT_FILENAMES' => '0',
                default => '',
            },
            filterAdder: static function (string $tag) use (&$disabledFilters): void {
                $disabledFilters[] = $tag;
            }
        );

        $this->assertFalse($disabledTransliterator->isSlugsEnabled());
        $this->assertFalse($disabledTransliterator->isFilenamesEnabled());
        $this->assertSame('Привет', $disabledTransliterator->sanitizeTitle('Привет', '', 'save'));
        $this->assertSame('Файл.png', $disabledTransliterator->sanitizeFileName('Файл.png'));

        $disabledTransliterator->registerHooks();
        $this->assertSame([], $disabledFilters);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFileLevelBootstrapRegistersHooksWhenAddFilterExists(): void
    {
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_filters'] = [];

        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-transliterator.php.example';

        $this->assertArrayHasKey('sanitize_title', $GLOBALS['test_registered_filters']);
        $this->assertArrayHasKey('sanitize_file_name', $GLOBALS['test_registered_filters']);
    }
}
