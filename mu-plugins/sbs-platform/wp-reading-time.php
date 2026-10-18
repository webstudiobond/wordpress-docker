<?php

/**
 * Plugin Name: WP Reading Time
 * Description: Lightweight [reading_time] shortcode with Unicode word counting and HTML/SVG markup.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-reading-time/WpReadingTime.php';

use WordPressDocker\ReadingTime\WpReadingTime;

if (\defined('ABSPATH')) {
    (new WpReadingTime())->register();
}
