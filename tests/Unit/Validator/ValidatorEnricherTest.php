<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The real generator with the bridge, on a project whose composer.lock pins symfony/validator.
 */
final class ValidatorEnricherTest extends TestCase
{
    use GeneratesDtos;

    public function testConstrainsStringsByRequirementLengthAndPattern(): void
    {
        $code = $this->pet(['type' => 'object', 'required' => ['name'], 'properties' => [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10, 'pattern' => '^a/b$'],
            'nick' => ['type' => 'string', 'maxLength' => 5],
        ]]);

        self::assertStringContainsString("use Symfony\\Component\\Validator\\Constraints as Assert;\n", $code);
        self::assertSame(['Assert\\NotNull', 'Assert\\Length(min: 1, max: 10)', "Assert\\Regex(pattern: '/^a\\/b\$/uD')"], $this->attributesOf($code, 'name'));
        self::assertSame(['Assert\\Length(max: 5)'], $this->attributesOf($code, 'nick'));
    }

    public function testConstrainsNumbersByBoundsAndMultiples(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 30],
            'weight' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 99.5, 'multipleOf' => 0.5],
            'legs' => ['type' => 'integer', 'minimum' => 2],
            'tail' => ['type' => 'integer', 'exclusiveMaximum' => 3],
        ]]);

        self::assertSame(['Assert\\Range(min: 0, max: 30)'], $this->attributesOf($code, 'age'));
        self::assertSame(['Assert\\GreaterThan(value: 0)', 'Assert\\LessThanOrEqual(value: 99.5)', 'Assert\\DivisibleBy(value: 0.5)'], $this->attributesOf($code, 'weight'));
        self::assertSame(['Assert\\GreaterThanOrEqual(value: 2)'], $this->attributesOf($code, 'legs'));
        self::assertSame(['Assert\\LessThan(value: 3)'], $this->attributesOf($code, 'tail'));
    }

    public function testConstrainsCollectionsAndTheirItems(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'uniqueItems' => true, 'items' => ['type' => 'string', 'maxLength' => 8]],
            'scores' => ['type' => 'object', 'maxProperties' => 4, 'additionalProperties' => ['type' => 'integer', 'minimum' => 0]],
        ]]);

        self::assertSame(['Assert\\Count(min: 1, max: 3)', 'Assert\\Unique', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 8)])'], $this->attributesOf($code, 'tags'));
        self::assertSame(['Assert\\Count(max: 4)', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\GreaterThanOrEqual(value: 0)])'], $this->attributesOf($code, 'scores'));
    }

    public function testConstrainsTheValuesOfUndeclaredProperties(): void
    {
        $strings = $this->pet(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string', 'maxLength' => 8, 'pattern' => '^[a-z]+$']]);
        $owners = $this->pet(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['$ref' => '#/components/schemas/Owner']], ['Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]);
        $declared = $this->pet(['type' => 'object', 'required' => ['counts'], 'properties' => [
            'additionalProperties' => ['type' => 'string', 'maxLength' => 3],
            'counts' => ['type' => 'object', 'maxProperties' => 2, 'additionalProperties' => ['type' => 'integer', 'minimum' => 1]],
        ]]);

        self::assertSame(["Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 8), new \\Symfony\\Component\\Validator\\Constraints\\Regex(pattern: '/^[a-z]+$/uD')])"], $this->attributesOf($strings, 'additionalProperties'));
        self::assertSame(['Assert\\Valid'], $this->attributesOf($owners, 'additionalProperties'));
        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($declared, 'additionalProperties'));
        self::assertSame(['Assert\\NotNull', 'Assert\\Count(max: 2)', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\GreaterThanOrEqual(value: 1)])'], $this->attributesOf($declared, 'counts'));
    }

    public function testReadsTheValidatorKeysOfUndeclaredPropertiesFromTheirSchema(): void
    {
        $skipped = $this->pet(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string', 'maxLength' => 8, 'x-validator-skip' => true]]);
        $grouped = $this->pet(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string', 'maxLength' => 8, 'x-validator-groups' => ['api']]]);

        self::assertSame([], $this->attributesOf($skipped, 'additionalProperties'));
        self::assertSame(["Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 8)], groups: ['api', 'Default'])"], $this->attributesOf($grouped, 'additionalProperties'));
    }

    public function testCascadesIntoNestedObjects(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'owner' => ['$ref' => '#/components/schemas/Owner'],
            'friends' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Owner']],
        ]], ['Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]);

        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'owner'));
        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'friends'));
    }

    public function testDecidesCascadingByTheSchemaOfTheItems(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'pals' => ['type' => 'array', 'items' => ['anyOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'null']]]],
            'dates' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']],
            'loose' => ['type' => 'array', 'minItems' => 1],
            'anything' => ['type' => 'object', 'maxProperties' => 2, 'additionalProperties' => true],
            'tags' => ['$ref' => '#/components/schemas/Tags'],
            'born' => ['type' => 'string', 'format' => 'date-time'],
        ]], [
            'Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 4]],
        ]);

        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'pals'));
        self::assertSame([], $this->attributesOf($code, 'dates'));
        self::assertSame(['Assert\\Count(min: 1)'], $this->attributesOf($code, 'loose'));
        self::assertSame(['Assert\\Count(max: 2)'], $this->attributesOf($code, 'anything'));
        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 4)])'], $this->attributesOf($code, 'tags'));
        self::assertSame([], $this->attributesOf($code, 'born'));
    }

    public function testPrefersTheKeywordsBesideAReferenceAndReadsTheRestFromItsTarget(): void
    {
        $schemas = [
            'Email' => ['type' => 'string', 'format' => 'email'],
            'Cat' => ['type' => 'string', 'const' => 'cat'],
            'Status' => ['type' => 'string', 'enum' => ['active', 'gone']],
            'Blank' => ['type' => 'object'],
        ];
        $code = $this->pet(['type' => 'object', 'required' => ['maybe'], 'properties' => [
            'address' => ['$ref' => '#/components/schemas/Email', 'format' => 'ipv4'],
            'kind' => ['$ref' => '#/components/schemas/Cat'],
            'status' => ['$ref' => '#/components/schemas/Status', 'enum' => ['active']],
            'blank' => ['$ref' => '#/components/schemas/Blank'],
            'maybe' => ['type' => ['string', 'null'], 'maxLength' => 2],
            'odd' => ['type' => 'integer', 'minimum' => '5'],
        ]], $schemas, '8.0');

        self::assertSame(["Assert\\Ip(version: '4')", "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'address'));
        self::assertSame(["Assert\\IdenticalTo(value: 'cat')"], $this->attributesOf($code, 'kind'));
        self::assertSame(["Assert\\Choice(choices: ['active'])"], $this->attributesOf($code, 'status'));
        self::assertSame([], $this->attributesOf($code, 'blank'));
        self::assertSame(['Assert\\Length(max: 2)'], $this->attributesOf($code, 'maybe'));
        self::assertSame([], $this->attributesOf($code, 'odd'));
    }

    public function testWarnsAboutGroupNamesThatAreNoNames(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['', 'api', 3]]]]);

        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($this->code($output, 'Pet.php'), 'a'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/a: x-validator-groups lists a value that is not a group name; it is left out.'], $this->messages($output));
    }

    public function testLeavesAFormatMappedToAClassToThatClass(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid']]], [], '8.2', [], 'v7.1.0', [], true, ['uuid' => ['type' => 'Symfony\\Component\\Uid\\Uuid']]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'id'));
    }

    public function testReadsTheConstraintsOfAReferencedScalar(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'email' => ['$ref' => '#/components/schemas/Email'],
            'contact' => ['$ref' => '#/components/schemas/Contact'],
            'short' => ['$ref' => '#/components/schemas/Email', 'maxLength' => 20],
        ]], ['Email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 64], 'Contact' => ['$ref' => '#/components/schemas/Email']]);

        self::assertSame(['Assert\\Length(max: 64)', "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'email'));
        self::assertSame(['Assert\\Length(max: 64)', "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'contact'));
        self::assertSame(['Assert\\Length(max: 20)', "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'short'));
    }

    public function testChecksFormatsWithExplicitOptions(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'ip4' => ['type' => 'string', 'format' => 'ipv4'],
            'ip6' => ['type' => 'string', 'format' => 'ipv6'],
            'host' => ['type' => 'string', 'format' => 'hostname'],
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'site' => ['type' => 'string', 'format' => 'uri'],
            'born' => ['type' => 'string', 'format' => 'date-time'],
        ]]);

        self::assertSame(["Assert\\Ip(version: '4')"], $this->attributesOf($code, 'ip4'));
        self::assertSame(["Assert\\Ip(version: '6')"], $this->attributesOf($code, 'ip6'));
        self::assertSame(['Assert\\Hostname(requireTld: false)'], $this->attributesOf($code, 'host'));
        self::assertSame(['Assert\\Uuid'], $this->attributesOf($code, 'id'));
        self::assertSame([], $this->attributesOf($code, 'site'));
        self::assertStringNotContainsString('Url', $code);
        self::assertSame([], $this->attributesOf($code, 'born'));
    }

    public function testBoundsInt32ValuesAndMergesTheSchemaBounds(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'plain' => ['type' => 'integer', 'format' => 'int32'],
            'narrow' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 0, 'maximum' => 10],
            'low' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 0],
            'high' => ['type' => 'integer', 'format' => 'int32', 'maximum' => 10],
            'wide' => ['type' => 'integer', 'format' => 'int32', 'minimum' => -3000000000, 'maximum' => 3000000000],
            'open' => ['type' => 'integer', 'format' => 'int32', 'exclusiveMaximum' => 10],
            'tie' => ['type' => 'integer', 'format' => 'int32', 'exclusiveMinimum' => -2147483648],
            'maybe' => ['type' => ['integer', 'null'], 'format' => 'int32'],
            'items' => ['type' => 'array', 'items' => ['type' => 'integer', 'format' => 'int32']],
            'long' => ['type' => 'integer', 'format' => 'int64'],
            'beyond' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 3000000000],
            'either' => ['type' => ['integer', 'string'], 'format' => 'int32'],
            'fraction' => ['type' => 'number', 'format' => 'int32'],
            'loose' => ['format' => 'uuid'],
        ]]);
        $code = $this->code($output, 'Pet.php');
        $int32 = 'Assert\\Range(min: -2147483648, max: 2147483647)';

        self::assertSame([$int32], $this->attributesOf($code, 'plain'));
        self::assertSame(['Assert\\Range(min: 0, max: 10)'], $this->attributesOf($code, 'narrow'));
        self::assertSame(['Assert\\Range(min: 0, max: 2147483647)'], $this->attributesOf($code, 'low'));
        self::assertSame(['Assert\\Range(min: -2147483648, max: 10)'], $this->attributesOf($code, 'high'));
        self::assertSame([$int32], $this->attributesOf($code, 'wide'));
        self::assertSame(['Assert\\GreaterThanOrEqual(value: -2147483648)', 'Assert\\LessThan(value: 10)'], $this->attributesOf($code, 'open'));
        self::assertSame(['Assert\\GreaterThan(value: -2147483648)', 'Assert\\LessThanOrEqual(value: 2147483647)'], $this->attributesOf($code, 'tie'));
        self::assertSame([$int32], $this->attributesOf($code, 'maybe'));
        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Range(min: -2147483648, max: 2147483647)])'], $this->attributesOf($code, 'items'));
        self::assertSame([], $this->attributesOf($code, 'long'));
        self::assertSame([], $this->attributesOf($code, 'beyond'));
        self::assertSame([], $this->attributesOf($code, 'either'));
        self::assertSame([], $this->attributesOf($code, 'fraction'));
        self::assertSame([], $this->attributesOf($code, 'loose'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/either/format: Unknown string format "int32"; the property stays a string.',
            'warning /api.yaml#/components/schemas/Pet/properties/fraction/format: Unknown number format "int32"; the property stays a float.',
            'warning /api.yaml#/components/schemas/Pet/properties/beyond: "minimum" and "format: int32" leave no valid value; they are not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/either: "format: int32" checks only an integer, and the value may be of another type; it is not checked.',
        ], $this->messages($output));
    }

    public function testWritesTheInt32RangeAsAnAnnotationForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['count' => ['type' => 'integer', 'format' => 'int32']]], [], '7.4', [], 'v6.4.1', ['doctrine/annotations' => '2.0.2']);

        self::assertStringContainsString("     * @Assert\\Range(min=-2147483648, max=2147483647)\n", $this->code($output, 'Pet.php'));
    }

    public function testChecksBase64OfByteStrings(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'blob' => ['type' => 'string', 'format' => 'byte', 'maxLength' => 8],
            'maybe' => ['type' => ['string', 'null'], 'format' => 'byte'],
            'many' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'byte']],
            'raw' => ['type' => 'string', 'format' => 'binary'],
            'either' => ['type' => ['integer', 'string'], 'format' => 'byte'],
        ]]);
        $code = $this->code($output, 'Pet.php');
        $base64 = "Regex(pattern: '/^(?:[A-Za-z0-9+\\/]{4})*(?:[A-Za-z0-9+\\/]{2}==|[A-Za-z0-9+\\/]{3}=)?\$/D')";

        self::assertSame(['Assert\\Length(max: 8)', 'Assert\\' . $base64], $this->attributesOf($code, 'blob'));
        self::assertSame(['Assert\\' . $base64], $this->attributesOf($code, 'maybe'));
        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\' . $base64 . '])'], $this->attributesOf($code, 'many'));
        self::assertSame([], $this->attributesOf($code, 'raw'));
        self::assertSame([], $this->attributesOf($code, 'either'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/either/format: Unknown integer format "byte"; the property stays an int.',
            'warning /api.yaml#/components/schemas/Pet/properties/either: "format: byte" checks only a string, and the value may be of another type; it is not checked.',
        ], $this->messages($output));
    }

    public function testWritesTheBase64PatternAsAnAnnotationForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['blob' => ['type' => 'string', 'format' => 'byte']]], [], '7.4', [], 'v6.4.1', ['doctrine/annotations' => '2.0.2']);

        self::assertStringContainsString('     * @Assert\\Regex(pattern="/^(?:[A-Za-z0-9+\\/]{4})*(?:[A-Za-z0-9+\\/]{2}==|[A-Za-z0-9+\\/]{3}=)?$/D")' . "\n", $this->code($output, 'Pet.php'));
    }

    public function testComparesWithAConstant(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'const' => 'cat'],
            'nothing' => ['type' => ['null', 'string'], 'const' => null],
            'shape' => ['type' => 'array', 'items' => ['type' => 'integer'], 'const' => [1, 2]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(["Assert\\IdenticalTo(value: 'cat')"], $this->attributesOf($code, 'kind'));
        self::assertSame(['Assert\\IsNull'], $this->attributesOf($code, 'nothing'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/shape: "const" with an array or object has no Symfony constraint; it is not checked.', $this->messages($output));
    }

    public function testChoosesAmongEnumValuesWhereTheTargetHasNoEnums(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['active', 'gone']],
            'size' => ['type' => 'integer', 'enum' => [1, 2]],
            'maybe' => ['type' => ['string', 'null'], 'enum' => ['x', null]],
            'mood' => ['$ref' => '#/components/schemas/Plain', 'enum' => ['calm']],
            'level' => ['type' => 'integer', 'enum' => [1, 2], 'const' => 2],
            'pair' => ['$ref' => '#/components/schemas/Trio', 'enum' => ['c', 'a', 'z']],
        ]];
        $others = ['Plain' => ['type' => 'string'], 'Trio' => ['type' => 'string', 'enum' => ['a', 'b', 'c']]];

        $old = $this->pet($schema, $others, '8.0');
        $new = $this->pet($schema, $others, '8.2');

        self::assertSame(["Assert\\Choice(choices: ['active', 'gone'])"], $this->attributesOf($old, 'status'));
        self::assertSame(['Assert\\Choice(choices: [1, 2])'], $this->attributesOf($old, 'size'));
        self::assertSame(["Assert\\Choice(choices: ['x'])"], $this->attributesOf($old, 'maybe'));
        self::assertSame(["Assert\\Choice(choices: ['calm'])"], $this->attributesOf($old, 'mood'));
        self::assertSame(['Assert\\Choice(choices: [1, 2])', 'Assert\\IdenticalTo(value: 2)'], $this->attributesOf($old, 'level'));
        self::assertSame(["Assert\\Choice(choices: ['c', 'a'])"], $this->attributesOf($old, 'pair'));
        self::assertSame([], $this->attributesOf($new, 'status'));
        self::assertSame([], $this->attributesOf($new, 'maybe'));
        // The generator types a $ref by its target and ignores the enum beside it: only the constraint checks it.
        self::assertSame(["Assert\\Choice(choices: ['calm'])"], $this->attributesOf($new, 'mood'));
    }

    public function testAddsGroupsAndKeepsTheDefaultOne(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api']],
            'b' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api', 'Default']],
            'c' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api'], 'x-validator-groups-exclusive' => true],
            'd' => ['type' => 'string', 'maxLength' => 1],
        ]], [], '8.2', ['groups' => ['admin']]);

        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($code, 'a'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($code, 'b'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api'])"], $this->attributesOf($code, 'c'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['admin', 'Default'])"], $this->attributesOf($code, 'd'));
    }

    public function testSkipsAPropertyThatOptsOut(): void
    {
        $code = $this->pet(['type' => 'object', 'required' => ['name'], 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 3, 'x-validator-skip' => true],
        ]]);

        self::assertStringNotContainsString('Assert', $code);
    }

    public function testWritesNothingWhenTheValidatorIsOff(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 3]]];

        self::assertStringNotContainsString('Assert', $this->pet($schema, [], '8.2', ['validator' => false]));
        self::assertStringNotContainsString('Assert', $this->pet($schema, [], '8.2', [], null));
    }

    public function testWarnsOnceWhenForcedWithoutTheValidatorInstalled(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'string', 'maxLength' => 3]]], [], '8.2', ['validator' => true], null);

        self::assertStringContainsString('#[Assert\\Length(max: 3)]', $this->code($output, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.'], $this->messages($output));
    }

    public function testDecidesOncePerRunAcrossDocuments(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3]]];
        $output = $this->generate($schema, [], '8.2', ['validator' => true], null, [], true, [], [], ['other' => $schema]);

        self::assertSame(['warning /api.yaml#: symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.'], $this->messages($output));
    }

    public function testWritesNothingForASymfonyItDoesNotSupport(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'string', 'maxLength' => 3]]], [], '8.2', [], 'v4.4.49');

        self::assertStringNotContainsString('Assert', $this->code($output, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: The bridge writes constraints for symfony/validator 5.4 or newer, the project has 4.4; none are written.'], $this->messages($output));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 3]]], [], '7.4', [], 'v6.4.1', ['doctrine/annotations' => '2.0.2']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("use Symfony\\Component\\Validator\\Constraints as Assert;\n", $code);
        self::assertStringContainsString("     * @Assert\\Length(max=3)\n", $code);
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsWhenAnnotationsCannotBeRead(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'string', 'maxLength' => 3]]];

        $missing = $this->generate($schema, [], '7.4', [], 'v6.4.1');
        self::assertStringContainsString('@Assert\\Length(max=3)', $this->code($missing, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: symfony/validator reads annotations through doctrine/annotations, which the project does not install.'], $this->messages($missing));

        $strict = $this->generate($schema, [], '7.4', [], 'v7.1.0', ['doctrine/annotations' => '2.0.2']);
        self::assertSame(['error /api.yaml#: symfony/validator 7.1 reads no annotations, and PHP 7.4 has no attributes; no constraints are written.'], $this->messages($strict));

        $loose = $this->generate($schema, [], '7.4', [], 'v7.1.0', [], false);
        self::assertStringNotContainsString('Assert', $this->code($loose, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: symfony/validator 7.1 reads no annotations, and PHP 7.4 has no attributes; no constraints are written.'], $this->messages($loose));
    }

    public function testEscapesOnlyTheDelimiterAndTranslatesUnicodeEscapes(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'path' => ['type' => 'string', 'pattern' => '^a\/b/c$'],
            'accent' => ['type' => 'string', 'pattern' => '^\\u00e9+$'],
            'broken' => ['type' => 'string', 'pattern' => '(a'],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(["Assert\\Regex(pattern: '/^a\\/b\\/c\$/uD')"], $this->attributesOf($code, 'path'));
        self::assertSame(["Assert\\Regex(pattern: '/^\\x{E9}+\$/uD')"], $this->attributesOf($code, 'accent'));
        self::assertSame([], $this->attributesOf($code, 'broken'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/broken: "pattern" is no regular expression PHP can run; it is not checked.'], $this->messages($output));
    }

    public function testKeepsValidOnThePropertyAtAnyDepth(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'teams' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/components/schemas/Owner']]],
            'words' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3]]],
        ]], ['Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]);

        self::assertSame(['Assert\\Count(max: 2)', 'Assert\\Valid', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Count(min: 1)])'], $this->attributesOf($code, 'teams'));
        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 3)])])'], $this->attributesOf($code, 'words'));
    }

    public function testWarnsInsteadOfAllWhereAttributesAllowNoNew(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 8]],
        ]], [], '8.0');

        self::assertSame(['Assert\\Count(max: 3)'], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/tags: The constraints of the elements go inside All as "new", which PHP 8.0 does not allow in attributes; they are not checked.'], $this->messages($output));
    }

    public function testComparesWithTheConstantAsThePropertyHoldsIt(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'ratio' => ['type' => 'number', 'const' => 1],
            'count' => ['type' => 'integer', 'const' => 1],
            'odd' => ['type' => 'number', 'const' => 'x'],
            'level' => ['type' => 'integer', 'enum' => [1, 2], 'const' => 2],
            'tone' => ['type' => 'string', 'enum' => ['low', 'high'], 'const' => 'low'],
        ]]);
        $output = $this->generate(['type' => 'object', 'properties' => ['shade' => ['type' => 'string', 'enum' => ['dark'], 'const' => 'light']]]);

        self::assertSame(['Assert\\IdenticalTo(value: 1.0)'], $this->attributesOf($code, 'ratio'));
        self::assertSame(['Assert\\IdenticalTo(value: 1)'], $this->attributesOf($code, 'count'));
        self::assertSame(["Assert\\IdenticalTo(value: 'x')"], $this->attributesOf($code, 'odd'));
        self::assertSame(['Assert\\IdenticalTo(value: PetLevel::VALUE_2)'], $this->attributesOf($code, 'level'));
        self::assertSame(['Assert\\IdenticalTo(value: PetTone::LOW)'], $this->attributesOf($code, 'tone'));
        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'shade'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/shade: "const" is none of the enum values; it is not checked.'], $this->messages($output));
    }

    public function testLeavesKeywordsOfOneTypeOffAValueOfSeveral(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'code' => ['type' => ['string', 'integer'], 'minLength' => 2, 'minimum' => 3],
            'either' => ['anyOf' => [['type' => 'string', 'maxLength' => 3], ['type' => 'integer']]],
            'free' => ['maxLength' => 3],
            'born' => ['type' => 'string', 'format' => 'date-time', 'maxLength' => 30],
        ]], [], '8.2');
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'code'));
        self::assertSame([], $this->attributesOf($code, 'either'));
        self::assertSame([], $this->attributesOf($code, 'free'));
        self::assertSame([], $this->attributesOf($code, 'born'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/code: "minLength" checks only a string, and the value may be of another type; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/code: "minimum" checks only a number, and the value may be of another type; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/free: "maxLength" checks only a string, and the value may be of another type; it is not checked.',
        ], $this->messages($output));
    }

    public function testReadsKeywordsWrittenAsOtherNumbersAndWarnsAboutTheRest(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 2.0, 'minLength' => -1],
            'nick' => ['type' => 'string', 'minLength' => 0, 'maxLength' => 2.5],
            'tag' => ['type' => 'string', 'minLength' => 0.0],
            'bio' => ['type' => 'string', 'maxLength' => 9.2233720368547758E18, 'minLength' => -1.0],
            'age' => ['type' => 'integer', 'minimum' => '5'],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Length(max: 2)'], $this->attributesOf($code, 'name'));
        self::assertSame(['Assert\\Length(min: 0)'], $this->attributesOf($code, 'nick'));
        self::assertSame(['Assert\\Length(min: 0)'], $this->attributesOf($code, 'tag'));
        self::assertSame([], $this->attributesOf($code, 'bio'));
        self::assertSame([], $this->attributesOf($code, 'age'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/name: "minLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/nick: "maxLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/bio: "minLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/bio: "maxLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/age: "minimum" must be a number; it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksTheKeywordsBesideAReferenceAndThoseOfItsTarget(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'wide' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 200, 'pattern' => '^a'],
            'broken' => ['$ref' => '#/components/schemas/Code', 'pattern' => '(a'],
            'narrow' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 4, 'minLength' => 2],
            'step' => ['$ref' => '#/components/schemas/Step', 'multipleOf' => 3, 'minimum' => 1],
        ]], [
            'Code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z]+$'],
            'Step' => ['type' => 'integer', 'multipleOf' => 2, 'minimum' => 0],
        ]);

        self::assertSame(['Assert\\Length(min: 1, max: 64)', "Assert\\Regex(pattern: '/^a/uD')", "Assert\\Regex(pattern: '/^[a-z]+\$/uD')"], $this->attributesOf($code, 'wide'));
        self::assertSame(['Assert\\Length(min: 2, max: 4)', "Assert\\Regex(pattern: '/^[a-z]+\$/uD')"], $this->attributesOf($code, 'narrow'));
        self::assertSame(['Assert\\Length(min: 1, max: 64)', "Assert\\Regex(pattern: '/^[a-z]+\$/uD')"], $this->attributesOf($code, 'broken'));
        self::assertSame([
            'Assert\\GreaterThanOrEqual(value: 1)',
            'Assert\\DivisibleBy(value: 3)',
            'Assert\\DivisibleBy(value: 2)',
        ], $this->attributesOf($code, 'step'));
    }

    public function testWarnsThatAnEnumBesideAReferenceDoesNotNarrowAPhpEnum(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'status' => ['$ref' => '#/components/schemas/Status', 'enum' => ['active']],
        ]], ['Status' => ['type' => 'string', 'enum' => ['active', 'gone']]]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'status'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/status: One "enum" narrows another, which the generated enum type does not; the narrowing is not checked.'], $this->messages($output));
    }

    public function testWarnsAboutGroupsThatAreNoListAndDropsRepeatedOnes(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => 'api'],
            'b' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api', 'api']],
            'c' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['first' => 'api']],
            'd' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api', 'admin']],
        ]], [], '8.2', ['groups' => ['admin']]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Length(max: 1)'], $this->attributesOf($code, 'a'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($code, 'b'));
        self::assertSame(['Assert\\Length(max: 1)'], $this->attributesOf($code, 'c'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'admin', 'Default'])"], $this->attributesOf($code, 'd'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/a: x-validator-groups must be a list of group names; the property gets no groups.',
            'warning /api.yaml#/components/schemas/Pet/properties/c: x-validator-groups must be a list of group names; the property gets no groups.',
        ], $this->messages($output));
    }

    public function testSaysWhichSettingAsksForAnnotationsOnATargetWithAttributes(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3]]], [], '8.2', [], 'v7.1.0', [], false, [], ['metadata' => 'annotations']);

        self::assertSame(['warning /api.yaml#: symfony/validator 7.1 reads no annotations, which target.metadata asks for; no constraints are written.'], $this->messages($output));
    }

    public function testAssumesAVersionReadingAnnotationsWhenNothingPinsOne(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3]]], [], '7.4', ['validator' => true], null);

        self::assertStringContainsString('@Assert\\Length(max=3)', $this->code($output, 'Pet.php'));
        self::assertSame([
            'warning /api.yaml#: symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.',
            'warning /api.yaml#: symfony/validator reads annotations through doctrine/annotations, which the project does not install.',
        ], $this->messages($output));
    }

    public function testChoosesAmongNumbersAndBooleansThatBecomeNoPhpEnum(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'ratio' => ['type' => 'number', 'enum' => [0.5, 1.5]],
            'flag' => ['type' => 'boolean', 'enum' => [true]],
            'whole' => ['type' => 'integer', 'const' => 2.0],
            'half' => ['type' => 'integer', 'const' => 2.5],
            'real' => ['type' => 'number', 'const' => 2.0],
        ]]);

        self::assertSame(['Assert\\Choice(choices: [0.5, 1.5])'], $this->attributesOf($code, 'ratio'));
        self::assertSame(['Assert\\Choice(choices: [true])'], $this->attributesOf($code, 'flag'));
        self::assertSame(['Assert\\IdenticalTo(value: 2)'], $this->attributesOf($code, 'whole'));
        self::assertSame(['Assert\\IdenticalTo(value: 2.5)'], $this->attributesOf($code, 'half'));
        self::assertSame(['Assert\\IdenticalTo(value: 2.0)'], $this->attributesOf($code, 'real'));
    }

    public function testWarnsAboutEnumsWithoutACommonValueAndSkipsAnEnumOfNull(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'state' => ['$ref' => '#/components/schemas/State', 'enum' => ['zz']],
            'none' => ['type' => ['string', 'null'], 'enum' => [null]],
        ]], ['State' => ['type' => 'string', 'enum' => ['a', 'b']]], '8.0');
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'state'));
        self::assertSame([], $this->attributesOf($code, 'none'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/state: The "enum" lists have no value in common, so no value is valid; they are not checked.', $this->messages($output));
    }

    public function testRequiresNoValueOfAMixedProperty(): void
    {
        $code = $this->pet(['type' => 'object', 'required' => ['any', 'nil'], 'properties' => [
            'any' => new stdClass(),
            'nil' => ['type' => 'null'],
        ]]);

        self::assertSame([], $this->attributesOf($code, 'any'));
        self::assertSame([], $this->attributesOf($code, 'nil'));
    }

    public function testReadsEveryLinkOfAReferenceChainAndASingleAllOfBranch(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'chain' => ['$ref' => '#/components/schemas/Short', 'maxLength' => 5],
            'wrapped' => ['allOf' => [['$ref' => '#/components/schemas/Tags']], 'description' => 'Tags of the pet'],
            'joined' => ['allOf' => [['$ref' => '#/components/schemas/Code']], 'minLength' => 8],
            'floor' => ['$ref' => '#/components/schemas/Low', 'minimum' => -5],
        ]], [
            'Low' => ['type' => 'integer', 'minimum' => 0],
            'Short' => ['$ref' => '#/components/schemas/Code', 'minLength' => 2],
            'Code' => ['type' => 'string', 'maxLength' => 8],
            'Tags' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 2]],
        ]);

        self::assertSame(['Assert\\Length(min: 2, max: 5)'], $this->attributesOf($code, 'chain'));
        self::assertSame(['Assert\\Length(min: 8, max: 8)'], $this->attributesOf($code, 'joined'));
        self::assertSame(['Assert\\GreaterThanOrEqual(value: 0)'], $this->attributesOf($code, 'floor'));
        self::assertSame(['Assert\\Count(max: 3)', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 2)])'], $this->attributesOf($code, 'wrapped'));
    }

    public function testWarnsAboutBoundsNoValueMeetsAndDivisorsBelowOne(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['$ref' => '#/components/schemas/Code', 'minLength' => 9],
            'age' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 1, 'multipleOf' => 0],
            'tags' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 1, 'items' => ['type' => 'string']],
            'step' => ['type' => 'number', 'multipleOf' => -2],
            'odd' => ['type' => 'number', 'multipleOf' => '3'],
        ]], ['Code' => ['type' => 'string', 'maxLength' => 8]]);
        $code = $this->code($output, 'Pet.php');

        foreach (['name', 'age', 'tags', 'step', 'odd'] as $property) {
            self::assertSame([], $this->attributesOf($code, $property), $property);
        }

        // The generator reports bounds that contradict within one schema too; the bridge also sees those across a $ref.
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/age: The minimum is greater than the maximum, so no range is applied.',
            'warning /api.yaml#/components/schemas/Pet/properties/name: "minLength" is above "maxLength", so no value is valid; they are not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/age: "minimum" and "maximum" leave no valid value; they are not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/age: "multipleOf" must be a number above zero; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/tags: "minItems" is above "maxItems", so no value is valid; they are not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/step: "multipleOf" must be a number above zero; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/odd: "multipleOf" must be a number above zero; it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksUniquenessOfListsOnly(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'scores' => ['type' => 'object', 'uniqueItems' => true, 'additionalProperties' => ['type' => 'integer']],
        ]]);

        self::assertSame([], $this->attributesOf($code, 'scores'));
    }

    public function testWarnsAboutFlagsThatAreNoBooleans(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 3, 'x-validator-skip' => 'yes', 'x-validator-groups' => ['api'], 'x-validator-groups-exclusive' => 'no'],
        ]]);

        self::assertSame(["Assert\\Length(max: 3, groups: ['api', 'Default'])"], $this->attributesOf($this->code($output, 'Pet.php'), 'name'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/name: x-validator-skip must be true or false; it is ignored.',
            'warning /api.yaml#/components/schemas/Pet/properties/name: x-validator-groups-exclusive must be true or false; it is ignored.',
        ], $this->messages($output));
    }

    public function testUsesTheStrictestOfInclusiveAndExclusiveBounds(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'tie' => ['type' => 'integer', 'minimum' => 3, 'exclusiveMinimum' => 3, 'maximum' => 9],
            'inner' => ['type' => 'number', 'minimum' => 4, 'exclusiveMinimum' => 2, 'exclusiveMaximum' => 8, 'maximum' => 6],
            'point' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 5],
            'open' => ['type' => 'integer', 'exclusiveMinimum' => 5, 'maximum' => 5],
            'apart' => ['type' => 'integer', 'minimum' => 5, 'exclusiveMaximum' => 5],
            'cap' => ['type' => 'integer', 'exclusiveMaximum' => 9, 'maximum' => 9],
            'low' => ['$ref' => '#/components/schemas/Cap', 'maximum' => 4],
        ]], ['Cap' => ['type' => 'integer', 'maximum' => 9]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\GreaterThan(value: 3)', 'Assert\\LessThanOrEqual(value: 9)'], $this->attributesOf($code, 'tie'));
        self::assertSame(['Assert\\Range(min: 4, max: 6)'], $this->attributesOf($code, 'inner'));
        self::assertSame(['Assert\\Range(min: 5, max: 5)'], $this->attributesOf($code, 'point'));
        self::assertSame(['Assert\\LessThan(value: 9)'], $this->attributesOf($code, 'cap'));
        self::assertSame(['Assert\\LessThanOrEqual(value: 4)'], $this->attributesOf($code, 'low'));
        self::assertSame([], $this->attributesOf($code, 'open'));
        self::assertSame([], $this->attributesOf($code, 'apart'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/open: "exclusiveMinimum" and "maximum" leave no valid value; they are not checked.', $this->messages($output));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/apart: "minimum" and "exclusiveMaximum" leave no valid value; they are not checked.', $this->messages($output));
    }

    public function testWarnsAboutDifferentConstantsAndAnEnumOfArrays(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'kind' => ['$ref' => '#/components/schemas/Cat', 'const' => 'dog'],
            'same' => ['$ref' => '#/components/schemas/Cat', 'const' => 'cat'],
            'shape' => ['enum' => [1.5, [1, 2]]],
        ]], ['Cat' => ['type' => 'string', 'const' => 'cat']]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'kind'));
        self::assertSame(["Assert\\IdenticalTo(value: 'cat')"], $this->attributesOf($code, 'same'));
        self::assertSame([], $this->attributesOf($code, 'shape'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/kind: The "const" values differ, so no value is valid; they are not checked.', $this->messages($output));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/shape: "enum" lists an array or object, which a Choice attribute cannot hold; it is not checked.', $this->messages($output));
    }

    public function testTypesThroughTheOneTypedAllOfBranchAsTheGeneratorDoes(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'short' => ['allOf' => [['$ref' => '#/components/schemas/Tags'], ['maxItems' => 2]]],
            'maybe' => ['allOf' => [['$ref' => '#/components/schemas/Tags']], 'type' => ['null']],
        ]], ['Tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 4]]]);
        $all = 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 4)])';

        self::assertSame(['Assert\\Count(max: 2)', $all], $this->attributesOf($code, 'short'));
        self::assertSame([$all], $this->attributesOf($code, 'maybe'));
    }

    public function testWarnsAboutAnEnumOnlyWhenItNarrowsAnother(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'same' => ['$ref' => '#/components/schemas/Status', 'enum' => ['b', 'a']],
        ]], ['Status' => ['type' => 'string', 'enum' => ['a', 'b']]]);

        self::assertSame([], $this->messages($output));
    }

    public function testChecksEveryFormatThatApplies(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'contact' => ['allOf' => [['$ref' => '#/components/schemas/Email']], 'format' => 'uuid'],
            'secret' => ['allOf' => [['$ref' => '#/components/schemas/Email']], 'format' => 'password'],
            'count' => ['type' => 'integer', 'format' => 'int32', 'allOf' => [['format' => 'int64']]],
        ]], ['Email' => ['type' => 'string', 'format' => 'email']]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Uuid', "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'contact'));
        self::assertSame(["Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'secret'));
        self::assertSame(['Assert\\Range(min: -2147483648, max: 2147483647)'], $this->attributesOf($code, 'count'));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesEnumAndConstOfAClassTypedValueToTheClass(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'currency' => ['$ref' => '#/components/schemas/Currency'],
            'fixed' => ['type' => 'string', 'const' => 'A', 'x-php-type' => 'App\\Code'],
            'id' => ['type' => 'string', 'format' => 'uuid', 'const' => '00000000-0000-0000-0000-000000000000'],
            'born' => ['type' => 'string', 'format' => 'date-time', 'const' => '2026-10-02T00:00:00Z'],
            'gone' => ['type' => ['string', 'null'], 'format' => 'date-time', 'const' => null],
            'blank' => ['type' => ['string', 'null'], 'format' => 'date-time', 'enum' => [null]],
        ]], ['Currency' => ['type' => 'string', 'enum' => ['USD', 'EUR'], 'x-php-type' => 'App\\Currency']], '8.2', [], 'v7.1.0', [], true, ['uuid' => ['type' => 'Symfony\\Component\\Uid\\Uuid']]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'currency'));
        self::assertSame([], $this->attributesOf($code, 'fixed'));
        self::assertSame([], $this->attributesOf($code, 'id'));
        self::assertSame([], $this->attributesOf($code, 'born'));
        self::assertSame(['Assert\\IsNull'], $this->attributesOf($code, 'gone'));
        self::assertSame([], $this->attributesOf($code, 'blank'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/id: "const" on a value of class Symfony\\Component\\Uid\\Uuid has no constraint to check it; it is not checked.', $this->messages($output));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/born: "const" on a value of class DateTimeImmutable has no constraint to check it; it is not checked.', $this->messages($output));
        // The fifth message is the generator's own, about the enum of null.
        self::assertCount(5, $this->messages($output));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/currency: "enum" on a value of class App\\Currency has no constraint to check it; it is not checked.', $this->messages($output));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/fixed: "const" on a value of class App\\Code has no constraint to check it; it is not checked.', $this->messages($output));
    }

    public function testChecksTheOneMemberOfAUnionBesideNull(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'nick' => ['anyOf' => [['type' => 'string', 'maxLength' => 3], ['type' => 'null']]],
            'owner' => ['oneOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'null']]],
        ]], ['Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]);

        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($code, 'nick'));
        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'owner'));
    }

    public function testComparesNumbersAsJsonDoes(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'ratio' => ['$ref' => '#/components/schemas/One', 'const' => 1.0],
            'whole' => ['$ref' => '#/components/schemas/Unit', 'const' => 1.0],
        ]], ['One' => ['type' => 'number', 'const' => 1], 'Unit' => ['type' => 'integer', 'const' => 1]]);

        self::assertSame(['Assert\\IdenticalTo(value: 1.0)'], $this->attributesOf($code, 'ratio'));
        self::assertSame(['Assert\\IdenticalTo(value: 1)'], $this->attributesOf($code, 'whole'));
    }

    public function testIntersectsEnumsOfEqualNumbers(): void
    {
        $schema = ['type' => 'object', 'properties' => ['size' => ['$ref' => '#/components/schemas/Size', 'enum' => [1.0, 2.0]]]];
        $others = ['Size' => ['type' => 'number', 'enum' => [1, 2]]];

        $old = $this->generate($schema, $others, '8.0');
        self::assertSame(['Assert\\Choice(choices: [1.0, 2.0])'], $this->attributesOf($this->code($old, 'Pet.php'), 'size'));
        self::assertSame([], $this->messages($old));
    }

    public function testTypesThroughAnAllOfBesideAnUntypedUnion(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'allOf' => [['$ref' => '#/components/schemas/Tags']], 'anyOf' => [['minItems' => 1], ['maxItems' => 9]]],
        ]], ['Tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 4]]]);

        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 4)])'], $this->attributesOf($code, 'tags'));
    }

    public function testFindsNoIntegerInAnOpenIntervalBetweenNeighbours(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'pick' => ['type' => 'integer', 'exclusiveMinimum' => 1, 'exclusiveMaximum' => 2],
            'gap' => ['type' => 'integer', 'minimum' => 1.2, 'maximum' => 1.8],
            'fit' => ['type' => 'integer', 'exclusiveMinimum' => 1.5, 'exclusiveMaximum' => 2.5],
            'real' => ['type' => 'number', 'exclusiveMinimum' => 1, 'exclusiveMaximum' => 2],
            'big' => ['type' => 'integer', 'minimum' => 9007199254740993, 'maximum' => 9007199254740992],
            'huge' => ['type' => 'number', 'minimum' => 9007199254740993, 'maximum' => 9007199254740992],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'pick'));
        self::assertSame([], $this->attributesOf($code, 'gap'));
        self::assertSame(['Assert\\GreaterThan(value: 1.5)', 'Assert\\LessThan(value: 2.5)'], $this->attributesOf($code, 'fit'));
        self::assertSame(['Assert\\GreaterThan(value: 1)', 'Assert\\LessThan(value: 2)'], $this->attributesOf($code, 'real'));
        self::assertSame([], $this->attributesOf($code, 'big'));
        self::assertSame([], $this->attributesOf($code, 'huge'));
        foreach (['pick' => 'exclusiveMinimum" and "exclusiveMaximum', 'gap' => 'minimum" and "maximum', 'big' => 'minimum" and "maximum', 'huge' => 'minimum" and "maximum'] as $property => $keywords) {
            self::assertContains(sprintf('warning /api.yaml#/components/schemas/Pet/properties/%s: "%s" leave no valid value; they are not checked.', $property, $keywords), $this->messages($output));
        }
    }

    public function testWarnsAboutAnExclusiveFlagWithoutGroups(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 3, 'x-validator-groups-exclusive' => 1],
        ]]);

        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/name: x-validator-groups-exclusive must be true or false; it is ignored.'], $this->messages($output));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     * @param array<string, mixed> $settings
     */
    private function pet(array $pet, array $others = [], string $php = '8.2', array $settings = [], ?string $validator = 'v7.1.0'): string
    {
        $output = $this->generate($pet, $others, $php, $settings, $validator);
        self::assertSame([], array_filter($this->messages($output), static fn (string $message): bool => strncmp($message, 'error', 5) === 0));

        return $this->code($output, 'Pet.php');
    }
}
