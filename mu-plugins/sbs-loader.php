<?php

/**
 * Plugin Name: SBS Stack Platform
 * Description: Dynamically loads configured immutable SBS platform MU-plugins from the read-only container layer.
 * Version: 1.1.0
 * Author: underhax
 */

declare(strict_types=1);

(static function (): void {
    $rawEnv = \getenv('WP_ENABLED_MU_PLUGINS', true);
    if ($rawEnv !== false && \trim($rawEnv) === 'none') {
        return;
    }

    if ($rawEnv === false || \trim($rawEnv) === '') {
        $enabledSlugs = ['notify'];
    } else {
        $tokens = \explode(',', $rawEnv);
        $enabledSlugs = [];
        foreach ($tokens as $token) {
            $trimmed = \strtolower(\trim($token));
            if (
                $trimmed !== ''
                && $trimmed !== 'all'
                && $trimmed !== 'none'
                && \preg_match('/^[a-z-]+$/', $trimmed) === 1
            ) {
                $enabledSlugs[] = $trimmed;
            }
        }
        $enabledSlugs = \array_values(\array_unique($enabledSlugs));
    }

    if ($enabledSlugs === []) {
        return;
    }

    $platformDir = \getenv('SBS_PLATFORM_MU_DIR', true);
    $dir = ($platformDir === false || \trim($platformDir) === '')
        ? '/usr/local/share/wordpress/mu-plugins'
        : \trim($platformDir);

    if (!\is_dir($dir)) {
        return;
    }

    foreach ($enabledSlugs as $slug) {
        $filePath = $dir . '/wp-' . $slug . '.php';
        if (\is_file($filePath)) {
            require_once $filePath;
        }
    }
})();
