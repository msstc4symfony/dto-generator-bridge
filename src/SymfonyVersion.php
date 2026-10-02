<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGeneratorBridgeSymfony;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;

/**
 * A Symfony minor version, which decides the constraints and attributes the bridge writes (bridge spec §5.4).
 */
final class SymfonyVersion
{
    /** The version assumed when the project has no Symfony component to tell. */
    public const LATEST = '7.3';

    private const COMPONENTS = ['symfony/validator', 'symfony/serializer'];

    private int $major;

    private int $minor;

    private function __construct(int $major, int $minor)
    {
        $this->major = $major;
        $this->minor = $minor;
    }

    /**
     * @throws InvalidArgumentException unless the version is "major.minor"
     */
    public static function fromString(string $version): self
    {
        $parsed = self::tryFromString($version);
        if (!$parsed instanceof self) {
            throw new InvalidArgumentException(sprintf('"%s" is no Symfony version like "6.4".', $version));
        }

        return $parsed;
    }

    /**
     * Null unless the version is "major.minor".
     */
    public static function tryFromString(string $version): ?self
    {
        if (preg_match('~^\d+\.\d+$~D', $version) !== 1) {
            return null;
        }

        [$major, $minor] = explode('.', $version);

        return new self((int) $major, (int) $minor);
    }

    /**
     * The minor version of a version Composer locks ("v6.4.1", "6.4.x-dev"); null for a branch like "dev-main".
     */
    public static function fromPackageVersion(string $version): ?self
    {
        return preg_match('~^v?(\d+)\.(\d+)\.~', $version, $matches) === 1 ? new self((int) $matches[1], (int) $matches[2]) : null;
    }

    /**
     * The configured version, else the newest of the project's Symfony components, else LATEST.
     */
    public static function resolve(Settings $settings, InstalledPackages $packages): self
    {
        $configured = $settings->version();
        if ($configured instanceof self) {
            return $configured;
        }

        $newest = null;
        foreach (self::COMPONENTS as $component) {
            $installed = $packages->version($component);
            $version = $installed === null ? null : self::fromPackageVersion($installed);
            if ($version instanceof self && (!$newest instanceof self || $version->isAtLeast($newest))) {
                $newest = $version;
            }
        }

        return $newest ?? self::fromString(self::LATEST);
    }

    public function isAtLeast(self $other): bool
    {
        return [$this->major, $this->minor] >= [$other->major, $other->minor];
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor;
    }
}
