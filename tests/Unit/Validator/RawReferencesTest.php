<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\Constraints\Property;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * A `$ref` in the raw schema of a keyword is followed: the keywords of every schema on the way are checked together.
 */
final class RawReferencesTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const PROPERTY = Property::class;

    private const OTHERS = [
        'Role' => ['type' => 'string', 'enum' => ['admin', 'user'], 'description' => 'A role'],
        'Name' => ['$ref' => '#/components/schemas/Word', 'maxLength' => 5],
        'Word' => ['type' => 'string', 'pattern' => '^[a-z]+$'],
        'Code' => ['type' => 'string', 'minLength' => 2],
        'Address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
    ];

    public function testChecksTheSchemasAReferenceLeadsThrough(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['$ref' => '#/components/schemas/Role']],
            'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['$ref' => '#/components/schemas/Name', 'minLength' => 2]],
            'pair' => ['type' => 'array', 'prefixItems' => [['$ref' => '#/components/schemas/Code']]],
            'note' => ['type' => 'string', 'not' => ['$ref' => '#/components/schemas/Word']],
            'codes' => ['type' => 'object', 'additionalProperties' => true, 'patternProperties' => ['^c' => ['$ref' => '#/components/schemas/Code']]],
            'rest' => ['type' => 'array', 'unevaluatedItems' => ['$ref' => '#/components/schemas/Code']],
            'grade' => ['type' => 'string', 'if' => ['$ref' => '#/components/schemas/Word'], 'then' => ['$ref' => '#/components/schemas/Code']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->messages($output));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "Choice(choices: ['admin', 'user'])])"], $this->attributesOf($code, 'roles'));
        self::assertSame(['RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . 'Length(min: 2, max: 5), new ' . self::ASSERT . "Regex(pattern: '/^[a-z]+$/uD')])"], $this->attributesOf($code, 'labels'));
        $string = 'new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string'), new " . self::ASSERT . 'Length(min: 2)';
        self::assertSame(['Assert\\Collection(fields: [0 => [' . $string . ']], allowExtraFields: true, allowMissingFields: true)'], $this->attributesOf($code, 'pair'));
        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . "Regex(pattern: '/^[a-z]+$/uD')])"], $this->attributesOf($code, 'note'));
        self::assertSame(["RuntimeAssert\\PatternProperty(pattern: '/^c/uD', constraints: [" . $string . '])'], $this->attributesOf($code, 'codes'));
        self::assertSame(['Assert\\All(constraints: [' . $string . '])'], $this->attributesOf($code, 'rest'));
        self::assertSame(['RuntimeAssert\\IfThenElse(if: [new ' . self::ASSERT . "Regex(pattern: '/^[a-z]+$/uD')], then: [new " . self::ASSERT . 'Length(min: 2)])'], $this->attributesOf($code, 'grade'));
    }

    public function testFollowsAReferenceUnderAPatternThePointerEscapes(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'paths' => ['type' => 'object', 'additionalProperties' => true, 'patternProperties' => ['^a/b~' => ['$ref' => '#/components/schemas/Code']]],
        ]]);

        self::assertSame(["RuntimeAssert\\PatternProperty(pattern: '/^a\\/b~/uD', constraints: [new " . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string'), new " . self::ASSERT . 'Length(min: 2)])'], $this->attributesOf($this->code($output, 'Pet.php'), 'paths'));
    }

    public function testFollowsAReferenceInTheNotAndTheDependentSchemasOfAClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string'],
            'code' => ['type' => 'string'],
        ], 'not' => ['properties' => ['kind' => ['$ref' => '#/components/schemas/Word']]], 'dependentSchemas' => ['kind' => ['properties' => ['code' => ['$ref' => '#/components/schemas/Code']]]]]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            'RuntimeAssert\\Not(constraints: [new \\' . self::PROPERTY . "(name: 'kind', constraints: [new " . self::ASSERT . "Regex(pattern: '/^[a-z]+$/uD')])])",
            'RuntimeAssert\\IfThenElse(if: [new \\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\Present(properties: [\'kind\'])], then: [new \\' . self::PROPERTY . "(name: 'code', constraints: [new " . self::ASSERT . 'Length(min: 2)])])',
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testSetsAsideTheMetadataOfAReferencedSchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['$ref' => '#/components/schemas/Tag']],
        ]], ['Tag' => ['type' => 'string', 'maxLength' => 4, 'example' => 'blue', 'readOnly' => true, 'xml' => ['name' => 'tag'], 'externalDocs' => ['url' => 'https://example.com']]]);

        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'Length(max: 4)])'], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
    }

    public function testLeavesUncheckedAReferenceToANullableOfOpenApi30(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['$ref' => '#/components/schemas/Tag']],
        ]], ['Tag' => ['type' => 'string', 'nullable' => true, 'maxLength' => 4]]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/tags: "contains" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "nullable" it is not checked.', $this->messages($output));
    }

    public function testLeavesUncheckedAReferenceItCannotMerge(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'twice' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['$ref' => '#/components/schemas/Code', 'minLength' => 3]],
            'gone' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['$ref' => '#/components/schemas/Gone']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        foreach (['twice', 'gone'] as $property) {
            self::assertSame([], $this->attributesOf($code, $property), $property);
        }

        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        $only = '"contains" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with ';
        self::assertSame([
            $at . 'gone/contains: $ref "#/components/schemas/Gone" does not resolve: /api.yaml has nothing at "/components/schemas/Gone".',
            $at . 'twice: ' . $only . '"$ref" it is not checked.',
            $at . 'gone: ' . $only . '"$ref" it is not checked.',
        ], $this->messages($output));
    }

    /**
     * @dataProvider shapes
     *
     * @param array<string, mixed> $shape
     */
    public function testLeavesUncheckedAReferenceToASchemaThatShapesMore(array $shape): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['$ref' => '#/components/schemas/Alias']],
        ]], ['Alias' => ['$ref' => '#/components/schemas/Shaped'], 'Shaped' => ['minLength' => 1] + $shape, 'Other' => ['type' => 'string']]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/tags: "contains" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "$ref" it is not checked.', $this->messages($output));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function shapes(): iterable
    {
        $other = ['$ref' => '#/components/schemas/Other'];

        yield 'properties' => [['properties' => ['a' => ['type' => 'string']]]];
        yield 'required' => [['required' => ['a']]];
        yield 'items' => [['items' => ['type' => 'string']]];
        yield 'additionalProperties' => [['additionalProperties' => false]];
        yield 'allOf' => [['allOf' => [$other]]];
        yield 'oneOf' => [['oneOf' => [$other]]];
        yield 'anyOf' => [['anyOf' => [$other]]];
        yield 'discriminator' => [['discriminator' => ['propertyName' => 'kind']]];
    }

    public function testChecksTheDeclaredNamesAgainstAReferencedSchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'propertyNames' => ['$ref' => '#/components/schemas/Word'], 'properties' => [
            'name' => ['type' => 'string'],
            'Tag' => ['type' => 'string'],
        ]]);

        self::assertSame(['warning /api.yaml#/components/schemas/Pet: "propertyNames" rejects "Tag", the name of a declared property, which can never be set.'], array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'rejects') !== false)));
    }

    public function testChecksAReferenceInABranchOfAClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string'],
            'code' => ['type' => 'string'],
        ], 'if' => ['properties' => ['kind' => ['$ref' => '#/components/schemas/Word']]], 'then' => ['properties' => ['code' => ['$ref' => '#/components/schemas/Code']]]]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            'RuntimeAssert\\IfThenElse(if: [new \\' . self::PROPERTY . "(name: 'kind', constraints: [new " . self::ASSERT . "Regex(pattern: '/^[a-z]+$/uD')])], then: [new \\" . self::PROPERTY . "(name: 'code', constraints: [new " . self::ASSERT . 'Length(min: 2)])])',
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     */
    private function withRuntime(array $pet, array $others = self::OTHERS): Output
    {
        return $this->generate($pet, $others, '8.2', [], 'v7.1.0', self::RUNTIME);
    }
}
