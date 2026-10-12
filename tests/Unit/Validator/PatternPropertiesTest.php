<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * patternProperties, checked by PatternProperty of msstc4symfony/dto-generator-bridge-runtime on maps and by the
 * constraints of declared properties whose names match.
 */
final class PatternPropertiesTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.1.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const AT = 'warning /api.yaml#/components/schemas/Pet';

    public function testChecksTheValuesOfAMapByTheirKeys(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string', 'maxLength' => 3], '^n-' => ['type' => 'integer']]],
        ]]);

        self::assertSame([
            "RuntimeAssert\\PatternProperty(pattern: '/^x-/uD', constraints: [new " . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string'), new " . self::ASSERT . 'Length(max: 3)])',
            "RuntimeAssert\\PatternProperty(pattern: '/^n-/uD', constraints: [new " . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'int')])",
        ], $this->attributesOf($this->code($output, 'Pet.php'), 'labels'));
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsThatTheSchemaOfUndeclaredPropertiesAppliesToMatchingKeysToo(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'codes' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'patternProperties' => ['^a' => ['minimum' => 1]]],
        ]]);

        self::assertSame(
            ["RuntimeAssert\\PatternProperty(pattern: '/^a/uD', constraints: [new " . self::ASSERT . 'GreaterThanOrEqual(value: 1)])'],
            $this->attributesOf($this->code($output, 'Pet.php'), 'codes'),
        );
        self::assertSame(
            [self::AT . '/properties/codes: "additionalProperties" applies to the properties "patternProperties" matches too, as the generated type gives every undeclared property its type.'],
            $this->messages($output),
        );
    }

    public function testChecksTheDeclaredAndUndeclaredPropertiesOfAClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'x-validator-groups' => ['api'], 'patternProperties' => ['^x-' => ['maxLength' => 3]], 'additionalProperties' => ['type' => 'string'], 'properties' => [
            'x-a' => ['type' => 'string', 'minLength' => 1],
            'x-b' => ['type' => 'string', 'x-validator-skip' => true],
            'name' => ['type' => 'string'],
        ]]);
        $code = $this->code($output, 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(['Assert\\Length(min: 1)', 'Assert\\Length(max: 3, ' . $groups . ')'], $this->attributesOf($code, 'xA'));
        self::assertSame(['Assert\\Length(max: 3, ' . $groups . ')'], $this->attributesOf($code, 'xB'));
        self::assertSame([], $this->attributesOf($code, 'name'));
        self::assertSame(
            ["RuntimeAssert\\PatternProperty(pattern: '/^x-/uD', constraints: [new " . self::ASSERT . "Length(max: 3)], $groups)"],
            $this->attributesOf($code, 'additionalProperties'),
        );
        self::assertSame(
            [self::AT . ': "additionalProperties" applies to the properties "patternProperties" matches too, as the generated type gives every undeclared property its type.'],
            $this->messages($output),
        );
    }

    public function testWarnsAboutTheUndeclaredPropertiesOfAClassWithoutThem(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string', 'maxLength' => 3]], 'properties' => ['x-a' => ['type' => 'string']]]);

        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($this->code($output, 'Pet.php'), 'xA'));
        self::assertSame(
            [self::AT . ': "patternProperties" is checked on the undeclared properties of a class, which this class does not keep; they are not checked.'],
            $this->messages($output),
        );
    }

    public function testWarnsAboutPatternsItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'object', 'additionalProperties' => true, 'patternProperties' => ['(' => ['type' => 'string'], '^a' => ['items' => ['type' => 'string']], '^b' => ['type' => 'string']]],
            'name' => ['type' => 'string', 'patternProperties' => ['^a' => ['type' => 'string']]],
            'malformed' => ['type' => 'object', 'patternProperties' => ['^a', '^b']],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(["RuntimeAssert\\PatternProperty(pattern: '/^b/uD', constraints: [new " . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "Type(type: 'string')])"], $this->attributesOf($code, 'labels'));
        self::assertSame([], $this->attributesOf($code, 'name'));
        self::assertSame([], $this->attributesOf($code, 'malformed'));
        $at = self::AT . '/properties/';
        self::assertSame([
            $at . 'labels: "patternProperties" holds "(", which is no regular expression PHP can run; it is not checked.',
            $at . 'labels: "patternProperties" is checked only with a schema of "type", "format", "enum", "const" and the keywords of strings and numbers; with "items" it is not checked.',
            $at . 'name: "patternProperties" is checked on maps and on classes only; it is not checked here.',
            $at . 'malformed: "patternProperties" must map patterns to schemas; it is not checked.',
        ], $this->messages($output));
    }

    public function testNeedsTheRuntimeForAMap(): void
    {
        $output = $this->generate(['type' => 'object', 'patternProperties' => ['^x-' => ['maxLength' => 3]], 'properties' => [
            'x-a' => ['type' => 'string'],
            'labels' => ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($code, 'xA'));
        self::assertSame([], $this->attributesOf($code, 'labels'));
        self::assertSame([
            self::AT . ': "patternProperties" is checked on the undeclared properties of a class, which this class does not keep; they are not checked.',
            self::AT . '/properties/labels: "patternProperties" is checked by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksTheMapsInsideAList(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array', 'x-validator-groups' => ['api'], 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'patternProperties' => ['^x-' => ['maxLength' => 3]]]],
        ]]), 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(
            ['Assert\\All(constraints: [new \\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\PatternProperty(pattern: \'/^x-/uD\', constraints: [new ' . self::ASSERT . "Length(max: 3)], $groups)], $groups)"],
            $this->attributesOf($code, 'rows'),
        );
    }

    public function testReadsThePlacesAfterAMalformedOne(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'labels' => ['type' => 'object', 'additionalProperties' => true, 'allOf' => [['patternProperties' => ['^a']], ['patternProperties' => ['^b' => ['const' => 'x']]]]],
        ]]);

        self::assertSame(["RuntimeAssert\\PatternProperty(pattern: '/^b/uD', constraints: [new " . self::ASSERT . 'NotNull(), new ' . self::ASSERT . "IdenticalTo(value: 'x')])"], $this->attributesOf($this->code($output, 'Pet.php'), 'labels'));
        self::assertSame([self::AT . '/properties/labels/allOf/0: "patternProperties" must map patterns to schemas; it is not checked.'], $this->messages($output));
    }

    /**
     * @param array<string, mixed> $pet
     */
    private function withRuntime(array $pet): Output
    {
        return $this->generate($pet, [], '8.2', [], 'v7.1.0', self::RUNTIME);
    }
}
