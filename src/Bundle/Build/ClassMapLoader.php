<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\Build;

/**
 * Loads the DTOs of the build mode, which Composer does not know: they live in the build directory.
 *
 * @internal
 */
final class ClassMapLoader
{
    /** @var array<string, string> the maps already registered, by hash: a kernel boots again in tests and workers */
    private static array $registered = [];

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $classes fully qualified class name → file, as the container keeps it
     */
    public static function register(array $classes): void
    {
        $key = md5(serialize($classes));
        if (isset(self::$registered[$key])) {
            return;
        }

        self::$registered[$key] = $key;
        spl_autoload_register(static function (string $class) use ($classes): void {
            $file = $classes[$class] ?? null;
            if (is_string($file)) {
                require $file;
            }
        });
    }
}
