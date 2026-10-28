<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Serializer;

use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\CollectionNormalizer;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class SerializerEnricherTest extends TestCase
{
    use GeneratesDtos;

    private const SERIALIZER = ['symfony/serializer' => 'v7.1.0'];

    /** With the runtime that reads collection objects. */
    private const COLLECTIONS = self::SERIALIZER + ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.4.0'];

    private const DEFAULTED = [
        'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Other']],
        'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat'], 'defaultMapping' => 'Other'],
    ];

    private const VARIANTS = [
        'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        'Other' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
    ];

    private const ONLY_SERIALIZER = ['validator' => false];

    private const SPREAD = ['validator' => false, 'additionalProperties' => 'spread'];

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

    public function testNamesTheCollectionClassAndItsItemsInTheContext(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
            'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
            'colours' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Colour']],
            'days' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
            'grid' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']], 'x-php-collection' => 'App\\Bag'],
            'codes' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'x-php-collection' => 'App\\Bag'],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => false],
        ]], [
            'Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Colour' => ['type' => 'string', 'enum' => ['red']],
        ], '8.2', self::ONLY_SERIALIZER, null, self::COLLECTIONS, true, [], [], [], ['collections' => ['class' => 'App\\Collection\\{item}List']]);
        self::assertSame([], $this->messages($output));
        $code = $this->code($output, 'Pet.php');
        $context = static fn (string $class, string $item): string => sprintf("Serializer\\Context(context: ['dto_generator_collection' => ['class' => '%s', 'item' => '%s']])", $class, $item);

        self::assertSame([$context('App\\Collection\\TagList', 'App\\Dto\\Tag')], $this->attributesOf($code, 'tags'));
        self::assertSame([$context('App\\Collection\\StringList', 'string')], $this->attributesOf($code, 'labels'));
        self::assertSame([$context('App\\Collection\\ColourList', 'App\\Dto\\Colour')], $this->attributesOf($code, 'colours'));
        self::assertSame([$context('App\\Bag', 'App\\Dto\\Tag[]')], $this->attributesOf($code, 'grid'));
        self::assertSame([$context('App\\Bag', 'mixed')], $this->attributesOf($code, 'codes'));
        self::assertSame([], $this->attributesOf($code, 'notes'));
        self::assertSame(
            ["Serializer\\Context(normalizationContext: ['datetime_format' => 'Y-m-d', 'dto_generator_collection' => ['class' => 'App\\Collection\\DateTimeImmutableList', 'item' => 'DateTimeImmutable']], denormalizationContext: ['datetime_format' => '!Y-m-d', 'dto_generator_collection' => ['class' => 'App\\Collection\\DateTimeImmutableList', 'item' => 'DateTimeImmutable']])"],
            $this->attributesOf($code, 'days'),
        );
    }

    public function testAddsTheCollectionToTheOneDirectionThatHasAContext(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'seen' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']],
            'sent' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time-rfc-2822']],
        ]];
        $code = $this->code($this->generate($schema, [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v8.1.0'], true, [], [], [], ['collections' => ['class' => 'App\\Collection\\{item}List']]), 'Pet.php');
        $collection = "'dto_generator_collection' => ['class' => 'App\\Collection\\DateTimeImmutableList', 'item' => 'DateTimeImmutable']";

        self::assertSame(["Serializer\\Context(normalizationContext: [{$collection}], denormalizationContext: ['datetime_format' => null, {$collection}])"], $this->attributesOf($code, 'seen'));
        self::assertSame(["Serializer\\Context(context: ['datetime_format' => 'D, d M Y H:i:s O', {$collection}])"], $this->attributesOf($code, 'sent'));
    }

    public function testNamesTheItemsOfACollectionBelowPhp81(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
            'colours' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Colour'], 'x-php-collection' => 'App\\Bag'],
        ]], [
            'Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Colour' => ['type' => 'string', 'enum' => ['red']],
        ], '8.0', self::ONLY_SERIALIZER, null, self::COLLECTIONS, true, [], [], [], ['collections' => ['class' => 'App\\Collection\\{item}List']]);
        self::assertSame([], $this->messages($output));
        $code = $this->code($output, 'Pet.php');
        $context = static fn (string $class, string $item): string => sprintf("Serializer\\Context(context: ['dto_generator_collection' => ['class' => '%s', 'item' => '%s']])", $class, $item);

        self::assertSame([$context('App\\Collection\\TagList', 'App\\Dto\\Tag')], $this->attributesOf($code, 'tags'));
        self::assertSame([$context('App\\Bag', 'string')], $this->attributesOf($code, 'colours'));
    }

    public function testTypesTheMapsAndWarnsAboutObjectsItCannotType(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'byName' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Tag']], 'x-php-collection' => 'App\\Bag'],
            'counts' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']], 'x-php-collection' => 'App\\Bag'],
            'maybe' => ['type' => 'array', 'items' => ['oneOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'null']]], 'x-php-collection' => 'App\\Bag'],
            'maybeByName' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['oneOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'null']]]], 'x-php-collection' => 'App\\Bag'],
        ]], ['Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]], '8.2', self::ONLY_SERIALIZER, null, self::COLLECTIONS);
        $code = $this->code($output, 'Pet.php');
        $context = static fn (string $item): string => sprintf("Serializer\\Context(context: ['dto_generator_collection' => ['class' => 'App\\Bag', 'item' => '%s']])", $item);

        self::assertSame([$context('App\\Dto\\Tag[]')], $this->attributesOf($code, 'byName'));
        self::assertSame([$context('mixed')], $this->attributesOf($code, 'counts'));
        self::assertSame([$context('mixed')], $this->attributesOf($code, 'maybe'));
        self::assertSame(
            [
                'warning /api.yaml#/components/schemas/Pet/properties/maybe: CollectionNormalizer cannot type the items of App\\Bag, App\\Dto\\Tag|null: they stay as the data has them, not objects.',
                'warning /api.yaml#/components/schemas/Pet/properties/maybeByName: CollectionNormalizer cannot type the items of App\\Bag, array<array-key, App\\Dto\\Tag|null>: they stay as the data has them, not objects.',
            ],
            $this->messages($output),
        );
    }

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function missingCollectionNormalizers(): array
    {
        return [
            // The one warning of the run, though the runtime is missing too.
            'php 7.4' => ['7.4', ['symfony/serializer' => 'v6.4.0', 'doctrine/annotations' => '2.0.0'], 'needs PHP 8.0; on PHP 7.4 the Serializer cannot read them.'],
            'no runtime' => ['8.2', self::SERIALIZER, 'comes with msstc4symfony/dto-generator-bridge-runtime 1.4, which the project does not install; the Serializer cannot read them.'],
            'old runtime' => ['8.2', self::SERIALIZER + ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.3.2'], 'comes with msstc4symfony/dto-generator-bridge-runtime 1.4; the project has v1.3.2, so the Serializer cannot read them.'],
            // Generated DTOs take every property through the constructor.
            'symfony 5.4' => ['8.2', ['symfony/serializer' => 'v5.4.55', 'msstc4symfony/dto-generator-bridge-runtime' => 'v1.4.0'], 'needs Symfony 6.2 to get the Context of a constructor argument; Symfony 5.4 makes them without their items.'],
            'symfony 6.1' => ['8.2', ['symfony/serializer' => 'v6.1.0', 'msstc4symfony/dto-generator-bridge-runtime' => 'v1.4.0'], 'needs Symfony 6.2 to get the Context of a constructor argument; Symfony 6.1 makes them without their items.'],
        ];
    }

    /**
     * @dataProvider missingCollectionNormalizers
     *
     * @param array<string, string> $packages
     */
    public function testWarnsOnceWhenNothingReadsTheCollections(string $php, array $packages, string $message): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\\Bag'],
            'codes' => ['type' => 'array', 'items' => ['type' => 'integer'], 'x-php-collection' => 'App\\Bag'],
            'maybe' => ['type' => 'array', 'items' => ['oneOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'null']]], 'x-php-collection' => 'App\\Bag'],
        ]], ['Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]], $php, self::ONLY_SERIALIZER, null, $packages);

        self::assertSame(['warning /api.yaml#: CollectionNormalizer, which builds collection objects, ' . $message], $this->messages($output));
    }

    public function testSymfony62ReadsTheCollections(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\\Bag'],
        ]], [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v6.2.0', 'msstc4symfony/dto-generator-bridge-runtime' => 'v1.4.0']);

        self::assertSame([], $this->messages($output));
    }

    /**
     * Symfony 5.4 writes the collection through the Context; only reading it fails.
     */
    public function testWritesTheContextWhereTheSerializerCannotReadTheCollection(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\\Bag'],
        ]], [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v5.4.55', 'msstc4symfony/dto-generator-bridge-runtime' => 'v1.4.0']);

        self::assertSame(["Serializer\\Context(context: ['dto_generator_collection' => ['class' => 'App\\Bag', 'item' => 'string']])"], $this->attributesOf($this->code($output, 'Pet.php'), 'labels'));
    }

    public function testTheConfiguredSymfonyDecidesWhetherTheCollectionsAreRead(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\\Bag'],
        ]], [], '8.2', self::ONLY_SERIALIZER + ['version' => '5.4'], null, self::COLLECTIONS);

        self::assertSame(['warning /api.yaml#: CollectionNormalizer, which builds collection objects, needs Symfony 6.2 to get the Context of a constructor argument; Symfony 5.4 makes them without their items.'], $this->messages($output));
    }

    public function testWritesTheKeyCollectionNormalizerReads(): void
    {
        if (!interface_exists(NormalizerInterface::class)) {
            self::markTestSkipped('CollectionNormalizer loads only with symfony/serializer.');
        }

        $output = $this->generate(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\\Bag'],
        ]], [], '8.2', self::ONLY_SERIALIZER, null, self::COLLECTIONS);

        self::assertStringContainsString(sprintf("context: ['%s' => ", CollectionNormalizer::CONTEXT), $this->code($output, 'Pet.php'));
    }

    public function testWritesTheCollectionInAnAnnotation(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\\Bag'],
        ]], [], '7.4', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v6.4.0', 'doctrine/annotations' => '2.0.0']);

        self::assertStringContainsString('@Serializer\\Context(context={"dto_generator_collection"={"class"="App\\Bag", "item"="string"}})', $this->code($output, 'Pet.php'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function runtimeBranches(): array
    {
        return ['main' => ['dev-main'], 'named after an old release' => ['dev-1.3-fix']];
    }

    /**
     * @dataProvider runtimeBranches
     */
    public function testABranchOfTheRuntimeReadsTheCollections(string $branch): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\\Bag'],
        ]], [], '8.2', self::ONLY_SERIALIZER, null, self::SERIALIZER + ['msstc4symfony/dto-generator-bridge-runtime' => $branch]);

        self::assertSame([], $this->messages($output));
    }

    public function testReadsAFormatByItsName(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'seen' => ['type' => 'string', 'format' => 'dateTime'],
            'born' => ['type' => 'string', 'format' => 'full-date'],
            'created' => ['type' => 'string', 'format' => 'date-time-rfc-2822'],
        ]];
        $code = $this->code($this->generate($schema, [], '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v8.1.0']), 'Pet.php');

        self::assertSame(["Serializer\\Context(denormalizationContext: ['datetime_format' => null])"], $this->attributesOf($code, 'seen'));
        self::assertCount(1, $this->attributesOf($code, 'born'));
        self::assertSame(["Serializer\\Context(context: ['datetime_format' => 'D, d M Y H:i:s O'])"], $this->attributesOf($code, 'created'));
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

    public function testMarksTheUndeclaredPropertiesForTheNormalizerWhenAskedToSpreadThem(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer', 'x-serializer-groups' => ['api']]];
        $output = $this->generate($schema, [], '8.0', self::SPREAD, null, self::SERIALIZER);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['\\' . AdditionalProperties::class, "Serializer\\Groups(['api'])"], $this->attributesOf($code, 'additionalProperties'));
        self::assertSame([], $this->messages($output));
    }

    public function testKeepsIgnoringOrSkippingUndeclaredPropertiesThatAskForIt(): void
    {
        $ignored = $this->code($this->generate(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer', 'x-serializer-ignore' => true]], [], '8.2', self::SPREAD, null, self::SERIALIZER), 'Pet.php');
        $skipped = $this->code($this->generate(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer', 'x-serializer-skip' => true]], [], '8.2', self::SPREAD, null, self::SERIALIZER), 'Pet.php');

        self::assertSame(['Serializer\\Ignore'], $this->attributesOf($ignored, 'additionalProperties'));
        self::assertSame([], $this->attributesOf($skipped, 'additionalProperties'));
    }

    public function testCannotSpreadUndeclaredPropertiesThroughAnnotations(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string']],
        ], 'additionalProperties' => ['type' => 'integer']], [], '7.4', self::SPREAD, null, ['symfony/serializer' => 'v6.4.0', 'doctrine/annotations' => '2.0.0']);

        self::assertSame(
            ['error /api.yaml#/components/schemas/Pet/additionalProperties: extensionConfig.symfony.additionalProperties: spread needs PHP attributes, which AdditionalPropertiesNormalizer reads; the target writes annotations.'],
            $this->messages($output),
        );
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

    public function testNamesTheDefaultVariantFromSymfony73(): void
    {
        $output = $this->generate(self::DEFAULTED, self::VARIANTS, '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v7.3.0']);

        self::assertSame([], $this->messages($output));
        self::assertSame(
            ["Serializer\\DiscriminatorMap(typeProperty: 'kind', mapping: ['cat' => Cat::class, 'Other' => Other::class], defaultType: 'Other')"],
            $this->classAttributesOf($this->code($output, 'Pet.php')),
        );
    }

    public function testWarnsThatAnOlderSerializerTakesNoDefaultVariant(): void
    {
        $output = $this->generate(self::DEFAULTED, self::VARIANTS, '8.2', self::ONLY_SERIALIZER, null, ['symfony/serializer' => 'v7.2.9']);

        self::assertSame(
            ['warning /api.yaml#/components/schemas/Pet/discriminator/defaultMapping: The Serializer of Symfony 7.2 has no default type in DiscriminatorMap (7.3 adds it): a payload without "kind" does not select App\Dto\Other.'],
            $this->messages($output),
        );
        self::assertSame(
            ["Serializer\\DiscriminatorMap(typeProperty: 'kind', mapping: ['cat' => Cat::class, 'Other' => Other::class])"],
            $this->classAttributesOf($this->code($output, 'Pet.php')),
        );
    }

    public function testWarnsAboutADefaultVariantWithoutAValue(): void
    {
        $output = $this->generate(
            ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat', 'Other' => 'Cat'], 'defaultMapping' => 'Other']] + self::DEFAULTED,
            self::VARIANTS,
            '8.2',
            self::ONLY_SERIALIZER,
            null,
            ['symfony/serializer' => 'v7.3.0'],
        );

        self::assertSame(
            [
                'warning /api.yaml#/components/schemas/Pet/discriminator: Variant App\Dto\Other gets no discriminator value: the mapping gives "Other" to another schema.',
                'warning /api.yaml#/components/schemas/Pet/discriminator/defaultMapping: App\Dto\Other has no discriminator value, so DiscriminatorMap cannot name it its default type: a payload without "kind" does not select it.',
            ],
            $this->messages($output),
        );
        self::assertSame(
            ["Serializer\\DiscriminatorMap(typeProperty: 'kind', mapping: ['cat' => Cat::class, 'Other' => Cat::class])"],
            $this->classAttributesOf($this->code($output, 'Pet.php')),
        );
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
