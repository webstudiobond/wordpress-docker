<?php

declare(strict_types=1);

require_once __DIR__ . '/FilesystemInterface.php';
require_once __DIR__ . '/NativeFilesystem.php';
require_once __DIR__ . '/WordPressSync.php';

use WordPressDocker\WordPressSync;

$syncer = new WordPressSync();
exit($syncer->run());
