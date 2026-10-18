<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs.php';
spl_autoload_register(static function (string $class): void {
    if ($class === 'WP_Error') {
        require_once __DIR__ . '/../stubs/WP_Error.php';
    }
    if ($class === 'WP_Admin_Bar_Stub' || $class === 'WP_Admin_Bar_Remove_Only_Stub') {
        require_once __DIR__ . '/../stubs/WP_Admin_Bar_Stub.php';
    }
    if ($class === 'WP_REST_Request_Stub' || $class === 'WP_Query_Stub') {
        require_once __DIR__ . '/../stubs/WP_Core_Classes_Stub.php';
    }
    if ($class === 'WordPressDocker\\Notify\\WpNotifyMailer' || $class === 'WpNotifyMailer') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-notify.php';
    }
    if ($class === 'WordPressDocker\\Performance\\WpPerformance' || $class === 'WpPerformance') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-performance/WpPerformance.php';
    }
    if (
        $class === 'WordPressDocker\\TranslationUpdates\\WpTranslationUpdatesDisabler'
        || $class === 'WpTranslationUpdatesDisabler'
    ) {
        require_once __DIR__
            . '/../mu-plugins/sbs-platform/wp-translation-updates-disabler/WpTranslationUpdatesDisabler.php';
    }
    if ($class === 'WordPressDocker\\AcfEditorControl\\WpAcfEditorControl' || $class === 'WpAcfEditorControl') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-acf-editor-control/WpAcfEditorControl.php';
    }
    if ($class === 'WordPressDocker\\TelemetryBlocker\\WpTelemetryBlocker' || $class === 'WpTelemetryBlocker') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-telemetry-blocker/WpTelemetryBlocker.php';
    }
    if ($class === 'WordPressDocker\\PostDuplicator\\WpPostDuplicator' || $class === 'WpPostDuplicator') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-post-duplicator/WpPostDuplicator.php';
    }
    if ($class === 'WordPressDocker\\CoreCleanup\\WpCoreCleanup' || $class === 'WpCoreCleanup') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-core-cleanup/WpCoreCleanup.php';
    }
    if ($class === 'WordPressDocker\\Transliterator\\WpTransliterator' || $class === 'WpTransliterator') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-transliterator/WpTransliterator.php';
    }
    if ($class === 'WordPressDocker\\Youtube\\WpYoutube' || $class === 'WpYoutube') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-youtube/WpYoutube.php';
    }
    if ($class === 'WordPressDocker\\ReadingTime\\WpReadingTime' || $class === 'WpReadingTime') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-reading-time/WpReadingTime.php';
    }
    if ($class === 'WordPressDocker\\Snippets\\WpSnippets' || $class === 'WpSnippets') {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-snippets/WpSnippets.php';
    }
    if (str_starts_with($class, 'WordPressDocker\\AdminControl\\')) {
        require_once __DIR__ . '/../mu-plugins/sbs-platform/wp-admin-control/Autoloader.php';
        \WordPressDocker\AdminControl\Autoloader::register();
    }
});
