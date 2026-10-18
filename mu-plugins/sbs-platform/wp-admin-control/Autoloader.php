<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl;

/**
 * Registers an isolated PSR-4 autoloader for WordPress Admin Control module classes,
 * ensuring modular classes are loaded on demand without external runtime dependencies.
 */
final class Autoloader
{
    private const string NAMESPACE_PREFIX = 'WordPressDocker\\AdminControl\\';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        \spl_autoload_register(static function (string $class): void {
            if (!\str_starts_with($class, self::NAMESPACE_PREFIX)) {
                return;
            }

            $relativeClass = \substr($class, \strlen(self::NAMESPACE_PREFIX));
            $file = __DIR__ . '/' . \str_replace('\\', '/', $relativeClass) . '.php';

            if (\is_file($file)) {
                require_once $file;
            }
        });

        self::$registered = true;
    }
}
