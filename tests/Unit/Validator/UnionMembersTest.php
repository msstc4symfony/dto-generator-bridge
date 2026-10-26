<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * The members of a oneOf or anyOf without a discriminator: AtLeastOneOf for anyOf, the runtime's ExactlyOneOf for oneOf.
 */
final class UnionMembersTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.3.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const RUNTIME_ASSERT = '\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\';

    public function testChecksAnyOfWithAtLeastOneOfAndOneOfWithExactlyOneOf(): void
    {
        $members = [['type' => 'string', 'minLength' => 2], ['type' => 'integer', 'minimum' => 1]];
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['any' => ['anyOf' => $members], 'one' => ['oneOf' => $members]]]);
        $code = $this->code($output, 'Pet.php');

        $inner = '[new ' . self::ASSERT . 'Sequentially(constraints: [new ' . self::ASSERT . "Type(type: 'string'), new " . self::ASSERT . 'Length(min: 2)]), new ' . self::ASSERT . 'Sequentially(constraints: [new ' . self::ASSERT . "Type(type: 'int'), new " . self::ASSERT . 'GreaterThanOrEqual(value: 1)])]';
        self::assertSame(['Assert\\AtLeastOneOf(constraints: ' . $inner . ')'], $this->attributesOf($code, 'any'));
        self::assertSame(['RuntimeAssert\\ExactlyOneOf(constraints: ' . $inner . ')'], $this->attributesOf($code, 'one'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksNothingWhenTheTypeTellsTheMembersApart(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['any' => ['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'null']]]]]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'any'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksMembersOfOneTypeThatOnlyTheirKeywordsTellApart(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['code' => ['oneOf' => [['type' => 'string', 'maxLength' => 3], ['type' => 'string', 'pattern' => '^[0-9]+$']]]]]);

        self::assertSame(
            ['RuntimeAssert\\ExactlyOneOf(constraints: [new ' . self::ASSERT . 'Length(max: 3), new ' . self::ASSERT . "Regex(pattern: '/^[0-9]+\$/uD')])"],
            $this->attributesOf($this->code($output, 'Pet.php'), 'code'),
        );
    }

    public function testLeavesOneOfToARuntimeThatHasExactlyOneOf(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['one' => ['oneOf' => [['type' => 'string', 'minLength' => 2], ['type' => 'integer']]]]], [], '8.2', [], 'v7.1.0', ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0']);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'one'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/one: "oneOf" is checked by msstc4symfony/dto-generator-bridge-runtime 1.3 or newer, which the project does not install; it is not checked.'], $this->messages($output));
    }

    public function testStillWarnsAboutMembersItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['mixed' => ['anyOf' => [['type' => 'string', 'not' => ['const' => 'x']], ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1]]]]]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'mixed'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/mixed: "not" in a member of "oneOf" or "anyOf" is not checked.', $this->messages($output));
    }

    public function testGivesTheNestedConstraintsTheGroupsOfTheValue(): void
    {
        $members = [['type' => 'string', 'minLength' => 2], ['type' => 'integer', 'minimum' => 1]];
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'any' => ['anyOf' => $members, 'x-validator-groups' => ['api']],
            'list' => ['type' => 'array', 'items' => ['oneOf' => $members], 'x-validator-groups' => ['api']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        $a = self::ASSERT;
        $groups = "groups: ['api', 'Default']";
        $inner = "[new {$a}Sequentially(constraints: [new {$a}Type(type: 'string'), new {$a}Length(min: 2)], {$groups}), new {$a}Sequentially(constraints: [new {$a}Type(type: 'int'), new {$a}GreaterThanOrEqual(value: 1)], {$groups})]";
        self::assertSame(["Assert\\AtLeastOneOf(constraints: {$inner}, {$groups})"], $this->attributesOf($code, 'any'));
        self::assertContains('Assert\\All(constraints: [new ' . self::RUNTIME_ASSERT . "ExactlyOneOf(constraints: {$inner}, {$groups})], {$groups})", $this->attributesOf($code, 'list'));
    }

    public function testLeavesMembersThatAreNoPhpScalarsUnchecked(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'date' => ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'integer', 'minimum' => 1]]],
            'typed' => ['anyOf' => [['type' => 'string', 'x-php-type' => '\\ArrayObject'], ['type' => 'integer', 'minimum' => 1]]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'date'));
        self::assertSame([], $this->attributesOf($code, 'typed'));
    }

    public function testChecksTheMembersOfAUnionThatANullBesideARefMakesNullable(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['code' => ['anyOf' => [['$ref' => '#/components/schemas/Union'], ['type' => 'null']]]]], [
            'Union' => ['oneOf' => [['type' => 'string', 'maxLength' => 3], ['type' => 'string', 'pattern' => '^[0-9]+$']]],
        ]);

        self::assertSame(
            ['RuntimeAssert\\ExactlyOneOf(constraints: [new ' . self::ASSERT . 'Length(max: 3), new ' . self::ASSERT . "Regex(pattern: '/^[0-9]+\$/uD')])"],
            $this->attributesOf($this->code($output, 'Pet.php'), 'code'),
        );
    }

    public function testIgnoresAnnotationsOfAMember(): void
    {
        $positive = ['type' => 'integer', 'minimum' => 1];
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'titled' => ['oneOf' => [['$ref' => '#/components/schemas/Short', 'title' => 'Short'], $positive]],
            'shown' => ['oneOf' => [['type' => 'string', 'maxLength' => 3, 'example' => 'ab', 'readOnly' => true], $positive]],
        ]], ['Short' => ['type' => 'string', 'maxLength' => 3]]);
        $code = $this->code($output, 'Pet.php');

        $a = self::ASSERT;
        $expected = ["RuntimeAssert\\ExactlyOneOf(constraints: [new {$a}Sequentially(constraints: [new {$a}Type(type: 'string'), new {$a}Length(max: 3)]), new {$a}Sequentially(constraints: [new {$a}Type(type: 'int'), new {$a}GreaterThanOrEqual(value: 1)])])"];
        self::assertSame($expected, $this->attributesOf($code, 'titled'));
        self::assertSame($expected, $this->attributesOf($code, 'shown'));
    }

    public function testChecksTheTypesOfMembersOnAnUntypedProperty(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'one' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            'any' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
        ]], [], '7.4', [], 'v6.4.0', self::RUNTIME + ['doctrine/annotations' => '1.14.0']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("@RuntimeAssert\\ExactlyOneOf(\n     *     constraints={\n     *         @\\Symfony\\Component\\Validator\\Constraints\\Type(type=\"string\"),", $code);
        self::assertStringContainsString("@Assert\\AtLeastOneOf(\n     *     constraints={\n     *         @\\Symfony\\Component\\Validator\\Constraints\\Type(type=\"string\"),", $code);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function unions(): iterable
    {
        $short = ['type' => 'string', 'maxLength' => 3];
        $digits = ['type' => 'string', 'pattern' => '^[0-9]+$'];
        $positive = ['type' => 'integer', 'minimum' => 1];
        $a = self::ASSERT;
        $exactly = static fn (string $inner): array => ['RuntimeAssert\\ExactlyOneOf(constraints: [' . $inner . '])'];

        yield 'a null member among them' => [['oneOf' => [['type' => 'null'], $short, $digits]], $exactly("new {$a}Length(max: 3), new {$a}Regex(pattern: '/^[0-9]+\$/uD')")];
        yield 'integer and number, which PHP tells apart otherwise than JSON Schema' => [['oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'number', 'maximum' => 0]]], []];
        yield 'integer or number' => [['anyOf' => [['type' => 'integer'], ['type' => 'number']]], []];
        yield 'one type twice, which never passes once' => [['oneOf' => [['type' => 'string'], ['type' => 'string']]], $exactly("new {$a}Type(type: 'string'), new {$a}Type(type: 'string')")];
        yield 'a member behind $ref' => [['oneOf' => [['$ref' => '#/components/schemas/Short'], $positive]], $exactly("new {$a}Sequentially(constraints: [new {$a}Type(type: 'string'), new {$a}Length(max: 3)]), new {$a}Sequentially(constraints: [new {$a}Type(type: 'int'), new {$a}GreaterThanOrEqual(value: 1)])")];
        yield 'a keyword beside $ref' => [['oneOf' => [['$ref' => '#/components/schemas/Str', 'maxLength' => 3], $positive]], []];
        yield 'a format beside $ref' => [['oneOf' => [['$ref' => '#/components/schemas/Str', 'format' => 'email'], $positive]], []];
        yield 'a union behind $ref' => [['$ref' => '#/components/schemas/Union', 'description' => 'A code.'], $exactly("new {$a}Length(max: 3), new {$a}Regex(pattern: '/^[0-9]+\$/uD')")];
        yield 'an own union beside one behind $ref' => [['$ref' => '#/components/schemas/Union', 'oneOf' => [$short, $digits]], []];
        yield 'oneOf beside anyOf' => [['oneOf' => [$short, $digits], 'anyOf' => [$short, $digits]], []];
        yield 'consts without a type' => [['oneOf' => [['const' => 'a'], ['const' => 7], ['const' => true]]], $exactly("new {$a}Sequentially(constraints: [new {$a}Type(type: 'string'), new {$a}IdenticalTo(value: 'a')]), new {$a}Sequentially(constraints: [new {$a}Type(type: 'int'), new {$a}IdenticalTo(value: 7)]), new {$a}Sequentially(constraints: [new {$a}Type(type: 'bool'), new {$a}IdenticalTo(value: true)])")];
        yield 'a const of a number' => [['oneOf' => [['const' => 1.5], $short]], $exactly("new {$a}Sequentially(constraints: [new {$a}Type(type: ['int', 'float']), new {$a}IdenticalTo(value: 1.5)]), new {$a}Sequentially(constraints: [new {$a}Type(type: 'string'), new {$a}Length(max: 3)])")];
        yield 'a whole number for a number, which PHP tells from a float' => [['oneOf' => [['type' => 'number', 'const' => 2], $short]], []];
        yield 'a const that is a list' => [['oneOf' => [['const' => ['a']], $short]], []];
        yield 'two dates, one class' => [['oneOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'string', 'format' => 'date']]], []];
        yield 'any of two dates, one class' => [['anyOf' => [['type' => 'string', 'format' => 'date-time', 'maxLength' => 30], ['type' => 'string', 'format' => 'date']]], []];
        yield 'an enum member, a PHP enum' => [['oneOf' => [['type' => 'string', 'enum' => ['a', 'b']], $positive]], []];
        yield 'a member with allOf' => [['oneOf' => [['type' => 'string', 'allOf' => [['maxLength' => 3]]], $positive]], []];
        yield 'a member with oneOf' => [['oneOf' => [['type' => 'string', 'oneOf' => [['maxLength' => 3], ['minLength' => 5]]], $positive]], []];
        yield 'a member with anyOf' => [['oneOf' => [['type' => 'string', 'anyOf' => [['maxLength' => 3], ['minLength' => 5]]], $positive]], []];
        yield 'a member with properties' => [['oneOf' => [['type' => 'string', 'properties' => ['a' => ['type' => 'string']]], $positive]], ['Assert\\Valid']];
        yield 'a member of two types' => [['oneOf' => [['type' => ['string', 'integer'], 'maxLength' => 3], ['type' => 'boolean']]], []];
        yield 'an array member' => [['oneOf' => [['type' => 'array', 'items' => ['type' => 'string']], $positive]], []];
    }

    /**
     * @dataProvider unions
     *
     * @param array<string, mixed> $union
     * @param list<string> $expected
     */
    public function testChecksTheMembersItCanTellApart(array $union, array $expected): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['value' => $union]], [
            'Short' => ['type' => 'string', 'maxLength' => 3],
            'Str' => ['type' => 'string'],
            'Union' => ['oneOf' => [['type' => 'string', 'maxLength' => 3], ['type' => 'string', 'pattern' => '^[0-9]+$']]],
        ], '8.2', [], 'v7.1.0', self::RUNTIME);

        self::assertSame($expected, $this->attributesOf($this->code($output, 'Pet.php'), 'value'));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     */
    private function withRuntime(array $pet, array $others = []): Output
    {
        return $this->generate($pet, $others, '8.2', [], 'v7.1.0', self::RUNTIME);
    }
}
