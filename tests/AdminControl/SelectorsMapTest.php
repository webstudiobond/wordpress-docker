<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Config\SelectorsMap;

final class SelectorsMapTest extends TestCase
{
    public function testGetProfileFieldSelectorsReturnsFullMap(): void
    {
        $map = SelectorsMap::getProfileFieldSelectors();

        $this->assertSame(SelectorsMap::PROFILE_FIELD_SELECTORS_MAP, $map);
        $this->assertArrayHasKey('syntax_highlighting', $map);
        $this->assertArrayHasKey('color_scheme', $map);
        $this->assertArrayHasKey('user_login', $map);
        $this->assertArrayHasKey('wordfence', $map);
        $this->assertArrayHasKey('yoast', $map);
    }

    public function testGetProfileFieldSelectorReturnsCorrectSelector(): void
    {
        $selector = SelectorsMap::getProfileFieldSelector('syntax_highlighting');

        $this->assertSame('tr.user-syntax-highlighting-wrap', $selector);
    }

    public function testGetProfileFieldSelectorReturnsNullForUnknownField(): void
    {
        $selector = SelectorsMap::getProfileFieldSelector('unknown_profile_field');

        $this->assertNull($selector);
    }

    public function testGetProfileFieldSelectorHandlesAliases(): void
    {
        $this->assertSame(
            SelectorsMap::getProfileFieldSelector('user_login'),
            SelectorsMap::getProfileFieldSelector('username')
        );
        $this->assertSame(
            SelectorsMap::getProfileFieldSelector('color_scheme'),
            SelectorsMap::getProfileFieldSelector('admin_color')
        );
    }
}
