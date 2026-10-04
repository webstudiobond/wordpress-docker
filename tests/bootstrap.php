<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../build/FilesystemInterface.php';
require_once __DIR__ . '/../build/NativeFilesystem.php';
require_once __DIR__ . '/../build/WordPressSync.php';
spl_autoload_register(static function (string $class): void {
    if ($class === 'WpNotifyMailer') {
        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-notify.php.example';
    }
    if ($class === 'WpPerformance') {
        require_once __DIR__ . '/../examples/data/wp-content/mu-plugins/wp-performance.php.example';
    }
});
