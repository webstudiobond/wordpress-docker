<?php

/**
 * Plugin Name: WP Performance Optimizer
 * Description: Prevents gateway timeouts during core/plugin updates via early FastCGI flush and enforces IPv4 cURL.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-performance/WpPerformance.php';

use WordPressDocker\Performance\WpPerformance;

if (\function_exists('add_action')) {
    (new WpPerformance())->registerHooks();
}
