<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\Build;

/**
 * Loads the DTOs of the build mode, which Composer does not know: they live in the build directory. One loader per
 * process, prepended when first registered, over the maps of every kernel built or booted in it; a class in a later map
 * takes the later file, as `cache:clear` boots the old container and builds the new one in the same process.
 *
 * @internal
 */
final class ClassMapLoader
{
    /** @var array<string, string> */
    private static array $classes = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $classes fully qualified class name → file
     */
    public static function add(array $classes): void
    {
        // Merged, not replaced: another kernel of the process may still load its own classes.
        self::$classes = $classes + self::$classes;
        // spl_autoload_register() keeps one entry per callable, so a second call registers nothing.
        spl_autoload_register([self::class, 'load'], true, true);
    }

    /**
     * @internal the autoloader itself
     */
    public static function load(string $class): void
    {
        $file = self::$classes[$class] ?? null;
        // A map outlives its files when a worker keeps running after the build directory went away.
        if ($file !== null && is_file($file)) {
            require $file;
        }
    }
}
