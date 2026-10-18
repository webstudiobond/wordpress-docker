<?php

/**
 * Plugin Name: WP ACF Editor Control
 * Description: Synchronizes ACF Hide Content Editor rules with the Block Editor using cached options.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-acf-editor-control/WpAcfEditorControl.php';

use WordPressDocker\AcfEditorControl\WpAcfEditorControl;

if (\function_exists('add_action') || \function_exists('add_filter')) {
    (new WpAcfEditorControl())->registerHooks();
}
