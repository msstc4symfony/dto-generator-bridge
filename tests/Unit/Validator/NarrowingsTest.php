<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * A child's schema that declares a property of its parent again with a narrower schema: the property stays the
 * parent's, and the child checks what its declaration adds with Property.
 */
final class NarrowingsTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    public function testChecksWhatTheChildsDeclarationAdds(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => [
                'code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 8],
                'same' => ['type' => 'string', 'maxLength' => 3],
                'own' => ['type' => 'string'],
                'tag' => ['type' => 'string', 'pattern' => '^t'],
            ]],
        ]], ['Base' => ['type' => 'object', 'properties' => [
            'code' => ['type' => 'string', 'maxLength' => 8],
            'same' => ['type' => 'string', 'maxLength' => 3],
            'tag' => ['type' => 'string'],
        ]]]);

        self::assertSame([
            'RuntimeAssert\\Property(name: \'code\', constraints: [new ' . self::ASSERT . 'Length(min: 2, max: 8)])',
            'RuntimeAssert\\Property(name: \'tag\', constraints: [new ' . self::ASSERT . "Regex(pattern: '/^t/uD')])",
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(['Assert\\Length(max: 8)'], $this->attributesOf($this->code($output, 'Base.php'), 'code'));
    }

    public function testNeedsTheRuntime(): void
    {
        $output = $this->generate(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'minLength' => 2]]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertContains('warning /api.yaml#/components/schemas/Pet: "properties" is checked by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.', $this->messages($output));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     */
    private function withRuntime(array $pet, array $others): Output
    {
        return $this->generate($pet, $others, '8.2', [], 'v7.1.0', self::RUNTIME);
    }
}
