<?php

/**
 * Plugin Name: WP Translation Updates Disabler
 * Description: Controls background translation auto-updates and disables translation updates for configured slugs.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-translation-updates-disabler/WpTranslationUpdatesDisabler.php';

use WordPressDocker\TranslationUpdates\WpTranslationUpdatesDisabler;

if (\function_exists('add_filter')) {
    (new WpTranslationUpdatesDisabler())->registerHooks();
}
