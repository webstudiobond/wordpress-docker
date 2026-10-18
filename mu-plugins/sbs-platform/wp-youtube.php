<?php

/**
 * Plugin Name: WP YouTube Tools
 * Description: Provides cached YouTube Data API v3 channel statistics shortcodes and oEmbed player parameter cleanup.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-youtube/WpYoutube.php';

use WordPressDocker\Youtube\WpYoutube;

if (\function_exists('add_filter')) {
    (new WpYoutube())->registerHooks();
}
