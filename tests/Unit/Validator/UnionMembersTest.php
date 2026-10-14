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
        yield 'integer and number, which overlap' => [['oneOf' => [['type' => 'integer'], ['type' => 'number']]], $exactly("new {$a}Type(type: 'int'), new {$a}Type(type: ['int', 'float'])")];
        yield 'integer or number' => [['anyOf' => [['type' => 'integer'], ['type' => 'number']]], []];
        yield 'one type twice, which never passes once' => [['oneOf' => [['type' => 'string'], ['type' => 'string']]], $exactly("new {$a}Type(type: 'string'), new {$a}Type(type: 'string')")];
        yield 'a member behind $ref' => [['oneOf' => [['$ref' => '#/components/schemas/Short'], $positive]], $exactly("new {$a}Sequentially(constraints: [new {$a}Type(type: 'string'), new {$a}Length(max: 3)]), new {$a}Sequentially(constraints: [new {$a}Type(type: 'int'), new {$a}GreaterThanOrEqual(value: 1)])")];
        yield 'a keyword beside $ref' => [['oneOf' => [['$ref' => '#/components/schemas/Str', 'maxLength' => 3], $positive]], []];
        yield 'a format beside $ref' => [['oneOf' => [['$ref' => '#/components/schemas/Str', 'format' => 'email'], $positive]], []];
        yield 'a union behind $ref' => [['$ref' => '#/components/schemas/Union', 'description' => 'A code.'], $exactly("new {$a}Length(max: 3), new {$a}Regex(pattern: '/^[0-9]+\$/uD')")];
        yield 'an own union beside one behind $ref' => [['$ref' => '#/components/schemas/Union', 'oneOf' => [$short, $digits]], []];
        yield 'oneOf beside anyOf' => [['oneOf' => [$short, $digits], 'anyOf' => [$short, $digits]], []];
        yield 'an enum member' => [['oneOf' => [['type' => 'string', 'enum' => ['a', 'b']], $positive]], []];
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
     */
    private function withRuntime(array $pet): Output
    {
        return $this->generate($pet, [], '8.2', [], 'v7.1.0', self::RUNTIME);
    }
}
