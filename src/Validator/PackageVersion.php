<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;

/**
 * @internal
 */
final class PackageVersion
{
    private function __construct()
    {
    }

    /**
     * Whether the project installs the package at the minor version or later. A branch (dev-main) counts as new: it is
     * what a project tries before a release.
     *
     * @param non-empty-string $minimum major.minor
     */
    public static function atLeast(InstalledPackages $packages, string $package, string $minimum): bool
    {
        $version = $packages->version($package);
        if ($version === null) {
            return false;
        }

        if (preg_match('/^v?(\d+)\.(\d+)/', $version, $match) !== 1) {
            return true;
        }

        return version_compare($match[1] . '.' . $match[2], $minimum, '>=');
    }
}
