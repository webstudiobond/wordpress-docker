<?php

declare(strict_types=1);

namespace WordPressDocker;

interface FilesystemInterface
{
    public function isDir(string $path): bool;

    public function isFile(string $path): bool;

    public function isLink(string $path): bool;

    public function fileExists(string $path): bool;

    public function fileGetContents(string $path): string|false;

    /**
     * @return array<string>|false
     */
    public function scandir(string $path): array|false;

    public function mkdir(string $path, int $permissions = 0755, bool $recursive = false): bool;

    public function copy(string $source, string $target): bool;

    public function rename(string $source, string $target): bool;

    public function chmod(string $path, int $permissions): bool;

    public function unlink(string $path): bool;

    public function rmdir(string $path): bool;
}
