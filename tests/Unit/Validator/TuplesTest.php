<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * prefixItems, `items: false` and unevaluatedItems, checked by Collection and Count of Symfony.
 */
final class TuplesTest extends TestCase
{
    use GeneratesDtos;

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const AT = 'warning /api.yaml#/components/schemas/Pet';

    private const OPEN = 'allowExtraFields: true, allowMissingFields: true';

    public function testChecksTheElementsOfThePrefix(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'pair' => ['type' => 'array', 'prefixItems' => [['type' => 'string', 'maxLength' => 3], ['type' => 'integer', 'minimum' => 0], new stdClass()]],
        ]]);

        self::assertSame([
            'Assert\\Collection(fields: [0 => [new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string'), new " . self::ASSERT . 'Length(max: 3)], '
            . '1 => [new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'int'), new " . self::ASSERT . 'GreaterThanOrEqual(value: 0)]], ' . self::OPEN . ')',
        ], $this->attributesOf($this->code($output, 'Pet.php'), 'pair'));
        self::assertSame([], $this->messages($output));
    }

    public function testBoundsTheLengthByUnevaluatedItemsFalse(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'pair' => ['type' => 'array', 'allOf' => [['prefixItems' => [true, true]], ['prefixItems' => [true]]], 'unevaluatedItems' => false],
            'none' => ['type' => 'array', 'unevaluatedItems' => false],
            'all' => ['type' => 'array', 'items' => ['type' => 'string'], 'unevaluatedItems' => false],
            'free' => ['type' => 'array', 'unevaluatedItems' => true],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Count(max: 2)'], $this->attributesOf($code, 'pair'));
        self::assertSame(['Assert\\Count(max: 0)'], $this->attributesOf($code, 'none'));
        self::assertSame([], $this->attributesOf($code, 'all'));
        self::assertSame([], $this->attributesOf($code, 'free'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksEveryElementByAnUnevaluatedItemsSchema(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'codes' => ['type' => 'array', 'unevaluatedItems' => ['type' => 'string', 'maxLength' => 3]],
        ]]);

        self::assertSame(
            ['Assert\\All(constraints: [new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string'), new " . self::ASSERT . 'Length(max: 3)])'],
            $this->attributesOf($this->code($output, 'Pet.php'), 'codes'),
        );
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsAboutTuplesItCannotCheck(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'typed' => ['type' => 'array', 'items' => ['type' => 'string'], 'prefixItems' => [['maxLength' => 2]]],
            'open' => ['type' => 'array', 'items' => true, 'prefixItems' => [['type' => 'string']]],
            'name' => ['type' => 'string', 'prefixItems' => [true]],
            'odd' => ['type' => 'array', 'prefixItems' => ['a' => ['type' => 'string']]],
            'deep' => ['type' => 'array', 'prefixItems' => [['type' => 'array']]],
            'rest' => ['type' => 'array', 'prefixItems' => [true], 'unevaluatedItems' => ['type' => 'string']],
            'found' => ['type' => 'array', 'contains' => ['type' => 'string'], 'unevaluatedItems' => false],
            'branch' => ['type' => 'array', 'then' => ['maxItems' => 3], 'unevaluatedItems' => false],
            'nick' => ['type' => 'string', 'unevaluatedItems' => false],
        ]], [], '8.2', [], 'v7.1.0', ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0']);

        self::assertSame(['Assert\\Count(max: 0)'], $this->attributesOf($this->code($output, 'Pet.php'), 'branch'), 'A "then" without "if" evaluates nothing.');
        $code = $this->code($output, 'Pet.php');
        self::assertSame([], $this->attributesOf($code, 'typed'));
        self::assertSame([], $this->attributesOf($code, 'odd'));
        self::assertSame([], $this->attributesOf($code, 'rest'));
        self::assertSame(['RuntimeAssert\\Contains(constraints: [new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string')])"], $this->attributesOf($code, 'found'));
        self::assertSame([
            self::AT . '/properties/typed: "prefixItems" is checked only without "items", which the generated type gives to every element; it is not checked.',
            self::AT . '/properties/open: "prefixItems" is checked only without "items", which the generated type gives to every element; it is not checked.',
            self::AT . '/properties/name: "prefixItems" is checked on arrays only; it is not checked here.',
            self::AT . '/properties/odd: "prefixItems" is checked only with a list of schemas; it is not checked.',
            self::AT . '/properties/deep: "prefixItems" is checked on a value of any type only with one "type" of string, integer, number or boolean beside the keywords of strings and numbers; it is not checked.',
            self::AT . '/properties/rest: "unevaluatedItems" is checked beside "prefixItems" only when false; it is not checked.',
            self::AT . '/properties/found: "unevaluatedItems" is checked only without "contains", "if", "oneOf" and "anyOf" in its schema; it is not checked.',
            self::AT . '/properties/nick: "unevaluatedItems" is checked on arrays only; it is not checked here.',
        ], $this->messages($output));
    }

    public function testGroupsTheTuplesOfTheElementsOfAList(): void
    {
        $code = $this->code($this->generate(['type' => 'object', 'properties' => [
            'pairs' => ['type' => 'array', 'x-validator-groups' => ['api'], 'items' => ['type' => 'array', 'prefixItems' => [['type' => 'string']]]],
        ]]), 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(
            ['Assert\\All(constraints: [new ' . self::ASSERT . 'Collection(fields: [0 => new ' . self::ASSERT . 'Optional([new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string')], $groups)], " . self::OPEN . ", $groups)], $groups)"],
            $this->attributesOf($code, 'pairs'),
        );
    }

    public function testGroupsTheElementsOfThePrefixAsTheirList(): void
    {
        $code = $this->code($this->generate(['type' => 'object', 'properties' => [
            'pair' => ['type' => 'array', 'x-validator-groups' => ['api'], 'x-validator-groups-exclusive' => true, 'prefixItems' => [['type' => 'string']]],
        ]]), 'Pet.php');

        self::assertSame(
            ['Assert\\Collection(fields: [0 => new ' . self::ASSERT . 'Optional([new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string')], groups: ['api'])], " . self::OPEN . ", groups: ['api'])"],
            $this->attributesOf($code, 'pair'),
        );
    }

    public function testReadsUnevaluatedItemsInItsOwnSchema(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'closed' => ['type' => 'array', 'allOf' => [['$ref' => '#/components/schemas/Pair'], ['prefixItems' => [true, true, true]]]],
            'sibling' => ['type' => 'array', 'allOf' => [['prefixItems' => [true, true]], ['unevaluatedItems' => false]]],
            'typed' => ['type' => 'array', 'allOf' => [['items' => ['type' => 'string']], ['unevaluatedItems' => false]]],
        ]], ['Pair' => ['type' => 'array', 'prefixItems' => [true, true], 'unevaluatedItems' => false]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Count(max: 2)'], $this->attributesOf($code, 'closed'));
        self::assertSame(['Assert\\Count(max: 0)'], $this->attributesOf($code, 'sibling'));
        self::assertSame(['Assert\\Count(max: 0)'], $this->attributesOf($code, 'typed'));
        self::assertSame([], $this->messages($output));
    }

    public function testLetsAnUnevaluatedItemsOfASubschemaEvaluateTheElements(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'words' => ['allOf' => [['$ref' => '#/components/schemas/Words']], 'unevaluatedItems' => false],
        ]], ['Words' => ['type' => 'array', 'unevaluatedItems' => ['type' => 'string']]]);

        self::assertSame(
            ['Assert\\All(constraints: [new ' . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string')])"],
            $this->attributesOf($this->code($output, 'Pet.php'), 'words'),
        );
        self::assertSame([], $this->messages($output));
    }

    public function testReadsTheListOfANullableUnion(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'pair' => ['oneOf' => [['$ref' => '#/components/schemas/Pair'], ['type' => 'null']], 'unevaluatedItems' => false],
        ]], ['Pair' => ['type' => 'array', 'prefixItems' => [true]]]);

        self::assertSame(['Assert\\Count(max: 1)'], $this->attributesOf($this->code($output, 'Pet.php'), 'pair'));
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsNotAboutTuplesWithoutEffectOnOtherValues(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'prefixItems' => [], 'unevaluatedItems' => true],
            'nick' => ['type' => 'string', 'unevaluatedItems' => new stdClass()],
        ]]);

        self::assertSame([], $this->messages($output));
    }

    public function testWritesOptionalOfTheGroupsInAnnotations(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'pair' => ['type' => 'array', 'x-validator-groups' => ['api'], 'prefixItems' => [['type' => 'string']]],
        ]], [], '7.4', [], 'v6.4.1', ['doctrine/annotations' => '2.0.2']);

        self::assertStringContainsString('@\\Symfony\\Component\\Validator\\Constraints\\Optional(', $this->code($output, 'Pet.php'));
        self::assertStringContainsString('groups={"api", "Default"}', $this->code($output, 'Pet.php'));
        self::assertSame([], $this->messages($output));
    }

    public function testIgnoresUnevaluatedItemsThatAllowEveryElement(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'free' => ['type' => 'array', 'contains' => ['type' => 'string'], 'unevaluatedItems' => true],
            'empty' => ['type' => 'array', 'contains' => ['type' => 'string'], 'unevaluatedItems' => new stdClass()],
        ]], [], '8.2', [], 'v7.1.0', ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0']);

        self::assertSame([], $this->messages($output));
    }

    public function testChecksNoPrefixWhoseElementIsNotChecked(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'none' => ['type' => 'array', 'prefixItems' => []],
            'half' => ['type' => 'array', 'prefixItems' => [['type' => 'string'], false]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'none'));
        self::assertSame([], $this->attributesOf($code, 'half'));
        self::assertSame([self::AT . '/properties/half: "prefixItems" is checked only with a schema object or true; it is not checked.'], $this->messages($output));
    }

    public function testNeedsNewInInitializers(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'pair' => ['type' => 'array', 'prefixItems' => [['type' => 'string']], 'unevaluatedItems' => false],
            'codes' => ['type' => 'array', 'unevaluatedItems' => ['type' => 'string', 'maxLength' => 3]],
        ]], [], '8.0');

        self::assertSame(['Assert\\Count(max: 1)'], $this->attributesOf($this->code($output, 'Pet.php'), 'pair'));
        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'codes'));
        self::assertSame([
            self::AT . '/properties/pair: "prefixItems" goes into the attribute as "new", which PHP 8.0 does not allow; it is not checked.',
            self::AT . '/properties/codes: The constraints of the elements go inside All as "new", which PHP 8.0 does not allow in attributes; they are not checked.',
        ], $this->messages($output));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'pair' => ['type' => 'array', 'prefixItems' => [['type' => 'string']]],
        ]], [], '7.4', [], 'v6.4.1', ['doctrine/annotations' => '2.0.2']);

        self::assertStringContainsString(
            "     * @Assert\\Collection(\n     *     fields={\n     *         0={\n     *             @\\Symfony\\Component\\Validator\\Constraints\\NotNull,\n     *             @\\Symfony\\Component\\Validator\\Constraints\\Type(type=\"string\")\n     *         }\n     *     },\n     *     allowExtraFields=true,\n     *     allowMissingFields=true\n     * )",
            $this->code($output, 'Pet.php'),
        );
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsAboutATupleInAMemberOfAUnion(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'value' => ['oneOf' => [['type' => 'array', 'prefixItems' => [true]], ['type' => 'integer']]],
        ]]);

        self::assertSame([self::AT . '/properties/value: "prefixItems" in a member of "oneOf" or "anyOf" is not checked.'], $this->messages($output));
    }
}
