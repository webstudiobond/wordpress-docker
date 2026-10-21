<?php

/**
 * Pre-execution runtime bootstrap loader designed to bridge external WAF implementations
 * such as Wordfence before WordPress core initialization, eliminating the requirement
 * for mutable per-directory .user.ini configuration files.
 */

declare(strict_types=1);

(static function (): void {
    $wafPath = '/var/www/html/wordfence-waf.php';

    if (\PHP_SAPI === 'cli' && defined('SBS_TEST_SUITE') && \getenv('SBS_TEST_MODE', true) === '1') {
        $testPath = \getenv('SBS_TEST_WORDFENCE_WAF_PATH', true);
        if ($testPath !== false && \trim($testPath) !== '' && \str_ends_with(\trim($testPath), 'wordfence-waf.php')) {
            $wafPath = \trim($testPath);
        }
    }

    if (\is_file($wafPath) && \is_readable($wafPath)) {
        require_once $wafPath;
    }
})();
