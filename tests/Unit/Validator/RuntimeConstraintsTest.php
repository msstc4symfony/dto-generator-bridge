<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * dependentRequired, propertyNames and contains, checked by the constraints of msstc4symfony/dto-generator-bridge-runtime.
 */
final class RuntimeConstraintsTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.1.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    public function testChecksContainsWithItsSchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'admin']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("use Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints as RuntimeAssert;\n", $code);
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'admin')])"], $this->attributesOf($code, 'roles'));
        self::assertSame([], $this->messages($output));
    }

    public function testCountsMatchesBetweenMinContainsAndMaxContains(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'scores' => ['type' => 'array', 'items' => ['type' => 'integer'], 'contains' => ['minimum' => 90], 'minContains' => 2, 'maxContains' => 3],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['pattern' => '^a'], 'maxContains' => 1],
        ]]), 'Pet.php');

        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'GreaterThanOrEqual(value: 90)], min: 2, max: 3)'], $this->attributesOf($code, 'scores'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "Regex(pattern: '/^a/uD')], max: 1)"], $this->attributesOf($code, 'tags'));
    }

    public function testLeavesOutAContainsThatAnyListPasses(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a'], 'minContains' => 0],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string'], 'minContains' => 2],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'tags'));
        self::assertSame([], $this->attributesOf($code, 'notes'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/notes: "minContains" has no effect without "contains" in the same schema.'], $this->messages($output));
    }

    public function testMatchesEveryElementWithAnEmptySchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => true],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['description' => 'Any note.', 'x-note' => 1], 'minContains' => 2],
        ]], [], '8.0');
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['RuntimeAssert\\Contains'], $this->attributesOf($code, 'tags'));
        self::assertSame(['RuntimeAssert\\Contains(min: 2)'], $this->attributesOf($code, 'notes'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksContainsOnTheElementsOfAList(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'x']]],
        ]]), 'Pet.php');

        self::assertSame(
            ['Assert\\All(constraints: [new \\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'x')])])"],
            $this->attributesOf($code, 'rows'),
        );
    }

    public function testChecksContainsThroughReferencesAndAllOf(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'roles' => ['$ref' => '#/components/schemas/Roles'],
            'tags' => ['allOf' => [['type' => 'array', 'items' => ['type' => 'string']], ['contains' => ['const' => 'a']], ['contains' => ['const' => 'b']]]],
        ]], ['Roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['enum' => ['admin', 'owner']]]]), 'Pet.php');

        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "Choice(choices: ['admin', 'owner'])])"], $this->attributesOf($code, 'roles'));
        self::assertSame([
            'RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'a')])",
            'RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'b')])",
        ], $this->attributesOf($code, 'tags'));
    }

    public function testWarnsAboutAContainsSchemaItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['$ref' => '#/components/schemas/Role']],
            'pets' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['properties' => ['a' => ['type' => 'string']]]],
            'never' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => false],
            'enums' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['enum' => []]],
            'kinds' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'integer']],
            'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'object']],
            'mixed' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 7]],
            'pairs' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']], 'contains' => ['type' => 'array']],
            'listed' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['a']],
            'numbered' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => [1 => 'a']],
            'marked' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['xml' => ['name' => 'a']]],
            'counted' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'number']],
        ]], ['Role' => ['type' => 'string']]);
        $code = $this->code($output, 'Pet.php');

        foreach (['roles', 'pets', 'never', 'enums', 'kinds', 'names', 'mixed', 'pairs', 'listed', 'numbered', 'marked', 'counted'] as $property) {
            self::assertSame([], $this->attributesOf($code, $property), $property);
        }

        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        self::assertSame([
            $at . 'roles: "contains" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "$ref" it is not checked.',
            $at . 'pets: "contains" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "properties" it is not checked.',
            $at . 'never: "contains" is checked only with a schema object or true; it is not checked.',
            $at . 'enums: "contains" is checked only with a non-empty list in "enum"; it is not checked.',
            $at . 'kinds: "contains" is checked only when its "type" admits every element; it is not checked.',
            $at . 'names: "contains" is checked only when its "type" admits every element; it is not checked.',
            $at . 'mixed: "contains" is checked only when its "type" admits every element; it is not checked.',
            $at . 'pairs: "contains" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.',
            $at . 'listed: "contains" is checked only with a schema object or true; it is not checked.',
            $at . 'numbered: "contains" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "1" it is not checked.',
            $at . 'marked: "contains" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "xml" it is not checked.',
            $at . 'counted: "contains" is checked only when its "type" admits every element; it is not checked.',
        ], $this->messages($output));
    }

    public function testAcceptsATypeThatAdmitsEveryElement(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'counts' => ['type' => 'array', 'items' => ['type' => 'integer'], 'contains' => ['type' => 'number', 'minimum' => 1]],
            'flags' => ['type' => 'array', 'items' => ['type' => ['boolean', 'null']], 'contains' => ['type' => ['null', 'boolean'], 'const' => true]],
            'nulls' => ['type' => 'array', 'items' => ['type' => ['string', 'null']], 'contains' => ['type' => 'string']],
            'anything' => ['type' => 'array', 'items' => ['type' => ['string', 'null']], 'contains' => true],
            'picks' => ['type' => 'array', 'items' => ['type' => ['string', 'null']], 'contains' => ['enum' => ['a']]],
            'loose' => ['type' => 'array', 'items' => ['type' => ['string', 'null']], 'contains' => ['type' => ['string', 'null'], 'enum' => ['ab', null], 'minLength' => 2]],
            'sizes' => ['type' => 'array', 'items' => ['type' => 'number'], 'contains' => ['type' => 'number', 'maximum' => 2.5]],
            'words' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'string', 'format' => 'email']],
        ]]), 'Pet.php');

        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'GreaterThanOrEqual(value: 1)])'], $this->attributesOf($code, 'counts'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . 'IdenticalTo(value: true)])'], $this->attributesOf($code, 'flags'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'NotNull()])'], $this->attributesOf($code, 'nulls'));
        self::assertSame(['RuntimeAssert\\Contains'], $this->attributesOf($code, 'anything'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Choice(choices: ['a'])])"], $this->attributesOf($code, 'picks'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'Length(min: 2), new ' . self::ASSERT . "Choice(choices: ['ab'])])"], $this->attributesOf($code, 'loose'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'LessThanOrEqual(value: 2.5)])'], $this->attributesOf($code, 'sizes'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "Email(mode: 'html5')])"], $this->attributesOf($code, 'words'));
    }

    public function testLeavesOutAContainsWhoseSchemaIsCheckedInPart(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'mixed' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'contains' => ['minLength' => 3], 'minContains' => 0, 'maxContains' => 1],
            'odd' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['pattern' => 'a\\u12']],
            'states' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/State'], 'contains' => ['enum' => ['on']]],
        ]], ['State' => ['type' => 'string', 'enum' => ['on', 'off']]]);
        $code = $this->code($output, 'Pet.php');

        foreach (['mixed', 'odd'] as $property) {
            self::assertSame([], $this->attributesOf($code, $property), $property);
        }

        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'Choice(choices: [State::ON])])'], $this->attributesOf($code, 'states'));
        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        self::assertSame([
            $at . 'mixed: "contains" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.',
            $at . 'odd/contains: "pattern" is no regular expression PHP can run; it is not checked.',
            $at . 'odd: "contains" is not checked, as part of its schema cannot be.',
        ], $this->messages($output));
    }

    public function testCountsObjectsAgainstASchemaOfAnnotationsOnly(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'friends' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Friend'], 'contains' => ['description' => 'Any friend.'], 'minContains' => 2],
        ]], ['Friend' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]), 'Pet.php');

        self::assertSame(['RuntimeAssert\\Contains(min: 2)', 'Assert\\Valid'], $this->attributesOf($code, 'friends'));
    }

    public function testWarnsAboutAMemberOfAUnionThroughEveryProperty(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['$ref' => '#/components/schemas/Tags'],
            'either' => ['oneOf' => [['$ref' => '#/components/schemas/Tags'], ['type' => 'string']]],
            'owners' => ['anyOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'string']]],
            'mixed' => ['anyOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['maxLength' => 2]]]],
            'wrapped' => ['oneOf' => [['allOf' => [['$ref' => '#/components/schemas/Tags']]], ['type' => 'string']]],
            'nested' => ['oneOf' => [['oneOf' => [['$ref' => '#/components/schemas/Tags'], ['type' => 'integer']]], ['type' => 'string']]],
            'both' => ['oneOf' => [['anyOf' => [['$ref' => '#/components/schemas/Owner'], ['$ref' => '#/components/schemas/Tags']]], ['type' => 'string']]],
            'linked' => ['oneOf' => [['$ref' => '#/components/schemas/Linked'], ['type' => 'string']]],
            'repeated' => ['oneOf' => [['$ref' => '#/components/schemas/Bounded'], ['$ref' => '#/components/schemas/Bounded'], ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['maxLength' => 1]]]],
            'joined' => ['oneOf' => [['allOf' => [['$ref' => '#/components/schemas/Owner'], ['$ref' => '#/components/schemas/Picks']]], ['type' => 'string']]],
        ]], [
            'Picks' => ['propertyNames' => ['maxLength' => 2]],

            'Linked' => ['$ref' => '#/components/schemas/Bounded', 'minContains' => 2],
            'Bounded' => ['type' => 'array', 'items' => ['type' => 'string']],
            'Tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a']],
            'Owner' => ['type' => 'object', 'dependentRequired' => ['a' => ['b']], 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]],
        ]);

        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'a')])"], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['a' => ['b']])"], $this->classAttributesOf($this->code($output, 'Owner.php')));
        $member = '"%s" in a member of "oneOf" or "anyOf" is not checked.';
        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        self::assertSame([
            $at . 'either: ' . sprintf($member, 'contains'),
            $at . 'mixed: ' . sprintf($member, 'propertyNames'),
            $at . 'wrapped: ' . sprintf($member, 'contains'),
            $at . 'nested: ' . sprintf($member, 'contains'),
            $at . 'both: ' . sprintf($member, 'contains'),
            $at . 'linked: ' . sprintf($member, 'minContains'),
            $at . 'repeated: ' . sprintf($member, 'propertyNames'),
            $at . 'joined/oneOf/0: "propertyNames" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; it is not checked.',
        ], $this->messages($output));
    }

    /**
     * @dataProvider loops
     *
     * @param array<string, mixed> $others
     * @param list<string> $messages
     */
    public function testEndsTheWalkOfALoopingUnion(array $others, array $messages): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['p' => ['$ref' => '#/components/schemas/A']]], $others);

        self::assertSame($messages, $this->messages($output));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function loops(): iterable
    {
        $at = '/api.yaml#/components/schemas/';
        yield 'through itself' => [
            ['A' => ['oneOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'string']]]],
            ['error ' . $at . 'A: The $ref chain loops back to itself without reaching an object schema.'],
        ];
        yield 'through another union' => [
            [
                'A' => ['oneOf' => [['$ref' => '#/components/schemas/B'], ['type' => 'string']]],
                'B' => ['oneOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'integer']]],
            ],
            ['error ' . $at . 'A: The $ref chain loops back to itself without reaching an object schema.'],
        ];
        yield 'through a class, whose keywords are its own' => [
            [
                'A' => ['oneOf' => [['$ref' => '#/components/schemas/B'], ['type' => 'string']]],
                'B' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'oneOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a']]], 'contains' => ['const' => 1]],
            ],
            [
                'warning ' . $at . 'B: "oneOf" and "anyOf" beside "properties" are not represented; the class keeps only its properties.',
                'warning ' . $at . 'B/oneOf/1/contains: "contains" is not supported and has no effect on the generated type.',
                'warning ' . $at . 'B/contains: "contains" is not supported and has no effect on the generated type.',
            ],
        ];
    }

    public function testWarnsAtTheItemsOfAList(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array', 'items' => ['oneOf' => [['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a']], ['type' => 'string']]]],
        ]]);

        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/rows/items: "contains" in a member of "oneOf" or "anyOf" is not checked.'], $this->messages($output));
    }

    public function testWarnsOnceAboutAStrayBoundOfASharedComponent(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['$ref' => '#/components/schemas/Tags'],
            'labels' => ['$ref' => '#/components/schemas/Tags'],
        ]], ['Tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxContains' => 1]]);

        self::assertSame(['warning /api.yaml#/components/schemas/Tags: "maxContains" has no effect without "contains" in the same schema.'], $this->messages($output));
    }

    public function testBoundsEachContainsByItsOwnSchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['allOf' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a'], 'minContains' => 2],
                ['contains' => ['const' => 'b']],
            ]],
            'roles' => ['$ref' => '#/components/schemas/Roles', 'maxContains' => 1],
        ]], ['Roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'admin']]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/roles: "maxContains" has no effect without "contains" in the same schema.'], $this->messages($output));
        self::assertSame([
            'RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'a')], min: 2)",
            'RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'b')])",
        ], $this->attributesOf($code, 'tags'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'admin')])"], $this->attributesOf($code, 'roles'));
    }

    public function testWarnsAboutMinContainsAboveMaxContains(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a'], 'minContains' => 3, 'maxContains' => 2],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a'], 'minContains' => -1],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'tags'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'a')])"], $this->attributesOf($code, 'notes'));
        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        self::assertSame([
            $at . 'tags: "minContains" is above "maxContains", so no value is valid; they are not checked.',
            $at . 'notes: "minContains" must be a non-negative integer; it is not checked.',
        ], $this->messages($output));
    }

    public function testWarnsAboutContainsOnAnotherValue(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'contains' => ['const' => 'a']],
        ]]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'name'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/name: "contains" is checked on arrays only; it is not checked here.'], $this->messages($output));
    }

    public function testNeedsNewInInitializersForNestedConstraints(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'admin']],
            'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['maxLength' => 3]],
        ]], [], '8.0');
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'roles'));
        self::assertSame([], $this->attributesOf($code, 'labels'));
        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        self::assertSame([
            $at . 'roles: "contains" goes into the attribute as "new", which PHP 8.0 does not allow; it is not checked.',
            $at . 'labels: "propertyNames" goes into the attribute as "new", which PHP 8.0 does not allow; it is not checked.',
        ], $this->messages($output));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'dependentRequired' => ['card' => ['holder']], 'properties' => [
            'card' => ['type' => 'string'],
            'holder' => ['type' => 'string'],
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'admin'], 'minContains' => 2],
        ]], [], '7.4', [], 'v6.4.1', self::RUNTIME + ['doctrine/annotations' => '2.0.2']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("use Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints as RuntimeAssert;\n", $code);
        self::assertStringContainsString(" * @RuntimeAssert\\DependentRequired(requirements={\"card\"={\"holder\"}})\n", $code);
        self::assertStringContainsString(
            "     * @RuntimeAssert\\Contains(\n     *     constraints={@\\Symfony\\Component\\Validator\\Constraints\\IdenticalTo(value=\"admin\")},\n     *     min=2\n     * )\n",
            $code,
        );
        self::assertSame([], $this->messages($output));
    }

    public function testChecksTheNamesOfAMap(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['pattern' => '^x-', 'maxLength' => 10]],
            'codes' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'propertyNames' => ['type' => 'string', 'enum' => ['a', 'b']]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . 'Length(max: 10), new ' . self::ASSERT . "Regex(pattern: '/^x-/uD')])"], $this->attributesOf($code, 'labels'));
        self::assertSame(['RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . "Choice(choices: ['a', 'b'])])"], $this->attributesOf($code, 'codes'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksThePropertyNamesOfEveryBranch(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'labels' => ['allOf' => [
                ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['maxLength' => 3]],
                ['propertyNames' => ['pattern' => '^a']],
            ]],
        ]]), 'Pet.php');

        self::assertSame([
            'RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . 'Length(max: 3)])',
            'RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . "Regex(pattern: '/^a/uD')])",
        ], $this->attributesOf($code, 'labels'));
    }

    public function testChecksTheNamesOfTheUndeclaredPropertiesOfAClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'propertyNames' => ['pattern' => '^[a-z]+$'], 'additionalProperties' => ['type' => 'string', 'maxLength' => 5], 'properties' => [
            'name' => ['type' => 'string'],
            'tag' => ['type' => 'object', 'propertyNames' => ['maxLength' => 2], 'additionalProperties' => ['type' => 'integer'], 'properties' => ['id' => ['type' => 'integer']]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([
            'Assert\\All(constraints: [new ' . self::ASSERT . 'Length(max: 5)])',
            'RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . "Regex(pattern: '/^[a-z]+\$/uD')])",
        ], $this->attributesOf($code, 'additionalProperties'));
        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'tag'));
        self::assertSame(['RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . 'Length(max: 2)])'], $this->attributesOf($this->code($output, 'PetTag.php'), 'additionalProperties'));
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsAboutTheNamesOfAClassWithoutUndeclaredProperties(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'propertyNames' => ['pattern' => '^[a-z]+$'], 'properties' => ['name' => ['type' => 'string']]], [
            'Closed' => ['type' => 'object', 'additionalProperties' => false, 'propertyNames' => ['pattern' => '^[a-z]+$'], 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'name'));
        self::assertSame(
            ['warning /api.yaml#/components/schemas/Pet: "propertyNames" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; it is not checked.'],
            $this->messages($output),
        );
    }

    public function testGroupsTheNamesOfUndeclaredPropertiesAsTheirClass(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'x-validator-groups' => ['api'], 'propertyNames' => ['maxLength' => 2], 'additionalProperties' => [
            'type' => 'string', 'maxLength' => 5, 'x-validator-skip' => true,
        ], 'properties' => ['name' => ['type' => 'string']]]), 'Pet.php');

        self::assertSame(['RuntimeAssert\\Keys(constraints: [new ' . self::ASSERT . "Length(max: 2)], groups: ['api', 'Default'])"], $this->attributesOf($code, 'additionalProperties'));
    }

    public function testWarnsAboutPropertyNamesItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'propertyNames' => ['maxLength' => 2]],
            'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['type' => 'integer']],
            'codes' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['items' => ['type' => 'string']]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'name'));
        self::assertSame([], $this->attributesOf($code, 'labels'));
        self::assertSame([], $this->attributesOf($code, 'codes'));
        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        self::assertSame([
            $at . 'name: "propertyNames" is checked on maps and on the undeclared properties of a class only; it is not checked here.',
            $at . 'labels: "propertyNames" is checked only when its "type" admits every element; it is not checked.',
            $at . 'codes: "propertyNames" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "items" it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksDependentRequiredOnTheClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'dependentRequired' => ['credit_card' => ['holder', 'expiry'], 'holder' => [], 'expiry' => ['credit_card']], 'properties' => [
            'credit_card' => ['type' => 'string'],
            'holder' => ['type' => 'string', 'x-php-name' => 'owner'],
            'expiry' => ['type' => 'string'],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['creditCard' => ['owner', 'expiry'], 'expiry' => ['creditCard']])"], $this->classAttributesOf($code));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesOutATriggerWithADefault(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'dependentRequired' => ['notify' => ['email'], 'email' => ['notify'], 'mode' => ['email']], 'properties' => [
            'notify' => ['type' => 'boolean', 'default' => false],
            'mode' => ['type' => ['string', 'null'], 'default' => null],
            'email' => ['type' => 'string'],
        ]]);

        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['email' => ['notify'], 'mode' => ['email']])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet: "dependentRequired": "notify" needing "email" is not checked, as "notify" has a default and is always set.'], $this->messages($output));
    }

    public function testWarnsAboutANameTheClassDoesNotGenerate(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'dependentRequired' => ['a' => ['hidden']], 'properties' => [
            'a' => ['type' => 'string'],
            'hidden' => ['type' => 'string', 'x-php-skip' => true],
        ]], ['Dog' => ['allOf' => [
            ['$ref' => '#/components/schemas/Pet'],
            ['type' => 'object', 'dependentRequired' => ['bark' => ['typo']], 'properties' => ['bark' => ['type' => 'string']]],
        ]]]);

        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet: "dependentRequired" names "hidden", which is not a property of this class; "a" needing "hidden" is not checked.',
            'warning /api.yaml#/components/schemas/Dog: "dependentRequired" names "typo", which is not a property of this class; "bark" needing "typo" is not checked.',
        ], $this->messages($output));
    }

    public function testJoinsDependentRequiredOfEveryBranch(): void
    {
        $code = $this->code($this->withRuntime(['allOf' => [
            ['type' => 'object', 'dependentRequired' => ['a' => ['b']], 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]],
            ['type' => 'object', 'dependentRequired' => ['a' => ['b', 'c']], 'properties' => ['c' => ['type' => 'string']]],
        ]]), 'Pet.php');

        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['a' => ['b', 'c']])"], $this->classAttributesOf($code));
    }

    public function testReadsTheBranchesAfterAMalformedDependentRequired(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'dependentRequired' => ['a'], 'allOf' => [
            ['type' => 'object', 'dependentRequired' => ['200' => ['a']], 'properties' => ['a' => ['type' => 'string'], '200' => ['type' => 'string', 'x-php-name' => 'code']]],
        ]]);

        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['code' => ['a']])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet: "dependentRequired" must map property names to lists of names; it is not checked.'], $this->messages($output));
    }

    public function testWarnsAboutDependentRequiredItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'dependentRequired' => ['a' => ['b', 'zz'], 'yy' => ['a'], 'b' => 'a', 'c' => [1]], 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
            'c' => ['type' => 'string'],
            'map' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'dependentRequired' => ['a' => ['b']]],
            'any' => ['dependentRequired' => ['a' => ['b']]],
        ]], ['Other' => ['type' => 'object', 'dependentRequired' => ['a', 'b'], 'properties' => ['a' => ['type' => 'string']]]]);

        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['a' => ['b']])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Other.php')));
        $at = 'warning /api.yaml#/components/schemas/';
        self::assertSame([
            $at . 'Pet: "dependentRequired" names "zz", which is not a property of this class; "a" needing "zz" is not checked.',
            $at . 'Pet: "dependentRequired" names "yy", which is not a property of this class; "yy" needing "a" is not checked.',
            $at . 'Pet: "dependentRequired" must map property names to lists of names; "b" is not checked.',
            $at . 'Pet: "dependentRequired" must map property names to lists of names; "c" is not checked.',
            $at . 'Pet/properties/map: "dependentRequired" is checked on classes only; it is not checked here.',
            $at . 'Pet/properties/any: "dependentRequired" is checked on classes only; it is not checked here.',
            $at . 'Other: "dependentRequired" must map property names to lists of names; it is not checked.',
        ], $this->messages($output));
    }

    public function testLeavesThePairsOfInheritedPropertiesToTheParent(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Animal'],
            ['type' => 'object', 'dependentRequired' => ['name' => ['legs'], 'tail' => ['name'], 'legs' => ['name']], 'properties' => ['tail' => ['type' => 'string']]],
        ]], ['Animal' => ['type' => 'object', 'dependentRequired' => ['name' => ['legs']], 'properties' => ['name' => ['type' => 'string'], 'legs' => ['type' => 'integer']]]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['name' => ['legs']])"], $this->classAttributesOf($this->code($output, 'Animal.php')));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet: "dependentRequired": "tail" needing "name" is not checked, as the class inherits "name"; write the pair in the schema that declares both.',
            'warning /api.yaml#/components/schemas/Pet: "dependentRequired": "legs" needing "name" is not checked, as the class inherits "legs"; write the pair in the schema that declares both.',
        ], $this->messages($output));
    }

    public function testGroupsTheClassConstraint(): void
    {
        $grouped = $this->withRuntime(['type' => 'object', 'x-validator-groups' => ['api'], 'dependentRequired' => ['a' => ['b']], 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
        ]]);
        $skipped = $this->withRuntime(['type' => 'object', 'x-validator-skip' => true, 'dependentRequired' => ['a' => ['b']], 'propertyNames' => ['maxLength' => 2], 'additionalProperties' => ['type' => 'string'], 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
        ]], [], '8.2', ['groups' => ['all']]);

        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['a' => ['b']], groups: ['api', 'Default'])"], $this->classAttributesOf($this->code($grouped, 'Pet.php')));
        $code = $this->code($skipped, 'Pet.php');
        self::assertSame([], $this->classAttributesOf($code));
        self::assertSame([], $this->attributesOf($code, 'additionalProperties'));
    }

    /**
     * @dataProvider runtimes
     */
    public function testNeedsTheRuntime(?string $version): void
    {
        $output = $this->generate(['type' => 'object', 'dependentRequired' => ['a' => ['b']], 'additionalProperties' => ['type' => 'string'], 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'admin']],
            'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['maxLength' => 3]],
        ]], [], '8.2', [], 'v7.1.0', $version === null ? [] : ['msstc4symfony/dto-generator-bridge-runtime' => $version]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->classAttributesOf($code));
        self::assertSame([], $this->attributesOf($code, 'roles'));
        self::assertSame([], $this->attributesOf($code, 'labels'));
        $needs = 'is checked by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.';
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet: "dependentRequired" ' . $needs,
            'warning /api.yaml#/components/schemas/Pet/properties/roles: "contains" ' . $needs,
            'warning /api.yaml#/components/schemas/Pet/properties/labels: "propertyNames" ' . $needs,
        ], $this->messages($output));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function runtimes(): iterable
    {
        yield 'none' => [null];
        yield '1.0' => ['v1.0.0'];
        yield '0.9' => ['0.9.0'];
    }

    /**
     * @dataProvider newRuntimes
     */
    public function testTakesANewerOrDevelopmentRuntime(string $version): void
    {
        $output = $this->generate(['type' => 'object', 'dependentRequired' => ['a' => ['b']], 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
        ]], [], '8.2', [], 'v7.1.0', ['msstc4symfony/dto-generator-bridge-runtime' => $version]);

        self::assertSame(["RuntimeAssert\\DependentRequired(requirements: ['a' => ['b']])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function newRuntimes(): iterable
    {
        yield '1.1' => ['1.1.0'];
        yield '1.10' => ['v1.10.0'];
        yield '2.0' => ['2.0.0'];
        yield 'branch' => ['dev-main'];
        yield 'alias' => ['1.1.x-dev'];
        yield 'branch with a number' => ['dev-fix-1.0'];
    }

    public function testWritesNothingWithoutTheValidator(): void
    {
        $output = $this->generate(['type' => 'object', 'dependentRequired' => ['a' => ['b']], 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'admin']],
        ]], [], '8.2', [], null);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->classAttributesOf($code));
        self::assertSame([], $this->attributesOf($code, 'roles'));
        $unsupported = 'is not supported and has no effect on the generated type.';
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/dependentRequired: "dependentRequired" ' . $unsupported,
            'warning /api.yaml#/components/schemas/Pet/properties/roles/contains: "contains" ' . $unsupported,
        ], $this->messages($output));
    }

    public function testLeavesToTheGeneratorWhatItDoesNotReach(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'either' => ['oneOf' => [['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a']], ['type' => 'string']]],
            'owner' => ['$ref' => '#/components/schemas/Owner', 'dependentRequired' => ['a' => ['b']], 'propertyNames' => ['maxLength' => 1]],
            'skipped' => ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'a'], 'x-validator-skip' => true],
            'typed' => ['type' => 'array', 'x-php-type' => '\\ArrayObject', 'contains' => ['const' => 'a']],
        ]], ['Owner' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'owner'));
        $at = 'warning /api.yaml#/components/schemas/Pet/properties/';
        $unsupported = 'is not supported and has no effect on the generated type.';
        self::assertSame([
            $at . 'either: "contains" in a member of "oneOf" or "anyOf" is not checked.',
            $at . 'owner/dependentRequired: "dependentRequired" ' . $unsupported,
            $at . 'owner/propertyNames: "propertyNames" ' . $unsupported,
            $at . 'skipped/contains: "contains" ' . $unsupported,
        ], $this->messages($output));
    }

    public function testDropsASubschemaWhoseWarningRepeatsAnother(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['allOf' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['pattern' => 'a\\u12']],
                ['contains' => ['pattern' => 'a\\u12', 'minLength' => 2]],
            ]],
        ]]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
        $at = 'warning /api.yaml#/components/schemas/Pet/properties/tags/allOf/';
        self::assertSame([
            $at . '0/contains: "pattern" is no regular expression PHP can run; it is not checked.',
            $at . '0: "contains" is not checked, as part of its schema cannot be.',
            $at . '1/contains: "pattern" is no regular expression PHP can run; it is not checked.',
            $at . '1: "contains" is not checked, as part of its schema cannot be.',
        ], $this->messages($output));
    }

    public function testGroupsTheCompositesInsideAll(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array', 'x-validator-groups' => ['create'], 'items' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3], 'contains' => ['const' => 'x']]],
        ]]), 'Pet.php');

        $groups = "groups: ['create', 'Default']";
        self::assertSame(
            ['Assert\\All(constraints: [new \\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\Contains(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'x')], $groups), new " . self::ASSERT . 'All(constraints: [new ' . self::ASSERT . "Length(max: 3)], $groups)], $groups)"],
            $this->attributesOf($code, 'rows'),
        );
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     * @param array<string, mixed> $settings
     */
    private function withRuntime(array $pet, array $others = [], string $php = '8.2', array $settings = []): Output
    {
        return $this->generate($pet, $others, $php, $settings, 'v7.1.0', self::RUNTIME);
    }
}
