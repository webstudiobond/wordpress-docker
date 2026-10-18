<?php

/**
 * Plugin Name: WP Snippets & Asset Control
 * Description: Conditional GTM injection, asset preloading, deregistration, and safe file snippets.
 * Version: 1.0.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-snippets/WpSnippets.php';

use WordPressDocker\Snippets\WpSnippets;

if (\defined('ABSPATH')) {
    (new WpSnippets())->register();
}
