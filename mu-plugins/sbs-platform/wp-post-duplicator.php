<?php

/**
 * Plugin Name: WP Post Duplicator
 * Description: Clones posts, pages, and custom post types with taxonomy terms and metadata.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-post-duplicator/WpPostDuplicator.php';

use WordPressDocker\PostDuplicator\WpPostDuplicator;

if (\function_exists('add_action') || \function_exists('add_filter')) {
    (new WpPostDuplicator())->registerHooks();
}
