<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPressDocker\AdminControl\Plugin;

final class WpAdminControlTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEntrypointLoadsAutoloaderAndRegistersHooks(): void
    {
        ensure_wordpress_stubs();
        ensure_wordpress_filter_stubs();
        $GLOBALS['test_registered_actions'] = [];
        $GLOBALS['test_registered_filters'] = [];

        require dirname(__DIR__) . '/mu-plugins/sbs-platform/wp-admin-control.php';

        $this->assertTrue(class_exists(Plugin::class));
        $this->assertArrayHasKey('admin_init', $GLOBALS['test_registered_actions']);
        $this->assertArrayHasKey('admin_menu', $GLOBALS['test_registered_actions']);
    }
}
