<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGeneratorBridgeSymfony\Tests\Unit;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\FormatMapping;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGeneratorBridgeSymfony\SymfonyExtension;
use PHPUnit\Framework\TestCase;

final class SymfonyExtensionTest extends TestCase
{
    public function testIsConfiguredUnderSymfony(): void
    {
        self::assertSame('symfony', (new SymfonyExtension())->name());
    }

    public function testClaimsTheKeysOfTheBridge(): void
    {
        $registry = $this->registry();

        (new SymfonyExtension())->register($registry, []);

        self::assertSame(['x-validator-*', 'x-serializer-*'], $registry->claimed);
    }

    public function testRefusesASectionItCannotUse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('extensionConfig.symfony.colour is not a setting of the Symfony bridge.');

        (new SymfonyExtension())->register($this->registry(), ['colour' => 'red']);
    }

    /**
     * @return ExtensionRegistry&object{claimed: list<string>}
     */
    private function registry(): ExtensionRegistry
    {
        return new class implements ExtensionRegistry {
            /** @var list<string> */
            public array $claimed = [];

            public function addPropertyEnricher(PropertyEnricher $enricher): void
            {
            }

            public function addClassEnricher(ClassEnricher $enricher): void
            {
            }

            public function addFormat(string $format, FormatMapping $mapping): void
            {
            }

            public function claimExtensionKeys(string ...$globs): void
            {
                array_push($this->claimed, ...$globs);
            }
        };
    }
}
