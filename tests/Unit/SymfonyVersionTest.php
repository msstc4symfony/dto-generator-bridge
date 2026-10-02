<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGeneratorBridgeSymfony\Tests\Unit;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGeneratorBridgeSymfony\Settings;
use MSSTC4PHP\DtoGeneratorBridgeSymfony\SymfonyVersion;
use PHPUnit\Framework\TestCase;

final class SymfonyVersionTest extends TestCase
{
    public function testReadsAMajorAndMinorVersion(): void
    {
        $version = SymfonyVersion::fromString('6.4');

        self::assertSame('6.4', $version->toString());
        self::assertTrue($version->isAtLeast(SymfonyVersion::fromString('6.4')));
        self::assertTrue($version->isAtLeast(SymfonyVersion::fromString('5.4')));
        self::assertTrue($version->isAtLeast(SymfonyVersion::fromString('6.3')));
        self::assertFalse($version->isAtLeast(SymfonyVersion::fromString('6.5')));
        self::assertFalse($version->isAtLeast(SymfonyVersion::fromString('7.0')));
        self::assertSame('10.12', SymfonyVersion::fromString('10.12')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function invalidVersions(): iterable
    {
        yield 'major only' => ['7'];
        yield 'patch' => ['6.4.1'];
        yield 'prefix' => ['v6.4'];
        yield 'text' => ['latest'];
        yield 'trailing' => ['6.4 '];
        yield 'newline' => ["6.4\n"];
    }

    /**
     * @dataProvider invalidVersions
     */
    public function testRefusesAnythingButMajorDotMinor(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s" is no Symfony version like "6.4".', $version));

        SymfonyVersion::fromString($version);
    }

    public function testReadsTheVersionsComposerLocks(): void
    {
        self::assertSame('6.4', $this->package('v6.4.1'));
        self::assertSame('7.1', $this->package('7.1.0'));
        self::assertSame('6.4', $this->package('6.4.x-dev'));
        self::assertNull($this->package('dev-main'));
        self::assertNull($this->package('v6'));
        self::assertNull($this->package('dev-release-6.4.x'));
    }

    public function testResolvesTheConfiguredVersionFirst(): void
    {
        $packages = new InstalledPackages(['symfony/validator' => 'v7.1.0']);

        self::assertSame('5.4', SymfonyVersion::resolve(Settings::fromConfig(['version' => '5.4']), $packages)->toString());
    }

    public function testResolvesTheNewestOfTheProjectsSymfonyComponents(): void
    {
        $settings = Settings::fromConfig([]);

        self::assertSame('6.4', SymfonyVersion::resolve($settings, new InstalledPackages(['symfony/validator' => 'v6.4.1', 'symfony/serializer' => 'v5.4.40']))->toString());
        self::assertSame('7.1', SymfonyVersion::resolve($settings, new InstalledPackages(['symfony/validator' => 'v6.4.1', 'symfony/serializer' => 'v7.1.2']))->toString());
        self::assertSame('5.4', SymfonyVersion::resolve($settings, new InstalledPackages(['symfony/serializer' => 'v5.4.40', 'symfony/validator' => 'dev-main']))->toString());
        self::assertSame('6.4', SymfonyVersion::resolve($settings, new InstalledPackages(['symfony/validator' => 'v6.4.1', 'symfony/serializer' => 'dev-main']))->toString());
    }

    public function testResolvesTheNewestKnownVersionWithoutComponents(): void
    {
        self::assertSame(SymfonyVersion::LATEST, SymfonyVersion::resolve(Settings::fromConfig([]), new InstalledPackages())->toString());
    }

    private function package(string $version): ?string
    {
        $found = SymfonyVersion::fromPackageVersion($version);

        return $found instanceof SymfonyVersion ? $found->toString() : null;
    }
}
