<?php

/**
 * Plugin Name: WP Admin Control
 * Description: Hardens WordPress admin dashboard via role-based access control and Docker secrets.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-admin-control/Autoloader.php';

\WordPressDocker\AdminControl\Autoloader::register();

use WordPressDocker\AdminControl\Plugin;

if (\function_exists('add_action') || \function_exists('add_filter')) {
    (new Plugin())->registerHooks();
}
