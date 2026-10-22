<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * oneOf and anyOf beside the properties of a class, which the generator does not type: ExactlyOneOf, and Not of a Not per
 * member, of their members' properties and requirements.
 */
final class AlternativesTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.3.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    private const RUNTIME_ASSERT = '\\Msstc4Symfony\\DtoGeneratorBridge\\Runtime\\Constraints\\';

    private const AT = 'warning /api.yaml#/components/schemas/Pet';

    private const UNTYPED = self::AT . ': "oneOf" and "anyOf" beside "properties" are not represented; the class keeps only its properties.';

    private const PROPERTIES = [
        'name' => ['type' => 'string'],
        'status' => ['type' => 'string', 'enum' => ['queued', 'completed']],
        'conclusion' => ['type' => 'string', 'x-php-name' => 'outcome'],
    ];

    public function testChecksThatExactlyOneMemberOfAOneOfHolds(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => self::PROPERTIES, 'oneOf' => [
            ['properties' => ['status' => ['const' => 'completed']], 'required' => ['status', 'conclusion']],
            ['title' => 'Pending', 'type' => 'object', 'properties' => ['status' => ['enum' => ['queued']]]],
        ]]);

        self::assertSame([
            'RuntimeAssert\\ExactlyOneOf(constraints: [new ' . self::ASSERT . 'Sequentially(constraints: [new ' . self::RUNTIME_ASSERT . "Property(name: 'status', constraints: [new " . self::ASSERT . 'IdenticalTo(value: PetStatus::COMPLETED)]), new ' . self::RUNTIME_ASSERT . "Present(properties: ['status', 'outcome'])]), new " . self::RUNTIME_ASSERT . "Property(name: 'status', constraints: [new " . self::ASSERT . 'Choice(choices: [PetStatus::QUEUED])])])',
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::UNTYPED], $this->messages($output));
    }

    public function testChecksThatAMemberOfAnAnyOfHolds(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => self::PROPERTIES, 'anyOf' => [['required' => ['name']], ['required' => ['conclusion']]]]);

        self::assertSame(
            ['RuntimeAssert\\Not(constraints: [new ' . self::RUNTIME_ASSERT . 'Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['name'])]), new " . self::RUNTIME_ASSERT . 'Not(constraints: [new ' . self::RUNTIME_ASSERT . "Present(properties: ['outcome'])])], message: 'This value should satisfy at least one of the given constraints.')"],
            $this->classAttributesOf($this->code($output, 'Pet.php')),
        );
        self::assertSame([self::UNTYPED], $this->messages($output));
    }

    /**
     * @dataProvider unchecked
     *
     * @param array<string, mixed> $member
     */
    public function testWarnsAboutAMemberItCannotCheck(array $member, string $at): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => self::PROPERTIES, 'oneOf' => [['required' => ['name']], $member]], ['Other' => ['type' => 'object']]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertContains(self::AT . $at . ': "oneOf" is checked on a class only with members of "properties" that check one value each and "required"; it is not checked.', $this->messages($output));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unchecked(): iterable
    {
        yield 'a $ref' => [['$ref' => '#/components/schemas/Other'], '/oneOf/1'];
        yield 'no other properties' => [['required' => ['status'], 'additionalProperties' => false], '/oneOf/1'];
        yield 'items beside a requirement' => [['required' => ['status'], 'items' => ['type' => 'string']], '/oneOf/1'];
        yield 'an allOf' => [['allOf' => [['required' => ['name']]]], '/oneOf/1'];
        yield 'a nested union' => [['anyOf' => [['required' => ['name']]]], '/oneOf/1'];
        yield 'a nested oneOf' => [['oneOf' => [['required' => ['name']]]], '/oneOf/1'];
        yield 'a discriminator' => [['properties' => ['status' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'status']], '/oneOf/1'];
        yield 'an enum' => [['enum' => [['name' => 'a']]], '/oneOf/1'];
        yield 'another type' => [['type' => ['object', 'array']], '/oneOf/1'];
        yield 'items' => [['items' => ['type' => 'string']], '/oneOf/1'];
        yield 'a schema of other properties' => [['additionalProperties' => ['type' => 'string']], '/oneOf/1'];
        yield 'a keyword that checks the object' => [['minProperties' => 2], '/oneOf/1'];
        yield 'a property that is an object' => [['properties' => ['name' => ['type' => 'string', 'properties' => ['a' => ['type' => 'string']]]]], '/oneOf/1'];
        yield 'a property behind a $ref' => [['properties' => ['name' => ['$ref' => '#/components/schemas/Other']]], '/oneOf/1'];
    }

    public function testChecksNoMemberWithAPropertyItCannotCheck(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => self::PROPERTIES, 'oneOf' => [
            ['required' => ['name']],
            ['required' => ['status'], 'properties' => ['name' => ['type' => 'string', 'properties' => ['a' => ['type' => 'string']]]]],
        ]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::UNTYPED, self::AT . '/oneOf/1: "oneOf" is checked on a class only with members of "properties" that check one value each and "required"; it is not checked.'], $this->messages($output));
    }

    public function testWarnsAboutAMemberOfAOneOfThatChecksNothing(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => self::PROPERTIES, 'oneOf' => [['required' => ['name']], ['title' => 'Anything']]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::UNTYPED, self::AT . '/oneOf/1: A member of "oneOf" checks nothing a class holds; "oneOf" is not checked.'], $this->messages($output));
    }

    public function testChecksNothingForAnAnyOfOneOfWhoseMembersHoldsAlways(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => self::PROPERTIES, 'anyOf' => [['required' => ['name']], ['title' => 'Anything']]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::UNTYPED], $this->messages($output));
    }

    public function testGivesTheNestedCompositesTheGroupsOfTheClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'x-validator-groups' => ['api'], 'properties' => self::PROPERTIES, 'anyOf' => [['required' => ['name']], ['required' => ['status']]]]);

        self::assertSame(3, substr_count($this->code($output, 'Pet.php'), "groups: ['api', 'Default']"));
    }

    public function testLeavesTheMembersOfADiscriminatedUnionToTheirClasses(): void
    {
        $output = $this->withRuntime(['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']], [
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertStringNotContainsString('ExactlyOneOf', $this->code($output, 'Pet.php'));
        self::assertSame([], $this->messages($output));
    }

    public function testLeavesAUnionOfTypesToTheProperty(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['v' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testWarnsAboutAMemberThatNamesWhatTheClassCannotCheck(): void
    {
        $properties = self::PROPERTIES + ['kind' => ['type' => 'string', 'default' => 'pet']];
        $unknown = $this->withRuntime(['type' => 'object', 'properties' => $properties, 'anyOf' => [['required' => ['name']], ['required' => ['owner']]]]);
        $defaulted = $this->withRuntime(['type' => 'object', 'properties' => $properties, 'anyOf' => [['required' => ['name']], ['required' => ['kind']]]]);

        self::assertSame([], $this->classAttributesOf($this->code($unknown, 'Pet.php')));
        self::assertSame([self::UNTYPED, self::AT . ': "anyOf" names "owner", which is not a property of this class; it is not checked.'], $this->messages($unknown));
        self::assertSame([], $this->classAttributesOf($this->code($defaulted, 'Pet.php')));
        self::assertSame([self::UNTYPED, self::AT . ': "anyOf" names "kind", which has a default and is always set; it is not checked.'], $this->messages($defaulted));
    }

    public function testChecksTheUnionOfEverySchemaOfTheClassOnce(): void
    {
        $output = $this->withRuntime(['x-php-all-of' => 'merge', 'allOf' => [
            ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'anyOf' => [['required' => ['a']], ['required' => ['b']]]],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'anyOf' => [['required' => ['b']], ['required' => ['a']]]],
        ]]);

        self::assertCount(2, $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testLeavesTheUnionOfAParentToItsClass(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => self::PROPERTIES, 'anyOf' => [['required' => ['name']], ['required' => ['status']]]], [
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['properties' => ['lives' => ['type' => 'integer']]]]],
        ]);

        self::assertCount(1, $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([], $this->classAttributesOf($this->code($output, 'Cat.php')));
        self::assertSame([self::UNTYPED], $this->messages($output));
    }

    public function testLeavesADiscriminatedBaseWithPropertiesToItsVariants(): void
    {
        $output = $this->withRuntime(['type' => 'object', 'properties' => ['kind' => ['type' => 'string']], 'oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']], [
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertStringNotContainsString('ExactlyOneOf', $this->code($output, 'Pet.php'));
        self::assertSame([], array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'is checked on a class only') !== false));
    }

    public function testNeedsTheRuntimeOfNotForAnAnyOf(): void
    {
        $output = $this->generate(
            ['type' => 'object', 'properties' => self::PROPERTIES, 'anyOf' => [['required' => ['name']], ['required' => ['status']]]],
            [],
            '8.2',
            [],
            'v7.1.0',
            ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.1.0'],
        );

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::UNTYPED, self::AT . ': "anyOf" is checked by msstc4symfony/dto-generator-bridge-runtime 1.2 or newer, which the project does not install; it is not checked.'], $this->messages($output));
    }

    public function testNeedsTheRuntimeOfOneOf(): void
    {
        $output = $this->generate(
            ['type' => 'object', 'properties' => self::PROPERTIES, 'oneOf' => [['required' => ['name']], ['required' => ['status']]]],
            [],
            '8.2',
            [],
            'v7.1.0',
            ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0'],
        );

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame([self::UNTYPED, self::AT . ': "oneOf" is checked by msstc4symfony/dto-generator-bridge-runtime 1.3 or newer, which the project does not install; it is not checked.'], $this->messages($output));
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
