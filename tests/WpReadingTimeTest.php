<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WpReadingTime;

final class WpReadingTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['test_current_post_id'],
            $GLOBALS['test_post_fields'],
            $GLOBALS['test_registered_shortcodes'],
            $GLOBALS['post'],
            $GLOBALS['post_ID']
        );
        parent::tearDown();
    }

    public function testCountWordsHandlesEmptyHtmlScriptsAndUnicode(): void
    {
        $plugin = new WpReadingTime();

        $this->assertSame(0, $plugin->countWords(''));
        $this->assertSame(0, $plugin->countWords('   '));
        $this->assertSame(
            2,
            $plugin->countWords('<p>Hello</p><p>world</p><script>const x = 10;</script><style>.a{color:red}</style>')
        );
        $this->assertSame(
            5,
            $plugin->countWords('<div>Перше слово</div><div>друге слово-дефіс</div> &mdash; д’Артаньян')
        );
    }

    #[Depends('testFallbacksWhenWordPressFunctionsAreNotDefined')]
    public function testCountWordsStripsShortcodesWhenFunctionExists(): void
    {
        ensure_reading_time_stubs();
        $plugin = new WpReadingTime();

        $this->assertSame(
            3,
            $plugin->countWords('<p>One two [gallery ids="1,2,3"] three</p>')
        );
    }

    public function testCalculateMinutesRoundsUpAndEnforcesMinimumOfOne(): void
    {
        $plugin = new WpReadingTime();

        $this->assertSame(1, $plugin->calculateMinutes(''));
        $this->assertSame(1, $plugin->calculateMinutes('One two three', 200));
        $this->assertSame(1, $plugin->calculateMinutes(str_repeat('word ', 200), 200));
        $this->assertSame(2, $plugin->calculateMinutes(str_repeat('word ', 201), 200));
        $this->assertSame(1, $plugin->calculateMinutes('word', 0));
    }

    public function testSelectPluralFormSupportsSingleTwoAndThreeForms(): void
    {
        $plugin = new WpReadingTime();

        $this->assertSame('min', $plugin->selectPluralForm(1, ['min']));
        $this->assertSame('min', $plugin->selectPluralForm(5, ['min']));

        $this->assertSame('minute', $plugin->selectPluralForm(1, ['minute', 'minutes']));
        $this->assertSame('minutes', $plugin->selectPluralForm(2, ['minute', 'minutes']));
        $this->assertSame('minutes', $plugin->selectPluralForm(21, ['minute', 'minutes']));

        $slavic = ['хвилина', 'хвилини', 'хвилин'];
        $this->assertSame('хвилина', $plugin->selectPluralForm(1, $slavic));
        $this->assertSame('хвилини', $plugin->selectPluralForm(2, $slavic));
        $this->assertSame('хвилини', $plugin->selectPluralForm(4, $slavic));
        $this->assertSame('хвилин', $plugin->selectPluralForm(5, $slavic));
        $this->assertSame('хвилин', $plugin->selectPluralForm(11, $slavic));
        $this->assertSame('хвилин', $plugin->selectPluralForm(14, $slavic));
        $this->assertSame('хвилина', $plugin->selectPluralForm(21, $slavic));
        $this->assertSame('хвилини', $plugin->selectPluralForm(23, $slavic));
        $this->assertSame('хвилин', $plugin->selectPluralForm(25, $slavic));
    }

    public function testRenderShortcodePlainTextAndPluralizationOverrides(): void
    {
        $plugin = new WpReadingTime(
            postContentGetter: static fn(int $id): string => $id === 10
                ? str_repeat('слово ', 450)
                : '',
            postIdResolver: static fn(): int => 10
        );

        $this->assertSame('~ 3 min', $plugin->renderShortcode([]));
        $this->assertSame('~ 3 min', $plugin->renderShortcode(''));
        $this->assertSame('3 min', $plugin->renderShortcode(['approx' => 'false']));
        $this->assertSame('~ 3 min', $plugin->renderShortcode(['approx' => '   ']));
        $this->assertSame('~ 3 min', $plugin->renderShortcode(['approx' => 'invalid']));
        $this->assertSame('~ 5 мин.', $plugin->renderShortcode(['wpm' => 100, 'label' => 'мин.']));
        $this->assertSame('Час читання: ~ 3 min', $plugin->renderShortcode(['prefix' => 'Час читання:']));
        $this->assertSame('~ 3 min', $plugin->renderShortcode(['label' => ' | | ']));
        $this->assertSame(
            '~ 3 хвилини',
            $plugin->renderShortcode(['min1' => 'хвилина', 'min2' => 'хвилини', 'min3' => 'хвилин'])
        );
        $this->assertSame(
            '~ 3 minutes',
            $plugin->renderShortcode(['min1' => 'minute', 'min2' => 'minutes'])
        );
        $this->assertSame(
            '~ 3 мин',
            $plugin->renderShortcode(['min1' => 'мин'])
        );
        $this->assertSame(
            '~ 3 минуты',
            $plugin->renderShortcode(['label' => 'минута|минуты|минут'])
        );
        $this->assertSame('', $plugin->renderShortcode(['id' => 999]));
    }

    public function testRenderShortcodeHtmlIconAndClassMarkup(): void
    {
        $plugin = new WpReadingTime(
            postContentGetter: static fn(): string => str_repeat('word ', 120),
            postIdResolver: static fn(): int => 10
        );

        $this->assertSame(
            '<span class="reading-time"><span class="reading-time__value">~ 1 min</span></span>'
            . '<style>:where(.reading-time){'
            . 'display:inline-flex;flex-direction:row;align-items:center;gap:.35em}</style>',
            $plugin->renderShortcode(['html' => 'true'])
        );

        $this->assertSame(
            '<span class="reading-time meta-rt">'
            . '<span class="reading-time__prefix">Час читання:</span> '
            . '<span class="reading-time__value">~ 1 хвилина</span>'
            . '</span>',
            $plugin->renderShortcode([
                'class' => 'meta-rt<>!',
                'prefix' => 'Час читання:',
                'min1' => 'хвилина',
                'min2' => 'хвилини',
                'min3' => 'хвилин',
            ])
        );

        $withIcon = $plugin->renderShortcode([
            'icon' => '1',
            'prefix' => 'Read time:',
            'min1' => 'minute',
            'min2' => 'minutes',
        ]);

        $this->assertStringStartsWith(
            '<span class="reading-time"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em"'
            . ' viewBox="0 0 24 24" class="icon reading-time__icon" aria-hidden="true">',
            $withIcon
        );
        $this->assertStringEndsWith(
            '<span class="reading-time__prefix">Read time:</span> '
            . '<span class="reading-time__value">~ 1 minute</span></span>'
            . '<style>:where(.reading-time__icon){width:1em;height:1em;flex-shrink:0}</style>',
            $withIcon
        );

        $freshPlugin = new WpReadingTime(
            postContentGetter: static fn(): string => str_repeat('word ', 120),
            postIdResolver: static fn(): int => 10
        );
        $combinedWithIcon = $freshPlugin->renderShortcode(['icon' => 'true']);
        $this->assertStringEndsWith(
            '<style>:where(.reading-time){display:inline-flex;flex-direction:row;align-items:center;gap:.35em}'
            . ':where(.reading-time__icon){width:1em;height:1em;flex-shrink:0}</style>',
            $combinedWithIcon
        );
    }

    public function testRenderShortcodeReturnsEmptyWhenPostIdCannotBeResolved(): void
    {
        $plugin = new WpReadingTime(
            postContentGetter: static fn(): string => 'Some text',
            postIdResolver: static fn(): int => 0
        );

        $this->assertSame('', $plugin->renderShortcode([]));
    }

    public function testRegisterAddsReadingTimeShortcodeViaInjectedCallback(): void
    {
        $registered = [];
        $plugin = new WpReadingTime(
            shortcodeAdder: static function (string $tag, callable $cb) use (&$registered): void {
                $registered[$tag] = $cb;
            }
        );

        $plugin->register();

        $this->assertArrayHasKey('reading_time', $registered);
    }

    public function testFallbacksWhenWordPressFunctionsAreNotDefined(): void
    {
        $plugin = new WpReadingTime();
        $plugin->register();

        $this->assertSame('', $plugin->renderShortcode([]));
        $this->assertSame('', $plugin->renderShortcode(['id' => 5]));
    }

    #[Depends('testFallbacksWhenWordPressFunctionsAreNotDefined')]
    public function testWordPressGlobalAndFunctionFallbacks(): void
    {
        ensure_reading_time_stubs();
        $GLOBALS['test_registered_shortcodes'] = [];
        $GLOBALS['test_post_fields'][42]['post_content'] = str_repeat('word ', 250);
        $GLOBALS['test_post_fields'][55]['post_content'] = str_repeat('word ', 100);
        $GLOBALS['test_post_fields'][77]['post_content'] = str_repeat('word ', 600);

        $plugin = new WpReadingTime();
        $plugin->register();

        $this->assertArrayHasKey('reading_time', $GLOBALS['test_registered_shortcodes']);

        $GLOBALS['test_current_post_id'] = 42;
        $this->assertSame('~ 2 min', $plugin->renderShortcode([]));

        $GLOBALS['test_current_post_id'] = 0;
        $GLOBALS['post'] = (object) ['ID' => 55];
        $this->assertSame('~ 1 min', $plugin->renderShortcode([]));

        unset($GLOBALS['post']);
        $GLOBALS['post_ID'] = 77;
        $this->assertSame('~ 3 min', $plugin->renderShortcode([]));

        $GLOBALS['post_ID'] = 0;
        $this->assertSame('', $plugin->renderShortcode([]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapRegistersShortcodeWhenAbspathDefined(): void
    {
        ensure_reading_time_stubs();
        $GLOBALS['test_registered_shortcodes'] = [];

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/var/www/html/');
        }

        require __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-reading-time.php.example';

        $this->assertArrayHasKey('reading_time', $GLOBALS['test_registered_shortcodes']);
    }
}
