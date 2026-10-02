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
    /** The oldest version the bridge writes for. */
    public const MINIMUM = '5.4';

    /** The newest version whose rules the bridge knows; assumed when the project has no Symfony component. */
    public const LATEST = '7.4';

    /** InstalledPackages cannot list packages, so these stand for "the project's Symfony". */
    private const COMPONENTS = [
        'symfony/validator',
        'symfony/serializer',
        'symfony/framework-bundle',
        'symfony/http-kernel',
        'symfony/dependency-injection',
        'symfony/console',
        'symfony/property-access',
    ];

    private const NUMBER = '(0|[1-9]\d{0,3})';

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
        return self::match('~^' . self::NUMBER . '\.' . self::NUMBER . '$~D', $version);
    }

    /**
     * The minor version of a version Composer locks ("v6.4.1", "6.4.x-dev"); null for a branch like "dev-main".
     */
    public static function fromPackageVersion(string $version): ?self
    {
        return self::match('~^v?' . self::NUMBER . '\.' . self::NUMBER . '\.~', $version);
    }

    public static function latest(): self
    {
        return self::fromString(self::LATEST);
    }

    /**
     * The version to write a component's attributes for: the configured one, else the project's version of that
     * component, else the newest of its other main Symfony packages (they come in step), else LATEST. A locked version
     * may be older than MINIMUM: the caller checks isSupported().
     *
     * @param 'symfony/validator'|'symfony/serializer' $component
     */
    public static function resolve(Settings $settings, InstalledPackages $packages, string $component): self
    {
        $configured = $settings->version();
        if ($configured instanceof self) {
            return $configured;
        }

        $own = self::installed($packages, $component);
        if ($own instanceof self) {
            return $own;
        }

        $newest = null;
        foreach (self::COMPONENTS as $other) {
            $version = self::installed($packages, $other);
            if ($version instanceof self && (!$newest instanceof self || $version->isAtLeast($newest))) {
                $newest = $version;
            }
        }

        return $newest ?? self::latest();
    }

    public function isAtLeast(self $other): bool
    {
        return [$this->major, $this->minor] >= [$other->major, $other->minor];
    }

    public function isSupported(): bool
    {
        return $this->isAtLeast(self::fromString(self::MINIMUM));
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor;
    }

    private static function installed(InstalledPackages $packages, string $component): ?self
    {
        $version = $packages->version($component);

        return $version === null ? null : self::fromPackageVersion($version);
    }

    private static function match(string $pattern, string $version): ?self
    {
        if (preg_match($pattern, $version, $matches) !== 1) {
            return null;
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }
}
