<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * not, checked by Not of msstc4symfony/dto-generator-bridge-runtime on values and classes.
 */
final class NegationsTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const RUNTIME_ASSERT = '\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\';

    private const AT = 'warning /api.yaml#/components/schemas/Pet';

    public function testRejectsTheValuesOfItsSchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'code' => ['type' => 'string', 'not' => ['enum' => ['TEST', 'DEMO']]],
            'size' => ['type' => 'integer', 'allOf' => [['not' => ['minimum' => 10, 'maximum' => 20]]]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . "Choice(choices: ['TEST', 'DEMO'])])"], $this->attributesOf($code, 'code'));
        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . 'Range(min: 10, max: 20)])'], $this->attributesOf($code, 'size'));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesASubschemaUncheckedWhoseFormatItDoesNotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'link' => ['type' => 'string', 'not' => ['format' => 'uri-reference']],
            'odd' => ['type' => 'string', 'not' => ['format' => 'ssh-key']],
            'mail' => ['type' => 'string', 'not' => ['format' => 'e-mail']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'link'));
        self::assertSame([], $this->attributesOf($code, 'odd'));
        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . "Email(mode: 'html5')])"], $this->attributesOf($code, 'mail'));
        self::assertSame([
            self::AT . '/properties/link/not: "format: uri-reference" is not checked.',
            self::AT . '/properties/link: "not" is not checked, as part of its schema cannot be.',
            self::AT . '/properties/odd/not: "format: ssh-key" is not checked.',
            self::AT . '/properties/odd: "not" is not checked, as part of its schema cannot be.',
        ], $this->messages($output));
    }

    public function testLeavesIfAndOneOfUncheckedAndOtherSubschemasCheckedWhereAFormatIsNot(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'pick' => ['type' => 'string', 'if' => ['format' => 'uri-reference'], 'then' => ['maxLength' => 5]],
            'then' => ['type' => 'string', 'if' => ['minLength' => 3], 'then' => ['format' => 'uri-reference', 'maxLength' => 9]],
            'one' => ['oneOf' => [['type' => 'string', 'format' => 'uri-reference'], ['type' => 'integer']]],
            'any' => ['anyOf' => [['type' => 'string', 'format' => 'uri-reference', 'maxLength' => 5], ['type' => 'integer', 'minimum' => 3]]],
            'names' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'propertyNames' => ['format' => 'uri-reference', 'maxLength' => 5]],
            'time' => ['type' => 'integer', 'not' => ['format' => 'unixtime']],
            'mail' => ['type' => 'integer', 'not' => ['format' => 'email']],
            'tiny' => ['type' => 'integer', 'not' => ['format' => 'int8']],
            'whole' => ['type' => 'integer', 'not' => ['format' => 'integer']],
            'amount' => ['type' => 'number', 'not' => ['format' => 'decimal']],
            'odd' => ['type' => 'boolean', 'not' => ['format' => 'ssh-key']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'pick'));
        self::assertStringContainsString('Length(max: 9)', implode(' ', $this->attributesOf($code, 'then')));
        self::assertSame([], $this->attributesOf($code, 'one'));
        self::assertStringContainsString('Length(max: 5)', implode(' ', $this->attributesOf($code, 'any')));
        self::assertStringContainsString('Length(max: 5)', implode(' ', $this->attributesOf($code, 'names')));
        self::assertSame([], $this->attributesOf($code, 'time'));
        self::assertSame(['RuntimeAssert\\Not'], $this->attributesOf($code, 'mail'));
        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . 'Range(min: -128, max: 127)])'], $this->attributesOf($code, 'tiny'));
        self::assertSame([
            self::AT . '/properties/pick/if: "format: uri-reference" is not checked.',
            self::AT . '/properties/pick: "if" is not checked, as part of its schema cannot be.',
            self::AT . '/properties/one/oneOf: "format: uri-reference" is not checked.',
            self::AT . '/properties/one: "oneOf" is not checked, as part of its schema cannot be.',
            self::AT . '/properties/time/not: "format: unix-time" is not checked.',
            self::AT . '/properties/time: "not" is not checked, as part of its schema cannot be.',
            self::AT . '/properties/whole/not: "format: integer" is not checked.',
            self::AT . '/properties/whole: "not" is not checked, as part of its schema cannot be.',
            self::AT . '/properties/amount/not: "format: decimal" is not checked.',
            self::AT . '/properties/amount: "not" is not checked, as part of its schema cannot be.',
            self::AT . '/properties/odd/not: "format: ssh-key" is not checked.',
            self::AT . '/properties/odd: "not" is not checked, as part of its schema cannot be.',
        ], $this->messages($output));
    }

    public function testRejectsEveryValueOfASchemaOfNothing(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'empty' => ['type' => 'string', 'not' => new stdClass()],
            'true' => ['type' => 'string', 'not' => true],
            'described' => ['type' => 'string', 'not' => ['description' => 'Gone.']],
            'false' => ['type' => 'string', 'not' => false],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['RuntimeAssert\\Not'], $this->attributesOf($code, 'empty'));
        self::assertSame(['RuntimeAssert\\Not'], $this->attributesOf($code, 'true'));
        self::assertSame(['RuntimeAssert\\Not'], $this->attributesOf($code, 'described'));
        self::assertSame([], $this->attributesOf($code, 'false'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksEveryNotOfAValue(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'code' => ['type' => ['string', 'null'], 'allOf' => [['not' => ['enum' => [null]]], ['not' => ['const' => 'x']], ['not' => ['const' => 'y']]]],
        ]]);

        self::assertSame([
            'RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'x')])",
            'RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'y')])",
        ], $this->attributesOf($this->code($output, 'Pet.php'), 'code'));
        self::assertSame([self::AT . '/properties/code/allOf/0: "not" of null is not checked: null stands for a missing value.'], $this->messages($output));
    }

    public function testChecksAValueOfAnyTypeByTheTypeOfItsSchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'value' => ['not' => ['type' => 'string']],
        ]]);

        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::ASSERT . "Type(type: 'string')])"], $this->attributesOf($this->code($output, 'Pet.php'), 'value'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksTheElementsOfAList(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'codes' => ['type' => 'array', 'x-validator-groups' => ['api'], 'items' => ['type' => 'string', 'not' => ['const' => 'x']]],
        ]]), 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(
            ['Assert\\All(constraints: [new ' . self::RUNTIME_ASSERT . 'Not(constraints: [new ' . self::ASSERT . "IdenticalTo(value: 'x')], $groups)], $groups)"],
            $this->attributesOf($code, 'codes'),
        );
    }

    public function testWarnsAboutASchemaItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'name' => ['type' => ['string', 'null'], 'not' => ['enum' => [null]]],
            'nil' => ['type' => ['string', 'null'], 'not' => ['type' => 'null']],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'not' => ['minItems' => 2]],
            'nick' => ['type' => 'string', 'not' => ['items' => ['type' => 'string']]],
            'any' => ['not' => ['minLength' => 1]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'name'));
        self::assertSame([
            self::AT . '/properties/name: "not" of null is not checked: null stands for a missing value.',
            self::AT . '/properties/nil: "not" is checked only when its "type" admits every element; it is not checked.',
            self::AT . '/properties/tags: "not" is checked only on values of a string, number, boolean or enum type, or of any type; it is not checked.',
            self::AT . '/properties/nick: "not" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "items" it is not checked.',
            self::AT . '/properties/any: "not" is checked on a value of any type only with one "type" of string, integer, number or boolean beside the keywords of strings and numbers; it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksNotOnTheClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'card' => ['type' => 'string'],
            'voucher' => ['type' => 'string', 'x-php-name' => 'code'],
            'kind' => ['type' => 'string'],
        ], 'allOf' => [
            ['not' => ['required' => ['card', 'voucher']]],
            ['not' => ['properties' => ['kind' => ['const' => 'test']], 'required' => ['kind']]],
            ['not' => false],
        ]]);

        self::assertSame([
            'RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['card', 'code'])])",
            'RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Property(name: 'kind', constraints: [new " . self::ASSERT . "IdenticalTo(value: 'test')]), new " . self::RUNTIME_ASSERT . "Present(properties: ['kind'])])",
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testRejectsEveryInstanceOfAClassWhoseNotAcceptsEveryObject(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'not' => ['type' => 'object']]);

        self::assertSame(['RuntimeAssert\\Not'], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsAboutANotOfTheClassItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'default' => 'x'],
            'b' => ['type' => 'string'],
        ], 'allOf' => [
            ['not' => ['required' => ['a']]],
            ['not' => ['properties' => ['a' => ['const' => 'y']]]],
            ['not' => ['required' => ['nick']]],
            ['not' => ['minProperties' => 1]],
            ['not' => ['a']],
        ]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([
            self::AT . '/allOf/0: "not" names "a", which has a default and is always set; it is not checked.',
            self::AT . '/allOf/1: "not" names "a", which has a default and is always set; it is not checked.',
            self::AT . '/allOf/2: "not" names "nick", which is not a property of this class; it is not checked.',
            self::AT . '/allOf/3: "not" is checked on a class only with "properties" and "required"; with "minProperties" it is not checked.',
            self::AT . '/allOf/4: "not" is checked on a class only with a schema object or true; it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksInheritedPropertiesAndLeavesTheNotOfAParentToIt(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            ['not' => ['required' => ['a', 'b']]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'not' => ['required' => ['a']]]]);

        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a'])])"], $this->classAttributesOf($this->code($output, 'Base.php')));
        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a', 'b'])])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesToTheParentANotTheChildRepeats(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            ['not' => ['required' => ['a']]],
            ['not' => ['required' => ['b']]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'not' => ['required' => ['a']]]]);

        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['b'])])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesToTheParentANotOfTheChildThatRejectsAlike(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'not' => true],
        ]], ['Base' => ['allOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], ['not' => ['type' => 'object']]]]]);

        self::assertSame(['RuntimeAssert\\Not'], $this->classAttributesOf($this->code($output, 'Base.php')));
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesWhatTheParentReachesToItsClass(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Middle'],
            ['type' => 'object', 'properties' => ['l' => ['type' => 'string']]],
            ['$ref' => '#/components/schemas/Alias'],
        ]], [
            'Base' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]],
            'Rules' => ['type' => 'object', 'not' => ['required' => ['a', 'b']]],
            'Middle' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['$ref' => '#/components/schemas/Rules'], ['type' => 'object', 'properties' => ['m' => ['type' => 'string']]]]],
            'Alias' => ['not' => ['required' => ['a', 'l']]],
        ]);

        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a', 'b'])])"], $this->classAttributesOf($this->code($output, 'Middle.php')));
        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a', 'l'])])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksTheNotOfAnInlineChildInsideItsParent(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string'],
            'child' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['c' => ['type' => 'string']], 'not' => ['required' => ['a', 'c']]]]],
        ]]);

        self::assertSame(['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a', 'c'])])"], $this->classAttributesOf($this->code($output, 'PetChild.php')));
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testLeavesTheNotOfASkippedParentUnmarked(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ]], ['Base' => ['type' => 'object', 'x-validator-skip' => true, 'properties' => ['a' => ['type' => 'string']], 'not' => ['required' => ['a']]]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(['warning /api.yaml#/components/schemas/Base/not: "not" is not supported and has no effect on the generated type.'], $this->messages($output));
    }

    public function testGroupsTheNotOfTheClassAsItsClass(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'x-validator-groups' => ['api'], 'properties' => ['a' => ['type' => 'string']], 'not' => ['properties' => ['a' => ['const' => 'x']]]]), 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(
            ['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Property(name: 'a', constraints: [new " . self::ASSERT . "IdenticalTo(value: 'x')], $groups)], $groups)"],
            $this->classAttributesOf($code),
        );
    }

    public function testNeedsTheRuntimeOfNot(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'not' => ['const' => 'x']],
        ], 'not' => ['required' => ['a']]], [], '8.2', [], 'v7.1.0', ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.1.0']);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'a'));
        self::assertSame([
            self::AT . ': "not" is checked by msstc4symfony/dto-generator-bridge-runtime 1.2 or newer, which the project does not install; it is not checked.',
            self::AT . '/properties/a: "not" is checked by msstc4symfony/dto-generator-bridge-runtime 1.2 or newer, which the project does not install; it is not checked.',
        ], $this->messages($output));
    }

    public function testNeedsNoRuntimeForANotThatForbidsNothing(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'not' => false],
        ], 'not' => false], [], '8.2', [], 'v7.1.0', ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.1.0']);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesTheNotOfAParentThatNamesNoPropertyToIt(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'not' => ['type' => 'object']]]);

        self::assertSame(['RuntimeAssert\\Not'], $this->classAttributesOf($this->code($output, 'Base.php')));
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testNeedsNewInInitializers(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'not' => ['const' => 'x']],
            'b' => ['type' => 'string', 'not' => true],
        ]], [], '8.0');
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'a'));
        self::assertSame(['RuntimeAssert\\Not'], $this->attributesOf($code, 'b'));
        self::assertSame([self::AT . '/properties/a: "not" goes into the attribute as "new", which PHP 8.0 does not allow; it is not checked.'], $this->messages($output));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'not' => ['const' => 'x']],
        ], 'not' => ['required' => ['a']]], [], '7.4', [], 'v6.4.1', self::RUNTIME + ['doctrine/annotations' => '2.0.2']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString(" * @RuntimeAssert\\Not(\n *     constraints={@\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\Present(properties={\"a\"})}\n * )", $code);
        self::assertStringContainsString('     * @RuntimeAssert\\Not(constraints={@\\Symfony\\Component\\Validator\\Constraints\\IdenticalTo(value="x")})', $code);
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsAboutANotInAMemberOfAUnion(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'value' => ['oneOf' => [['type' => 'string', 'not' => ['const' => 'a']], ['type' => 'integer']]],
        ]]);

        self::assertSame([self::AT . '/properties/value: "not" in a member of "oneOf" or "anyOf" is not checked.'], $this->messages($output));
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
