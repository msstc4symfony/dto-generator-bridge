<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGeneratorBridgeSymfony\Tests\Unit;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use PHPUnit\Framework\TestCase;

/**
 * The core discovers the bridge through composer.json alone, so its declaration must name real extensions.
 */
final class ComposerJsonTest extends TestCase
{
    public function testDeclaresExtensionsTheGeneratorCanLoad(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        $extra = $json['extra'] ?? null;
        self::assertIsArray($extra);
        $section = $extra['dto-generator'] ?? null;
        self::assertIsArray($section);
        $extensions = $section['extensions'] ?? null;
        self::assertIsArray($extensions);
        self::assertNotEmpty($extensions);

        foreach ($extensions as $class) {
            self::assertIsString($class);
            self::assertTrue(class_exists($class), $class);
            self::assertTrue(is_a($class, Extension::class, true), $class);
        }
    }
}
