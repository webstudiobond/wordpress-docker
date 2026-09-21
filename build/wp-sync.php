<?php

declare(strict_types=1);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$source = '/usr/src/wordpress';
$target = '/var/www/html';

if (!is_dir($source)) {
    echo "[wp-sync] Source directory {$source} not found. Skipping synchronization.\n";
    exit(0);
}

$deleteRecursive = static function (string $path) use (&$deleteRecursive): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $deleteRecursive($path . '/' . $item);
    }

    rmdir($path);
};

$copyRecursive = static function (string $src, string $dst) use (&$copyRecursive): void {
    if (is_link($src)) {
        return;
    }

    if (is_dir($src)) {
        if (!is_dir($dst)) {
            if (!mkdir($dst, 0755, true) && !is_dir($dst)) {
                throw new RuntimeException("Failed to create directory: {$dst}");
            }
            chmod($dst, 0755);
        }

        $items = scandir($src);
        if ($items === false) {
            throw new RuntimeException("Failed to read directory: {$src}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $copyRecursive($src . '/' . $item, $dst . '/' . $item);
        }
    } elseif (is_file($src)) {
        if (!copy($src, $dst)) {
            throw new RuntimeException("Failed to copy file from {$src} to {$dst}");
        }
        chmod($dst, 0644);
    }
};

$atomicSyncDirectory = static function (
    string $sourceDir,
    string $targetDir
) use (
    $copyRecursive,
    $deleteRecursive
): void {
    if (!is_dir($sourceDir)) {
        return;
    }

    $parentDir = dirname($targetDir);
    $baseName = basename($targetDir);
    $randomSuffix = bin2hex(random_bytes(4));
    $tmpDir = "{$parentDir}/.{$baseName}.tmp_{$randomSuffix}";
    $bakDir = "{$parentDir}/.{$baseName}.bak_{$randomSuffix}";

    try {
        $copyRecursive($sourceDir, $tmpDir);

        if (is_dir($targetDir)) {
            if (!rename($targetDir, $bakDir)) {
                throw new RuntimeException("Failed to move {$targetDir} to backup location {$bakDir}");
            }
        }

        if (!rename($tmpDir, $targetDir)) {
            if (is_dir($bakDir)) {
                rename($bakDir, $targetDir);
            }
            throw new RuntimeException("Failed to activate new {$targetDir} from {$tmpDir}");
        }

        if (is_dir($bakDir)) {
            $deleteRecursive($bakDir);
        }
    } catch (Throwable $e) {
        if (is_dir($tmpDir)) {
            $deleteRecursive($tmpDir);
        }
        throw $e;
    }
};

$getWpVersion = static function (string $versionFile): ?string {
    if (!is_file($versionFile)) {
        return null;
    }

    $content = file_get_contents($versionFile);
    if ($content !== false && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
        return $matches[1];
    }

    return null;
};

$getContentDirName = static function (): string {
    $envName = getenv('WP_CONTENT_DIR');
    if ($envName === false || trim($envName) === '') {
        $envName = getenv('WP_CONTENT_FOLDERNAME');
    }

    if ($envName !== false && trim($envName) !== '') {
        $sanitized = basename(trim($envName, "/\\ \t\n\r\0\x0B"));
        if ($sanitized !== '' && $sanitized !== '.' && $sanitized !== '..') {
            return $sanitized;
        }
    }

    return 'wp-content';
};

$syncCustomContentDirectory = static function (
    string $target,
    string $contentDirName
) use (
    $copyRecursive,
    $deleteRecursive
): void {
    if ($contentDirName === 'wp-content') {
        return;
    }

    $wpContentDir = "{$target}/wp-content";
    $customContentDir = "{$target}/{$contentDirName}";

    if (!is_dir($wpContentDir)) {
        return;
    }

    if (!is_dir($customContentDir)) {
        if (!rename($wpContentDir, $customContentDir)) {
            throw new RuntimeException("Failed to rename {$wpContentDir} to {$customContentDir}");
        }
    } else {
        $contentFiles = scandir($wpContentDir);
        if ($contentFiles !== false) {
            foreach ($contentFiles as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $src = "{$wpContentDir}/{$file}";
                $dst = "{$customContentDir}/{$file}";
                if (!file_exists($dst)) {
                    $copyRecursive($src, $dst);
                }
            }
        }
        $deleteRecursive($wpContentDir);
    }
    echo "[wp-sync] Initialized custom content directory: {$contentDirName}\n";
};

try {
    $sourceVersion = $getWpVersion("{$source}/wp-includes/version.php");
    if ($sourceVersion === null) {
        throw new RuntimeException(
            "Unable to determine donor WordPress version from {$source}/wp-includes/version.php"
        );
    }

    $targetVersion = $getWpVersion("{$target}/wp-includes/version.php");
    $isNewInstall = !file_exists("{$target}/index.php") && $targetVersion === null;
    $versionMismatch = $targetVersion !== null && $sourceVersion !== $targetVersion;
    $contentDirName = $getContentDirName();

    if ($isNewInstall) {
        echo "[wp-sync] Initializing clean WordPress installation (version {$sourceVersion})...\n";
        $copyRecursive($source, $target);

        $syncCustomContentDirectory($target, $contentDirName);

        echo "[wp-sync] Clean installation complete (version {$sourceVersion}).\n";
        exit(0);
    }

    $syncCustomContentDirectory($target, $contentDirName);

    if ($versionMismatch) {
        $action = version_compare($sourceVersion, $targetVersion, '>') ? 'Upgrading' : 'Rolling back';
        echo "[wp-sync] {$action} WordPress core from {$targetVersion} to {$sourceVersion}...\n";

        $parentItems = scandir($target);
        if ($parentItems !== false) {
            foreach ($parentItems as $item) {
                if (preg_match('/^\.(wp-admin|wp-includes)\.(tmp|bak)_[0-9a-f]+$/', $item)) {
                    $deleteRecursive("{$target}/{$item}");
                }
            }
        }

        $atomicSyncDirectory("{$source}/wp-admin", "{$target}/wp-admin");
        $atomicSyncDirectory("{$source}/wp-includes", "{$target}/wp-includes");

        $rootFiles = scandir($source);
        if ($rootFiles !== false) {
            foreach ($rootFiles as $file) {
                if (
                    $file === '.' ||
                    $file === '..' ||
                    $file === 'wp-content' ||
                    $file === 'wp-admin' ||
                    $file === 'wp-includes' ||
                    str_starts_with($file, 'wp-config')
                ) {
                    continue;
                }

                $srcPath = "{$source}/{$file}";
                $dstPath = "{$target}/{$file}";

                if (is_file($srcPath)) {
                    if (!copy($srcPath, $dstPath)) {
                        throw new RuntimeException("Failed to copy root core file {$file}");
                    }
                    chmod($dstPath, 0644);
                }
            }
        }

        echo "[wp-sync] WordPress core synchronized successfully to {$sourceVersion}.\n";
        exit(0);
    }

    echo "[wp-sync] WordPress core is up-to-date (version {$targetVersion}).\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[wp-sync] FATAL ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[wp-sync] File: " . $e->getFile() . " on line " . $e->getLine() . "\n");
    exit(1);
}
