<?php

/**
 * Plugin Name: WP Telemetry Blocker
 * Description: Intercepts outbound browser telemetry requests with a synthetic 200 OK response.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-telemetry-blocker/WpTelemetryBlocker.php';

use WordPressDocker\TelemetryBlocker\WpTelemetryBlocker;

if (\function_exists('add_action')) {
    (new WpTelemetryBlocker())->registerHooks();
}
