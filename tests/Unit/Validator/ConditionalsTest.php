<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * if, then and else, checked by IfThenElse of msstc4symfony/dto-generator-bridge-runtime.
 */
final class ConditionalsTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.1.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const RUNTIME_ASSERT = '\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\';

    private const AT = 'warning /api.yaml#/components/schemas/Pet';

    public function testChecksTheBranchesOfAString(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'phone' => ['type' => 'string', 'if' => ['pattern' => '^\\+'], 'then' => ['minLength' => 8], 'else' => ['maxLength' => 6]],
        ]]);

        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::ASSERT . "Regex(pattern: '/^\\+/uD')], then: [new " . self::ASSERT . 'Length(min: 8)], else: [new ' . self::ASSERT . 'Length(max: 6)])'],
            $this->attributesOf($this->code($output, 'Pet.php'), 'phone'),
        );
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesOutABranchThatIsMissing(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'code' => ['type' => 'integer', 'if' => ['minimum' => 10], 'else' => ['const' => 0]],
        ]]), 'Pet.php');

        self::assertSame(['RuntimeAssert\\IfThenElse(if: [new ' . self::ASSERT . 'GreaterThanOrEqual(value: 10)], else: [new ' . self::ASSERT . 'IdenticalTo(value: 0)])'], $this->attributesOf($code, 'code'));
    }

    public function testMarksKeywordsWithoutEffectAsChecked(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'alone' => ['type' => 'string', 'if' => ['const' => 'x']],
            'stray' => ['type' => 'string', 'then' => ['const' => 'y'], 'else' => ['const' => 'z']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'alone'));
        self::assertSame([], $this->attributesOf($code, 'stray'));
        self::assertSame([], $this->messages($output));
    }

    public function testNarrowsAValueOfAnyTypeByTheTypeOfABranch(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'value' => ['if' => ['type' => 'string'], 'then' => ['type' => 'string', 'minLength' => 2], 'else' => ['type' => ['integer', 'null'], 'minimum' => 0]],
        ]]);

        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::ASSERT . "Type(type: 'string')], then: [new " . self::ASSERT . "Type(type: 'string'), new " . self::ASSERT . 'Length(min: 2)], else: [new ' . self::ASSERT . "Type(type: 'int'), new " . self::ASSERT . 'GreaterThanOrEqual(value: 0)])'],
            $this->attributesOf($this->code($output, 'Pet.php'), 'value'),
        );
        self::assertSame([], $this->messages($output));
    }

    public function testChecksTheBranchesOfTheElementsOfAList(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'codes' => ['type' => 'array', 'x-validator-groups' => ['api'], 'items' => ['type' => 'string', 'if' => ['pattern' => '^x'], 'then' => ['maxLength' => 3]]],
        ]]), 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(
            ['Assert\\All(constraints: [new ' . self::RUNTIME_ASSERT . 'IfThenElse(if: [new ' . self::ASSERT . "Regex(pattern: '/^x/uD')], then: [new " . self::ASSERT . "Length(max: 3)], $groups)], $groups)"],
            $this->attributesOf($code, 'codes'),
        );
    }

    public function testWarnsAboutBranchesOfAValueItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'if' => ['minItems' => 1], 'then' => ['maxItems' => 3]],
            'name' => ['type' => 'string', 'if' => ['const' => 'a'], 'then' => ['items' => ['type' => 'string']]],
            'any' => ['if' => ['minLength' => 1], 'then' => ['const' => 'a']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'tags'));
        self::assertSame([], $this->attributesOf($code, 'name'));
        self::assertSame([], $this->attributesOf($code, 'any'));
        $at = self::AT . '/properties/';
        self::assertSame([
            $at . 'tags: "if" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.',
            $at . 'name: "then" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "items" it is not checked.',
            $at . 'any: "if" is checked on a value of any type only with one "type" of string, integer, number or boolean beside the keywords of strings and numbers; it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksConditionsOnTheClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'country' => ['type' => 'string'],
            'zip' => ['type' => 'string', 'x-php-name' => 'postcode'],
        ], 'if' => ['properties' => ['country' => ['const' => 'US']], 'required' => ['country']], 'then' => ['required' => ['zip'], 'properties' => ['zip' => ['pattern' => '^\\d{5}$']]], 'else' => ['properties' => ['zip' => ['maxLength' => 10]]]]);

        self::assertSame([
            'RuntimeAssert\\IfThenElse('
            . 'if: [new ' . self::RUNTIME_ASSERT . "Property(name: 'country', constraints: [new " . self::ASSERT . "IdenticalTo(value: 'US')]), new " . self::RUNTIME_ASSERT . "Present(properties: ['country'])], "
            . 'then: [new ' . self::RUNTIME_ASSERT . "Property(name: 'postcode', constraints: [new " . self::ASSERT . "Regex(pattern: '/^\\d{5}\$/uD')]), new " . self::RUNTIME_ASSERT . "Present(properties: ['postcode'])], "
            . 'else: [new ' . self::RUNTIME_ASSERT . "Property(name: 'postcode', constraints: [new " . self::ASSERT . 'Length(max: 10)])])',
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testComparesAPhpEnumWithItsCases(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['card', 'cash', 'bank']],
            'card' => ['type' => 'string'],
        ], 'if' => ['properties' => ['kind' => ['enum' => ['card', 'bank']]]], 'then' => ['required' => ['card']], 'else' => ['properties' => ['kind' => ['const' => 'cash']]]]), 'Pet.php');

        self::assertSame([
            'RuntimeAssert\\IfThenElse('
            . 'if: [new ' . self::RUNTIME_ASSERT . "Property(name: 'kind', constraints: [new " . self::ASSERT . 'Choice(choices: [PetKind::CARD, PetKind::BANK])])], '
            . 'then: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['card'])], "
            . 'else: [new ' . self::RUNTIME_ASSERT . "Property(name: 'kind', constraints: [new " . self::ASSERT . 'IdenticalTo(value: PetKind::CASH)])])',
        ], $this->classAttributesOf($code));
    }

    public function testChecksEveryConditionOfTheClassAndItsBranches(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'allOf' => [
            ['if' => ['required' => ['a']], 'then' => ['required' => ['b']]],
            ['if' => ['required' => ['b']], 'then' => ['required' => ['a']]],
        ], 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]]), 'Pet.php');

        self::assertSame([
            'RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a'])], then: [new " . self::RUNTIME_ASSERT . "Present(properties: ['b'])])",
            'RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['b'])], then: [new " . self::RUNTIME_ASSERT . "Present(properties: ['a'])])",
        ], $this->classAttributesOf($code));
    }

    public function testWarnsAboutAConditionOnAPropertyWithADefault(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'country' => ['type' => 'string', 'default' => 'US'],
            'zip' => ['type' => 'string', 'default' => '00000'],
            'name' => ['type' => 'string'],
        ], 'allOf' => [
            ['if' => ['required' => ['country']], 'then' => ['required' => ['zip']]],
            ['if' => ['required' => ['zip']], 'then' => ['properties' => ['country' => ['const' => 'US']]]],
            ['if' => ['required' => ['name']], 'then' => ['properties' => ['zip' => ['minLength' => 1]]]],
        ]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([
            self::AT . '/allOf/0: "if" names "country", which has a default and is always set; the condition is not checked.',
            self::AT . '/allOf/1: "if" names "zip", which has a default and is always set; the condition is not checked.',
            self::AT . '/allOf/2: "then" names "zip", which has a default and is always set; the condition is not checked.',
        ], $this->messages($output));
    }

    public function testWarnsAboutAConditionItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], 'allOf' => [
            ['if' => ['required' => ['name', 'nick']], 'then' => ['required' => ['name']]],
            ['if' => ['properties' => ['tags' => ['minItems' => 1]]], 'then' => ['required' => ['name']]],
            ['if' => ['minProperties' => 1], 'then' => ['required' => ['name']]],
            ['if' => ['required' => 'name'], 'then' => ['required' => ['name']]],
            ['if' => ['properties' => ['name' => false]], 'then' => ['required' => ['name']]],
            ['if' => false, 'then' => ['required' => ['name']]],
            ['if' => ['properties' => ['7' => ['const' => 1]]], 'then' => ['required' => ['name']]],
            ['if' => ['required' => ['name']], 'then' => ['a', 'b']],
            ['if' => ['required' => ['name']], 'else' => ['5' => 1]],
            ['if' => ['not' => ['required' => ['name']]], 'then' => ['required' => ['name']]],
            ['if' => ['required' => ['name', 1]], 'then' => ['required' => ['name']]],
            ['if' => ['required' => ['name']], 'then' => ['properties' => ['tags' => ['minItems' => 2]]]],
            ['if' => ['required' => ['name']], 'else' => ['properties' => ['tags' => ['maxItems' => 2]]]],
            ['if' => ['properties' => [['const' => 1]]], 'then' => ['required' => ['name']]],
        ]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([
            self::AT . '/allOf/0: "if" names "nick", which is not a property of this class; the condition is not checked.',
            self::AT . '/allOf/1: "if" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.',
            self::AT . '/allOf/2: "if" is checked on a class only with "properties" and "required"; with "minProperties" it is not checked.',
            self::AT . '/allOf/3: "if" is checked on a class only with "properties" and "required"; with "required" it is not checked.',
            self::AT . '/allOf/4: "if" is checked only with a schema object or true; it is not checked.',
            self::AT . '/allOf/5: "if" is checked on a class only with a schema object or true; it is not checked.',
            self::AT . '/allOf/6: "if" names "7", which is not a property of this class; the condition is not checked.',
            self::AT . '/allOf/7: "then" is checked on a class only with a schema object or true; it is not checked.',
            self::AT . '/allOf/8: "else" is checked on a class only with "properties" and "required"; with "5" it is not checked.',
            self::AT . '/allOf/9: "if" is checked on a class only with "properties" and "required"; with "not" it is not checked.',
            self::AT . '/allOf/10: "if" is checked on a class only with "properties" and "required"; with "required" it is not checked.',
            self::AT . '/allOf/11: "then" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.',
            self::AT . '/allOf/12: "else" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.',
            self::AT . '/allOf/13: "if" is checked on a class only with "properties" and "required"; with "properties" it is not checked.',
        ], $this->messages($output));
    }

    public function testLeavesTheConditionOfAParentToIt(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            ['if' => ['required' => ['a']], 'then' => ['required' => ['b']]],
            ['if' => ['required' => ['zzz']], 'then' => ['required' => ['b']]],
            ['if' => ['properties' => ['7' => ['const' => 1]]], 'then' => ['required' => ['b']]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'if' => ['required' => ['a']], 'then' => ['properties' => ['a' => ['minLength' => 2]]]]]);

        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a'])], then: [new " . self::RUNTIME_ASSERT . "Property(name: 'a', constraints: [new " . self::ASSERT . 'Length(min: 2)])])'],
            $this->classAttributesOf($this->code($output, 'Base.php')),
        );
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(
            [
                self::AT . '/allOf/2: "if" names "a", which this class inherits; the condition is not checked: write it in the schema that declares its properties.',
                self::AT . '/allOf/3: "if" names "zzz", which is not a property of this class; the condition is not checked.',
                self::AT . '/allOf/4: "if" names "7", which is not a property of this class; the condition is not checked.',
            ],
            $this->messages($output),
        );
    }

    public function testGroupsTheConditionAsItsClass(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'x-validator-groups' => ['api'], 'properties' => ['a' => ['type' => 'string']], 'if' => ['properties' => ['a' => ['const' => 'x']]], 'then' => ['required' => ['a']]]), 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Property(name: 'a', constraints: [new " . self::ASSERT . "IdenticalTo(value: 'x')], $groups)], then: [new " . self::RUNTIME_ASSERT . "Present(properties: ['a'])], $groups)"],
            $this->classAttributesOf($code),
        );
    }

    public function testNeedsTheRuntime(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'if' => ['const' => 'x'], 'then' => ['minLength' => 2]],
        ], 'if' => ['required' => ['a']], 'then' => ['required' => ['a']]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'a'));
        self::assertSame([
            self::AT . ': "if" is checked by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.',
            self::AT . '/properties/a: "if" is checked by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.',
        ], $this->messages($output));
    }

    public function testNeedsNewInInitializers(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'if' => ['const' => 'x'], 'then' => ['minLength' => 2]],
        ], 'if' => ['required' => ['a']], 'then' => ['required' => ['a']]], [], '8.0');

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'a'));
        self::assertSame([
            self::AT . ': "if" goes into the attribute as "new", which PHP 8.0 does not allow; it is not checked.',
            self::AT . '/properties/a: "if" goes into the attribute as "new", which PHP 8.0 does not allow; it is not checked.',
        ], $this->messages($output));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'if' => ['const' => 'x'], 'then' => ['minLength' => 2]],
        ], 'if' => ['properties' => ['a' => ['const' => 'y']]], 'then' => ['required' => ['a']]], [], '7.4', [], 'v6.4.1', self::RUNTIME + ['doctrine/annotations' => '2.0.2']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString(' * @RuntimeAssert\\IfThenElse(', $code);
        self::assertStringContainsString('@\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\Property(', $code);
        self::assertStringContainsString('@\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\Present(properties={"a"})', $code);
        self::assertStringContainsString('     * @RuntimeAssert\\IfThenElse(', $code);
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsAboutAConditionInAMemberOfAUnion(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'value' => ['oneOf' => [['type' => 'string', 'if' => ['const' => 'a'], 'then' => ['minLength' => 2]], ['type' => 'integer']]],
        ]]);

        self::assertSame([
            self::AT . '/properties/value: "if" in a member of "oneOf" or "anyOf" is not checked.',
            self::AT . '/properties/value: "then" in a member of "oneOf" or "anyOf" is not checked.',
        ], $this->messages($output));
    }

    public function testReadsEmptyBranchesAsSchemasOfNothing(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'if' => ['properties' => []], 'then' => ['required' => ['a', 'a']], 'else' => []]);

        self::assertSame(['RuntimeAssert\\IfThenElse(then: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a'])])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesTheConditionsOfAClassToTheClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['address' => ['$ref' => '#/components/schemas/Address']]], [
            'Address' => ['type' => 'object', 'properties' => ['zip' => ['type' => 'string']], 'if' => ['required' => ['zip']], 'then' => ['properties' => ['zip' => ['minLength' => 5]]]],
        ]);

        self::assertSame(['Assert\\Valid'], $this->attributesOf($this->code($output, 'Pet.php'), 'address'));
        self::assertCount(1, $this->classAttributesOf($this->code($output, 'Address.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksEveryConditionOfAValue(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'code' => ['type' => 'string', 'allOf' => [
                ['if' => ['items' => true], 'then' => ['minLength' => 1]],
                ['if' => ['pattern' => '^a'], 'then' => ['minLength' => 2]],
                ['if' => ['pattern' => '^b'], 'then' => ['minLength' => 3]],
            ]],
        ]]);

        self::assertSame([
            'RuntimeAssert\\IfThenElse(if: [new ' . self::ASSERT . "Regex(pattern: '/^a/uD')], then: [new " . self::ASSERT . 'Length(min: 2)])',
            'RuntimeAssert\\IfThenElse(if: [new ' . self::ASSERT . "Regex(pattern: '/^b/uD')], then: [new " . self::ASSERT . 'Length(min: 3)])',
        ], $this->attributesOf($this->code($output, 'Pet.php'), 'code'));
        self::assertSame([self::AT . '/properties/code/allOf/0: "if" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "items" it is not checked.'], $this->messages($output));
    }

    public function testChecksTheTypesOfAValueOfAnyType(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'amount' => ['if' => ['type' => ['integer', 'number']], 'then' => ['type' => 'integer', 'format' => 'int32']],
            'shape' => ['if' => ['type' => 'object'], 'then' => ['const' => 1]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::ASSERT . "Type(type: ['int', 'float'])], then: [new " . self::ASSERT . "Type(type: 'int'), new " . self::ASSERT . 'Range(min: -2147483648, max: 2147483647)])'],
            $this->attributesOf($code, 'amount'),
        );
        self::assertSame([], $this->attributesOf($code, 'shape'));
        self::assertSame([self::AT . '/properties/shape: "if" is checked on a value of any type only with one "type" of string, integer, number or boolean beside the keywords of strings and numbers; it is not checked.'], $this->messages($output));
    }

    public function testWarnsAboutKeywordsOfOneKindOnAPhpEnum(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['card', 'cash']],
        ], 'if' => ['properties' => ['kind' => ['const' => 'card', 'minLength' => 1]]], 'then' => ['required' => ['kind']]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::AT . ': "if" is checked on a value of an enum type only with "type", "const" and "enum"; with "minLength" it is not checked.'], $this->messages($output));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     */
    private function withRuntime(array $pet, array $others = [], string $php = '8.2'): Output
    {
        return $this->generate($pet, $others, $php, [], 'v7.1.0', self::RUNTIME);
    }
}
