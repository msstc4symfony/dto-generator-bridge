<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * dependentSchemas, checked on classes by IfThenElse with Present, and by Not, of msstc4symfony/dto-generator-bridge-runtime.
 */
final class DependentSchemasTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const RUNTIME_ASSERT = '\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\';

    private const AT = 'warning /api.yaml#/components/schemas/Pet';

    public function testChecksTheSchemaOfAPropertyThatIsSet(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'card' => ['type' => 'string', 'x-php-name' => 'cardNumber'],
            'holder' => ['type' => 'string'],
            'expiry' => ['type' => 'string'],
            'cash' => ['type' => 'boolean'],
        ], 'dependentSchemas' => [
            'card' => ['required' => ['holder'], 'properties' => ['expiry' => ['pattern' => '^\\d\\d/\\d\\d$']]],
            'cash' => false,
            'holder' => true,
            'expiry' => new stdClass(),
        ]]);

        self::assertSame([
            'RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['cardNumber'])], then: [new " . self::RUNTIME_ASSERT . "Property(name: 'expiry', constraints: [new " . self::ASSERT . "Regex(pattern: '/^\\d\\d\\/\\d\\d\$/uD')]), new " . self::RUNTIME_ASSERT . "Present(properties: ['holder'])])",
            'RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['cash'])])",
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsAboutADependencyItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'default' => 'x'],
            'b' => ['type' => 'string'],
        ], 'allOf' => [
            ['dependentSchemas' => ['a' => ['required' => ['b']]]],
            ['dependentSchemas' => ['nick' => ['required' => ['b']]]],
            ['dependentSchemas' => ['b' => ['required' => ['nick']]]],
            ['dependentSchemas' => ['b' => ['minProperties' => 1]]],
            ['dependentSchemas' => ['b' => ['properties' => ['a' => ['const' => 'y']]]]],
            ['dependentSchemas' => [['required' => ['b']]]],
        ]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([
            self::AT . '/allOf/0: "dependentSchemas" names "a", which has a default and is always set; it is not checked.',
            self::AT . '/allOf/1: "dependentSchemas" names "nick", which is not a property of this class; it is not checked.',
            self::AT . '/allOf/2: "dependentSchemas" names "nick", which is not a property of this class; it is not checked.',
            self::AT . '/allOf/3: "dependentSchemas" is checked on a class only with "properties" and "required"; with "minProperties" it is not checked.',
            self::AT . '/allOf/4: "dependentSchemas" names "a", which has a default and is always set; it is not checked.',
            self::AT . '/allOf/5: "dependentSchemas" is checked only with an object of schemas; it is not checked.',
        ], $this->messages($output));
    }

    public function testWarnsAboutDependentSchemasOfAValue(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'dependentSchemas' => ['a' => ['required' => ['b']]]],
            'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'dependentSchemas' => ['a' => ['required' => ['b']]]],
        ]]);

        self::assertSame([
            self::AT . '/properties/name: "dependentSchemas" is checked on classes only; it is not checked here.',
            self::AT . '/properties/labels: "dependentSchemas" is checked on classes only; it is not checked here.',
        ], $this->messages($output));
    }

    public function testLeavesTheDependencyOfAParentToIt(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            ['dependentSchemas' => ['a' => ['required' => ['b']]]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'c' => ['type' => 'string']], 'dependentSchemas' => ['a' => ['required' => ['c']]]]]);

        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a'])], then: [new " . self::RUNTIME_ASSERT . "Present(properties: ['c'])])"],
            $this->classAttributesOf($this->code($output, 'Base.php')),
        );
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(
            [self::AT . '/allOf/2: "dependentSchemas" names "a", which this class inherits; it is not checked: write it in the schema that declares its properties.'],
            $this->messages($output),
        );
    }

    public function testRequiresAPropertyWithADefaultInTheSchema(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'card' => ['type' => 'string'],
            'currency' => ['type' => 'string', 'default' => 'EUR'],
        ], 'dependentSchemas' => ['card' => ['required' => ['currency']]]]);

        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['card'])], then: [new " . self::RUNTIME_ASSERT . "Present(properties: ['currency'])])"],
            $this->classAttributesOf($this->code($output, 'Pet.php')),
        );
        self::assertSame([], $this->messages($output));
    }

    public function testGroupsTheDependencyAsItsClass(): void
    {
        $code = $this->code($this->withRuntime(['type' => 'object', 'x-validator-groups' => ['api'], 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
        ], 'dependentSchemas' => ['a' => ['properties' => ['b' => ['minLength' => 2]]]]]), 'Pet.php');

        $groups = "groups: ['api', 'Default']";
        self::assertSame(
            ['RuntimeAssert\\IfThenElse(if: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['a'])], then: [new " . self::RUNTIME_ASSERT . "Property(name: 'b', constraints: [new " . self::ASSERT . "Length(min: 2)], $groups)], $groups)"],
            $this->classAttributesOf($code),
        );
    }

    public function testNeedsNewInInitializers(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
        ], 'dependentSchemas' => ['a' => ['required' => ['b']], 'b' => false]], [], '8.0');

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::AT . ': "dependentSchemas" goes into the attribute as "new", which PHP 8.0 does not allow; it is not checked.'], $this->messages($output));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
        ], 'dependentSchemas' => ['a' => ['required' => ['b']]]], [], '7.4', [], 'v6.4.1', self::RUNTIME + ['doctrine/annotations' => '2.0.2']);

        self::assertStringContainsString(' * @RuntimeAssert\\IfThenElse(', $this->code($output, 'Pet.php'));
        self::assertStringContainsString('@\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\Present(properties={"b"})', $this->code($output, 'Pet.php'));
        self::assertSame([], $this->messages($output));
    }

    public function testNeedsTheRuntime(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
        ], 'dependentSchemas' => ['a' => ['required' => ['b']]]], [], '8.2', [], 'v7.1.0', ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.1.0']);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(
            [self::AT . ': "dependentSchemas" is checked by msstc4symfony/dto-generator-bridge-runtime 1.2 or newer, which the project does not install; it is not checked.'],
            $this->messages($output),
        );
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
