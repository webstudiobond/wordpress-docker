<?php

declare(strict_types=1);

namespace WordPressDocker;

use ErrorException;
use RuntimeException;
use Throwable;

final class WordPressSync
{
    public function __construct(
        private readonly string $source = '/usr/src/wordpress',
        private readonly string $target = '/var/www/html',
        private readonly FilesystemInterface $filesystem = new NativeFilesystem()
    ) {
    }

    public function getWpVersion(string $versionFile): ?string
    {
        if (!$this->filesystem->isFile($versionFile)) {
            return null;
        }

        $content = $this->filesystem->fileGetContents($versionFile);
        if ($content !== false && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function getContentDirName(?string $envValue = null): string
    {
        $envName = $envValue;
        if ($envName === null) {
            $envName = getenv('WP_CONTENT_DIR');
            if ($envName === false || trim($envName) === '') {
                $envName = getenv('WP_CONTENT_FOLDERNAME');
            }
        }

        if ($envName !== false && trim($envName) !== '') {
            $sanitized = basename(trim($envName, "/\\ \t\n\r\0\x0B"));
            if ($sanitized !== '' && $sanitized !== '.' && $sanitized !== '..') {
                return $sanitized;
            }
        }

        return 'wp-content';
    }

    public function deleteRecursive(string $path): void
    {
        if ($this->filesystem->isLink($path) || $this->filesystem->isFile($path)) {
            $this->filesystem->unlink($path);
            return;
        }

        if (!$this->filesystem->isDir($path)) {
            return;
        }

        $items = $this->filesystem->scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteRecursive($path . '/' . $item);
        }

        $this->filesystem->rmdir($path);
    }

    public function copyRecursive(string $src, string $dst): void
    {
        if ($this->filesystem->isLink($src)) {
            return;
        }

        if ($this->filesystem->isDir($src)) {
            if (!$this->filesystem->isDir($dst)) {
                if (!$this->filesystem->mkdir($dst, 0755, true)) {
                    throw new RuntimeException("Failed to create directory: {$dst}");
                }
                $this->filesystem->chmod($dst, 0755);
            }

            $items = $this->filesystem->scandir($src);
            if ($items === false) {
                throw new RuntimeException("Failed to read directory: {$src}");
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->copyRecursive($src . '/' . $item, $dst . '/' . $item);
            }
        } elseif ($this->filesystem->isFile($src)) {
            if (!$this->filesystem->copy($src, $dst)) {
                throw new RuntimeException("Failed to copy file from {$src} to {$dst}");
            }
            $this->filesystem->chmod($dst, 0644);
        }
    }

    public function atomicSyncDirectory(string $sourceDir, string $targetDir): void
    {
        if (!$this->filesystem->isDir($sourceDir)) {
            return;
        }

        $parentDir = dirname($targetDir);
        $baseName = basename($targetDir);
        $randomSuffix = bin2hex(random_bytes(4));
        $tmpDir = "{$parentDir}/.{$baseName}.tmp_{$randomSuffix}";
        $bakDir = "{$parentDir}/.{$baseName}.bak_{$randomSuffix}";

        try {
            $this->copyRecursive($sourceDir, $tmpDir);

            if ($this->filesystem->isDir($targetDir)) {
                if (!$this->filesystem->rename($targetDir, $bakDir)) {
                    throw new RuntimeException("Failed to move {$targetDir} to backup location {$bakDir}");
                }
            }

            if (!$this->filesystem->rename($tmpDir, $targetDir)) {
                if ($this->filesystem->isDir($bakDir)) {
                    $this->filesystem->rename($bakDir, $targetDir);
                }
                throw new RuntimeException("Failed to activate new {$targetDir} from {$tmpDir}");
            }

            if ($this->filesystem->isDir($bakDir)) {
                $this->deleteRecursive($bakDir);
            }
        } catch (Throwable $e) {
            if ($this->filesystem->isDir($tmpDir)) {
                $this->deleteRecursive($tmpDir);
            }
            throw $e;
        }
    }

    public function syncCustomContentDirectory(string $target, string $contentDirName): void
    {
        if ($contentDirName === 'wp-content') {
            return;
        }

        $wpContentDir = "{$target}/wp-content";
        $customContentDir = "{$target}/{$contentDirName}";

        if (!$this->filesystem->isDir($wpContentDir)) {
            return;
        }

        if (!$this->filesystem->isDir($customContentDir)) {
            if (!$this->filesystem->rename($wpContentDir, $customContentDir)) {
                throw new RuntimeException("Failed to rename {$wpContentDir} to {$customContentDir}");
            }
        } else {
            $contentFiles = $this->filesystem->scandir($wpContentDir);
            if ($contentFiles !== false) {
                foreach ($contentFiles as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $src = "{$wpContentDir}/{$file}";
                    $dst = "{$customContentDir}/{$file}";
                    if (!$this->filesystem->fileExists($dst)) {
                        $this->copyRecursive($src, $dst);
                    }
                }
            }
            $this->deleteRecursive($wpContentDir);
        }
        echo "[wp-sync] Initialized custom content directory: {$contentDirName}\n";
    }

    public function run(): int
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            if (!$this->filesystem->isDir($this->source)) {
                echo "[wp-sync] Source directory {$this->source} not found. Skipping synchronization.\n";
                return 0;
            }

            $sourceVersion = $this->getWpVersion("{$this->source}/wp-includes/version.php");
            if ($sourceVersion === null) {
                throw new RuntimeException(
                    "Unable to determine donor WordPress version from {$this->source}/wp-includes/version.php"
                );
            }

            $targetVersion = $this->getWpVersion("{$this->target}/wp-includes/version.php");
            $isNewInstall = !$this->filesystem->fileExists("{$this->target}/index.php") && $targetVersion === null;
            $versionMismatch = $targetVersion !== null && $sourceVersion !== $targetVersion;
            $contentDirName = $this->getContentDirName();

            if ($isNewInstall) {
                echo "[wp-sync] Initializing clean WordPress installation (version {$sourceVersion})...\n";
                $this->copyRecursive($this->source, $this->target);
                $this->syncCustomContentDirectory($this->target, $contentDirName);
                echo "[wp-sync] Clean installation complete (version {$sourceVersion}).\n";
                return 0;
            }

            $this->syncCustomContentDirectory($this->target, $contentDirName);

            if ($versionMismatch) {
                $action = version_compare($sourceVersion, $targetVersion, '>') ? 'Upgrading' : 'Rolling back';
                echo "[wp-sync] {$action} WordPress core from {$targetVersion} to {$sourceVersion}...\n";

                $parentItems = $this->filesystem->scandir($this->target);
                if ($parentItems !== false) {
                    foreach ($parentItems as $item) {
                        if (preg_match('/^\.(wp-admin|wp-includes)\.(tmp|bak)_[0-9a-f]+$/', $item)) {
                            $this->deleteRecursive("{$this->target}/{$item}");
                        }
                    }
                }

                $this->atomicSyncDirectory("{$this->source}/wp-admin", "{$this->target}/wp-admin");
                $this->atomicSyncDirectory("{$this->source}/wp-includes", "{$this->target}/wp-includes");

                $rootFiles = $this->filesystem->scandir($this->source);
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

                        $srcPath = "{$this->source}/{$file}";
                        $dstPath = "{$this->target}/{$file}";

                        if ($this->filesystem->isFile($srcPath)) {
                            if (!$this->filesystem->copy($srcPath, $dstPath)) {
                                throw new RuntimeException("Failed to copy root core file {$file}");
                            }
                            $this->filesystem->chmod($dstPath, 0644);
                        }
                    }
                }

                echo "[wp-sync] WordPress core synchronized successfully to {$sourceVersion}.\n";
                return 0;
            }

            echo "[wp-sync] WordPress core is up-to-date (version {$targetVersion}).\n";
            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, "[wp-sync] FATAL ERROR: " . $e->getMessage() . "\n");
            fwrite(STDERR, "[wp-sync] File: " . $e->getFile() . " on line " . $e->getLine() . "\n");
            return 1;
        } finally {
            restore_error_handler();
        }
    }
}
