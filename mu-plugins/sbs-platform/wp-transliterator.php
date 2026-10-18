<?php

/**
 * Plugin Name: WP Transliterator
 * Description: Transliterates non-Latin post, page, and term slugs on save and uploaded media filenames.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-transliterator/WpTransliterator.php';

use WordPressDocker\Transliterator\WpTransliterator;

if (\function_exists('add_filter')) {
    (new WpTransliterator())->registerHooks();
}
