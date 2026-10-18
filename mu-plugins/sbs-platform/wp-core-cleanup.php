<?php

/**
 * Plugin Name: WP Core Cleanup
 * Description: Removes redundant WordPress head metadata, HTTP headers, emojis, self-pings, and comment spam vectors.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-core-cleanup/WpCoreCleanup.php';

use WordPressDocker\CoreCleanup\WpCoreCleanup;

if (\function_exists('add_action') || \function_exists('add_filter')) {
    (new WpCoreCleanup())->registerHooks();
}
