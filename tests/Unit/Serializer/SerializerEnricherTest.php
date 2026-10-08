<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Serializer;

use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

final class SerializerEnricherTest extends TestCase
{
    use GeneratesDtos;

    private const SERIALIZER = ['symfony/serializer' => 'v7.1.0'];

    private const ONLY_SERIALIZER = ['validator' => false];

    public function testNamesAPropertyByItsWireNameAndGroupsIt(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'first_name' => ['type' => 'string', 'x-serializer-groups' => ['api', 'admin', 'api']],
            'age' => ['type' => 'integer'],
        ]], [], '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("use Symfony\\Component\\Serializer\\Attribute as Serializer;\n", $code);
        self::assertSame(["Serializer\\SerializedName('first_name')", "Serializer\\Groups(['api', 'admin'])"], $this->attributesOf($code, 'firstName'));
        self::assertSame([], $this->attributesOf($code, 'age'));
        self::assertSame([], $this->messages($output));
    }

    public function testIgnoresOrSkipsAPropertyThatAsksForIt(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'secret_key' => ['type' => 'string', 'x-serializer-ignore' => true, 'x-serializer-groups' => ['api']],
            'raw_value' => ['type' => 'string', 'x-serializer-skip' => true],
        ]]);

        self::assertSame(['Serializer\\Ignore'], $this->attributesOf($code, 'secretKey'));
        self::assertSame([], $this->attributesOf($code, 'rawValue'));
    }

    public function testFormatsDatesWithoutTimes(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'born' => ['type' => 'string', 'format' => 'date'],
            'seen' => ['type' => 'string', 'format' => 'date-time'],
            'visits' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Day']],
            'by_vet' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'format' => 'date']],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'loose' => ['type' => 'array'],
            'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'extra' => ['type' => 'object', 'additionalProperties' => true],
        ]], ['Day' => ['type' => 'string', 'format' => 'date']]);
        $context = "Serializer\\Context(normalizationContext: ['datetime_format' => 'Y-m-d'], denormalizationContext: ['datetime_format' => '!Y-m-d'])";

        self::assertSame([$context], $this->attributesOf($code, 'born'));
        self::assertSame([], $this->attributesOf($code, 'seen'));
        self::assertSame([$context], $this->attributesOf($code, 'visits'));
        self::assertSame(["Serializer\\SerializedName('by_vet')", $context], $this->attributesOf($code, 'byVet'));
        foreach (['notes', 'loose', 'labels', 'extra'] as $property) {
            self::assertSame([], $this->attributesOf($code, $property), $property);
        }
    }

    public function testReadsDateTimesLooselyFromSymfony81(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'seen' => ['type' => 'string', 'format' => 'date-time'],
            'visits' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']],
            'born' => ['type' => 'string', 'format' => 'date'],
        ]];
        $loose = "Serializer\\Context(denormalizationContext: ['datetime_format' => null])";

        $new = $this->code($this->generate($schema, [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v8.1.0']), 'Pet.php');
        $old = $this->code($this->generate($schema, [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v8.0.9']), 'Pet.php');

        self::assertSame([$loose], $this->attributesOf($new, 'seen'));
        self::assertSame([$loose], $this->attributesOf($new, 'visits'));
        self::assertCount(1, $this->attributesOf($new, 'born'));
        self::assertSame([], $this->attributesOf($old, 'seen'));
    }

    public function testFormatsDatesTypedThroughANullableUnion(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'due' => ['anyOf' => [['$ref' => '#/components/schemas/Day'], ['type' => 'null']]],
            'left' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
            'days' => ['type' => 'array', 'items' => ['anyOf' => [['$ref' => '#/components/schemas/Day'], ['type' => 'null']]]],
            'weeks' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Day']]],
            'either' => ['anyOf' => [['$ref' => '#/components/schemas/Day'], ['type' => 'string', 'format' => 'date-time']]],
        ]], ['Day' => ['type' => 'string', 'format' => 'date']]);
        $context = "Serializer\\Context(normalizationContext: ['datetime_format' => 'Y-m-d'], denormalizationContext: ['datetime_format' => '!Y-m-d'])";

        foreach (['due', 'left', 'days', 'weeks'] as $property) {
            self::assertSame([$context], $this->attributesOf($code, $property), $property);
        }

        self::assertSame([], $this->attributesOf($code, 'either'));
    }

    public function testIgnoresTheUndeclaredPropertiesTheSerializerCannotCarry(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer']], [], '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER);
        $declared = $this->pet(['type' => 'object', 'properties' => ['additionalProperties' => ['type' => 'string']]]);

        self::assertSame(['Serializer\\Ignore'], $this->attributesOf($this->code($output, 'Pet.php'), 'additionalProperties'));
        self::assertSame(
            ['warning /api.yaml#/components/schemas/Pet/additionalProperties: Symfony Serializer would carry the undeclared properties as one key "additionalProperties", so $additionalProperties is ignored: they are dropped when reading and not written.'],
            $this->messages($output),
        );
        self::assertSame([], $this->attributesOf($declared, 'additionalProperties'));
    }

    public function testReadsTheSerializerKeysOfUndeclaredPropertiesFromTheirSchema(): void
    {
        $confirmed = $this->pet(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer', 'x-serializer-ignore' => true]]);
        $skipped = $this->pet(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer', 'x-serializer-skip' => true]]);

        self::assertSame(['Serializer\\Ignore'], $this->attributesOf($confirmed, 'additionalProperties'));
        self::assertSame([], $this->attributesOf($skipped, 'additionalProperties'));
    }

    public function testWarnsThatAnIgnoredRequiredPropertyCannotBeDenormalized(): void
    {
        $output = $this->generate(['type' => 'object', 'required' => ['secret', 'token'], 'properties' => [
            'secret' => ['type' => 'string', 'x-serializer-ignore' => true],
            'token' => ['type' => 'string', 'default' => 'none', 'x-serializer-ignore' => true],
            'note' => ['type' => 'string', 'x-serializer-ignore' => true],
        ]], [], '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER);

        self::assertSame(['Serializer\\Ignore'], $this->attributesOf($this->code($output, 'Pet.php'), 'secret'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/secret: x-serializer-ignore on a required property leaves the serializer nothing to pass to the constructor; denormalizing fails.',
            'warning /api.yaml#/components/schemas/Pet/properties/token: x-serializer-ignore on a required property leaves the serializer nothing to pass to the constructor; denormalizing fails.',
        ], $this->messages($output));
    }

    public function testMapsNumericDiscriminatorValues(): void
    {
        $output = $this->generate([
            'oneOf' => [['$ref' => '#/components/schemas/Cat']],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['1' => '#/components/schemas/Cat']],
        ], ['Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]]], '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER);

        self::assertSame(["Serializer\\DiscriminatorMap(typeProperty: 'kind', mapping: [1 => Cat::class])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testWritesEveryAttributeAsAnAnnotationForPhp74(): void
    {
        $output = $this->generate([
            'oneOf' => [['$ref' => '#/components/schemas/Cat']],
            'discriminator' => ['propertyName' => 'kind'],
        ], ['Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => [
            'kind' => ['type' => 'string'],
            'born' => ['type' => 'string', 'format' => 'date', 'x-serializer-groups' => ['api']],
        ]]], '7.4', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v5.4.40', 'doctrine/annotations' => '2.0.2']);
        $base = $this->code($output, 'Pet.php');
        $cat = $this->code($output, 'Cat.php');

        self::assertSame([], $this->messages($output));
        self::assertStringContainsString('@Serializer\\DiscriminatorMap(typeProperty="kind", mapping={"Cat"=', $base);
        self::assertStringContainsString('@Serializer\\Groups({"api"})', $cat);
        $docblock = (string) preg_replace('~\s*\n\s*\*\s*~', ' ', $cat);
        self::assertStringContainsString('@Serializer\\Context( normalizationContext={"datetime_format"="Y-m-d"}, denormalizationContext={"datetime_format"="!Y-m-d"} )', $docblock);
    }

    public function testMapsTheSubclassesOfADiscriminatedBase(): void
    {
        $output = $this->generate([
            'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
            'discriminator' => ['propertyName' => 'pet_type', 'mapping' => ['cat' => '#/components/schemas/Cat']],
        ], [
            'Cat' => ['type' => 'object', 'required' => ['pet_type'], 'properties' => ['pet_type' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'required' => ['pet_type'], 'properties' => ['pet_type' => ['type' => 'string']]],
        ], '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER);

        self::assertSame(
            ["Serializer\\DiscriminatorMap(typeProperty: 'pet_type', mapping: ['cat' => Cat::class, 'Dog' => Dog::class])"],
            $this->classAttributesOf($this->code($output, 'Pet.php')),
        );
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Cat.php')));
    }

    public function testUsesTheAttributeNamespaceFromSymfony64(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['first_name' => ['type' => 'string']]], [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v6.4.0']);

        self::assertStringContainsString("use Symfony\\Component\\Serializer\\Attribute as Serializer;\n", $this->code($output, 'Pet.php'));
    }

    public function testUsesTheAnnotationNamespaceBeforeSymfony64(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['first_name' => ['type' => 'string']]], [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v6.3.0']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("use Symfony\\Component\\Serializer\\Annotation as Serializer;\n", $code);
        self::assertSame(["Serializer\\SerializedName('first_name')"], $this->attributesOf($code, 'firstName'));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['first_name' => ['type' => 'string']]], [], '7.4', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v5.4.40', 'doctrine/annotations' => '2.0.2']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("use Symfony\\Component\\Serializer\\Annotation as Serializer;\n", $code);
        self::assertStringContainsString('@Serializer\\SerializedName("first_name")', $code);
        self::assertSame([], $this->messages($output));
    }

    public function testDecidesOnceForClassesAndProperties(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['first_name' => ['type' => 'string']]], [], '8.2', ['validator' => false, 'serializer' => true], null);

        self::assertStringContainsString("Serializer\\SerializedName('first_name')", $this->code($output, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: symfony/serializer is not installed, but the bridge writes its attributes because extensionConfig.symfony.serializer is true.'], $this->messages($output));
    }

    public function testWritesNothingWhenTheSerializerIsOffOrAbsent(): void
    {
        $schema = ['type' => 'object', 'properties' => ['first_name' => ['type' => 'string']]];
        $union = ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']];
        $cat = ['Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]]];

        self::assertStringNotContainsString('Serializer', $this->code($this->generate($schema, [], '8.2', ['validator' => false, 'serializer' => false], null, self::SERIALIZER), 'Pet.php'));
        self::assertStringNotContainsString('Serializer', $this->code($this->generate($union, $cat, '8.2', ['validator' => false, 'serializer' => false], null, self::SERIALIZER), 'Pet.php'));
        self::assertStringNotContainsString('Serializer', $this->code($this->generate($schema, [], '8.2', self::ONLY_SERIALIZER, null), 'Pet.php'));
    }

    public function testWarnsAboutFlagsAndGroupsOfTheWrongKind(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'x-serializer-ignore' => 'yes', 'x-serializer-groups' => 'api'],
        ]], [], '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'name'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/name: x-serializer-ignore must be true or false; it is ignored.',
            'warning /api.yaml#/components/schemas/Pet/properties/name: x-serializer-groups must be a list of group names; the property gets no groups.',
        ], $this->messages($output));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     */
    private function pet(array $pet, array $others = []): string
    {
        $output = $this->generate($pet, $others, '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER);
        self::assertSame([], $this->messages($output));

        return $this->code($output, 'Pet.php');
    }
}
