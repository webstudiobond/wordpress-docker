<?php

declare(strict_types=1);

namespace WordPressDocker;

final class NativeFilesystem implements FilesystemInterface
{
    public function isDir(string $path): bool
    {
        return is_dir($path);
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isLink(string $path): bool
    {
        return is_link($path);
    }

    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    public function fileGetContents(string $path): string|false
    {
        return @file_get_contents($path);
    }

    /**
     * @return array<string>|false
     */
    public function scandir(string $path): array|false
    {
        return @scandir($path);
    }

    public function mkdir(string $path, int $permissions = 0755, bool $recursive = false): bool
    {
        return @mkdir($path, $permissions, $recursive);
    }

    public function copy(string $source, string $target): bool
    {
        return @copy($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        return @rename($source, $target);
    }

    public function chmod(string $path, int $permissions): bool
    {
        return @chmod($path, $permissions);
    }

    public function unlink(string $path): bool
    {
        return @unlink($path);
    }

    public function rmdir(string $path): bool
    {
        return @rmdir($path);
    }
}
