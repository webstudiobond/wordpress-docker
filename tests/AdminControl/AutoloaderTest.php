<?php

declare(strict_types=1);

namespace WordPressDocker\Tests\AdminControl;

use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Autoloader;

final class AutoloaderTest extends TestCase
{
    public function testRegisterIsIdempotent(): void
    {
        Autoloader::register();
        Autoloader::register();

        $this->assertTrue(\class_exists(Autoloader::class));
    }

    public function testAutoloadLoadsExistingClass(): void
    {
        Autoloader::register();

        $this->assertTrue(\class_exists(\WordPressDocker\AdminControl\Config\SelectorsMap::class));
        $this->assertTrue(\class_exists(\WordPressDocker\AdminControl\Security\RuleMatcher::class));
    }

    public function testAutoloadIgnoresNonMatchingNamespace(): void
    {
        Autoloader::register();

        $this->assertFalse(\class_exists('SomeOtherVendor\\NonExistentClass'));
    }

    public function testAutoloadHandlesMissingClassGracefully(): void
    {
        Autoloader::register();

        $this->assertFalse(\class_exists('WordPressDocker\\AdminControl\\NonExistentClass'));
    }
}
