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
        $output = $this->withRuntime(['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string', 'maxLength' => 3]], 'properties' => [
            'x-a' => ['type' => 'string'],
            'x-gone' => ['type' => 'string', 'x-php-skip' => true],
        ]]);

        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($this->code($output, 'Pet.php'), 'xA'));
        self::assertSame(
            [self::AT . ': "patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.'],
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
            self::AT . ': "patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.',
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

    public function testChecksAnInheritedPropertyAPatternOfTheChildMatchesOnTheChild(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'additionalProperties' => true, 'patternProperties' => ['^x-' => ['maxLength' => 3]], 'properties' => ['x-own' => ['type' => 'string']]],
        ]], ['Base' => ['type' => 'object', 'patternProperties' => ['^x-' => ['minLength' => 1]], 'properties' => ['x-a' => ['type' => 'string'], 'plain' => ['type' => 'string']]]]);

        self::assertSame(['Assert\\Length(min: 1)', 'Assert\\Length(max: 3)'], $this->attributesOf($this->code($output, 'Pet.php'), 'xOwn'));
        self::assertSame(['Assert\\Length(min: 1)'], $this->attributesOf($this->code($output, 'Base.php'), 'xA'));
        self::assertSame(['RuntimeAssert\\Property(name: \'xA\', constraints: [new ' . self::ASSERT . 'Length(max: 3)])'], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([
            self::AT . ': "patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.',
            'warning /api.yaml#/components/schemas/Base: "patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.',
        ], $this->messages($output));
    }

    public function testLeavesToTheParentAPropertyAPatternOfAnotherBranchOfItMatches(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            ['$ref' => '#/components/schemas/Base'],
        ]], ['Base' => ['allOf' => [
            ['type' => 'object', 'properties' => ['x-a' => ['type' => 'string']]],
            ['patternProperties' => ['^x-' => ['maxLength' => 3]]],
        ]]]);

        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($this->code($output, 'Base.php'), 'xA'));
        self::assertSame(
            ['warning /api.yaml#/components/schemas/Base: "patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.'],
            array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'Pet') === false)),
        );
        self::assertSame(
            [self::AT . ': "patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.'],
            array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'Pet') !== false)),
        );
    }

    public function testChecksEachInheritedMatchOnTheClassAndAnOwnOneOnItsProperty(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['x-own' => ['type' => 'string'], 'z' => ['type' => 'string', 'x-php-skip' => true]]],
            ['patternProperties' => ['^x-' => ['type' => 'string', 'maxLength' => 3], '^y-' => ['description' => 'Y'], '^z' => ['maxLength' => 1]]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['x-b' => ['type' => 'string'], 'x-c' => ['type' => 'string'], 'y-a' => ['type' => 'string']]]]);
        $code = $this->code($output, 'Pet.php');

        $length = 'constraints: [new ' . self::ASSERT . 'Length(max: 3)])';
        self::assertSame(["RuntimeAssert\\Property(name: 'xB', " . $length, "RuntimeAssert\\Property(name: 'xC', " . $length], $this->classAttributesOf($code));
        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($code, 'xOwn'));
        self::assertSame([], array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'does not have') !== false)));
    }

    public function testWarnsAboutAMatchOfAPropertyTheParentLeavesOut(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'patternProperties' => ['^x-' => ['maxLength' => 3]]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['x-a' => ['type' => 'string', 'x-php-skip' => true], 'x-b' => ['type' => 'string']]]]);

        self::assertSame(['RuntimeAssert\\Property(name: \'xB\', constraints: [new ' . self::ASSERT . 'Length(max: 3)])'], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertContains(self::AT . '/allOf/1: "patternProperties" matches "x-a", which the schema of a parent declares and this class does not have; it is not checked on it.', $this->messages($output));
    }

    public function testNeedsTheRuntimeForAnInheritedMatch(): void
    {
        $output = $this->generate(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'patternProperties' => ['^x-' => ['maxLength' => 3]]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['x-a' => ['type' => 'string']]]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertContains(self::AT . ': "patternProperties" is checked by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.', $this->messages($output));
    }

    public function testChecksAPatternBesideTheReferenceToTheParent(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['$ref' => '#/components/schemas/Base', 'patternProperties' => ['^x-' => ['maxLength' => 3]]],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['x-a' => ['type' => 'string']]]]);

        self::assertSame(['RuntimeAssert\\Property(name: \'xA\', constraints: [new ' . self::ASSERT . 'Length(max: 3)])'], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testChecksAPatternOfASchemaBetweenTheClassAndItsParent(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['$ref' => '#/components/schemas/Mid'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ]], [
            'Mid' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['patternProperties' => ['^x-' => ['maxLength' => 3]]]]],
            'Base' => ['type' => 'object', 'properties' => ['x-a' => ['type' => 'string']]],
        ]);

        self::assertSame(['RuntimeAssert\\Property(name: \'xA\', constraints: [new ' . self::ASSERT . 'Length(max: 3)])'], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testChecksThePropertiesOfMergedReferences(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['$ref' => '#/components/schemas/Named'],
            ['$ref' => '#/components/schemas/Tagged'],
            ['patternProperties' => ['^x-' => ['maxLength' => 3]]],
        ]], [
            'Named' => ['type' => 'object', 'properties' => ['x-a' => ['type' => 'string']]],
            'Tagged' => ['type' => 'object', 'properties' => ['tag' => ['type' => 'string']]],
        ]);

        self::assertSame(['Assert\\Length(max: 3)'], $this->attributesOf($this->code($output, 'Pet.php'), 'xA'));
        self::assertSame(
            [self::AT . ': "patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.'],
            $this->messages($output),
        );
    }

    public function testChecksThatAPatternAdmitsOnlyNull(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'marks' => ['type' => 'object', 'patternProperties' => ['^n' => ['type' => 'null'], '^d' => ['description' => 'Anything.']]],
        ]]);

        self::assertSame(["RuntimeAssert\\PatternProperty(pattern: '/^n/uD', constraints: [new " . self::ASSERT . 'IsNull()])'], $this->attributesOf($this->code($output, 'Pet.php'), 'marks'));
        self::assertSame([], $this->messages($output));
    }

    public function testChecksTheKeywordsOfOneKindOnAnEnumWithoutPhpEnums(): void
    {
        $output = $this->generate(['type' => 'object', 'patternProperties' => ['^k' => ['minLength' => 2]], 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['ab', 'cd']],
        ]], [], '8.0', [], 'v7.1.0', self::RUNTIME);

        self::assertContains('Assert\\Length(min: 2)', $this->attributesOf($this->code($output, 'Pet.php'), 'kind'));
    }

    public function testChecksAnInheritedMatchOfTheOnlyVariantOfItsParent(): void
    {
        $output = $this->withRuntimeAnd(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']], 'patternProperties' => ['^k' => ['maxLength' => 3]]],
        ]], ['Base' => [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string']],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['pet' => '#/components/schemas/Pet']],
            'oneOf' => [['$ref' => '#/components/schemas/Pet']],
        ]]);

        self::assertSame(['RuntimeAssert\\Property(name: \'kind\', constraints: [new ' . self::ASSERT . 'Length(max: 3)])'], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    /**
     * @param array<string, mixed> $pet
     */
    private function withRuntime(array $pet): Output
    {
        return $this->withRuntimeAnd($pet, []);
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     */
    private function withRuntimeAnd(array $pet, array $others): Output
    {
        return $this->generate($pet, $others, '8.2', [], 'v7.1.0', self::RUNTIME);
    }
}
