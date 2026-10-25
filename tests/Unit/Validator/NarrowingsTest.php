<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * A child's schema that declares a property of its parent again with a narrower schema: the property stays the
 * parent's, and the child checks what its declaration adds with Property.
 */
final class NarrowingsTest extends TestCase
{
    use GeneratesDtos;

    private const RUNTIME = ['msstc4symfony/dto-generator-bridge-runtime' => 'v1.2.0'];

    private const ASSERT = '\\Symfony\\Component\\Validator\\Constraints\\';

    public function testChecksWhatTheChildsDeclarationAdds(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => [
                'code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 8],
                'same' => ['type' => 'string', 'maxLength' => 3],
                'own' => ['type' => 'string'],
                'tag' => ['type' => 'string', 'pattern' => '^t'],
            ]],
        ]], ['Base' => ['type' => 'object', 'properties' => [
            'code' => ['type' => 'string', 'maxLength' => 8],
            'same' => ['type' => 'string', 'maxLength' => 3],
            'tag' => ['type' => 'string'],
        ]]]);

        self::assertSame([
            'RuntimeAssert\\Property(name: \'code\', constraints: [new ' . self::ASSERT . 'Length(min: 2, max: 8)])',
            'RuntimeAssert\\Property(name: \'tag\', constraints: [new ' . self::ASSERT . "Regex(pattern: '/^t/uD')])",
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertSame(['Assert\\Length(max: 8)'], $this->attributesOf($this->code($output, 'Base.php'), 'code'));
    }

    public function testChecksThatAPropertyTheChildRequiresIsSet(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'required' => ['nick_name', 'id', 'opt'], 'properties' => [
                'nick_name' => ['type' => 'string', 'minLength' => 1],
                'opt' => ['type' => ['string', 'null']],
            ]],
            ['required' => ['nick_name']],
        ]], ['Base' => ['type' => 'object', 'required' => ['id'], 'properties' => [
            'nick_name' => ['type' => 'string'],
            'id' => ['type' => 'string'],
        ]]]);

        self::assertSame([
            'RuntimeAssert\\Property(name: \'nickName\', constraints: [new ' . self::ASSERT . 'Length(min: 1)])',
            "RuntimeAssert\\Present(properties: ['nickName'])",
        ], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testLeavesNullAndWhatTheParentRequiresAlone(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'required' => ['opt', 'req', 'any', 'ref', 'mine'], 'properties' => ['own' => ['type' => 'string'], 'mine' => ['type' => ['string', 'null']]]],
        ]], [
            'Base' => ['type' => 'object', 'required' => ['req'], 'properties' => [
                'opt' => ['type' => ['string', 'null']],
                'req' => ['type' => ['string', 'null']],
                'any' => [],
                'ref' => ['$ref' => '#/components/schemas/Maybe'],
                'mine' => ['type' => 'string'],
            ]],
            'Maybe' => ['type' => ['integer', 'null']],
        ]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testChecksEachConstraintOnceAndThoseOfAParentInGroupsOfItsOwn(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'maxLength' => 8], 'own' => ['type' => 'string']]],
            ['properties' => ['code' => ['type' => 'string', 'maxLength' => 8]]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'maxLength' => 8, 'x-validator-groups' => ['api']]]]]);

        self::assertSame(['RuntimeAssert\\Property(name: \'code\', constraints: [new ' . self::ASSERT . 'Length(max: 8)])'], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testLeavesTheTraversalOfAnObjectToTheParentsProperty(): void
    {
        $variant = ['type' => 'object', 'required' => ['kind'], 'properties' => [
            'kind' => ['type' => 'string'],
            'cfg' => ['$ref' => '#/components/schemas/Cfg'],
            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Cfg']],
        ]];
        $output = $this->withRuntime(
            ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            ['Cat' => $variant, 'Dog' => $variant, 'Cfg' => ['type' => 'object', 'properties' => ['on' => ['type' => 'boolean']]]],
        );

        self::assertSame(["RuntimeAssert\\Present(properties: ['kind'])"], $this->classAttributesOf($this->code($output, 'Cat.php')));
        self::assertSame(['Assert\\Valid'], $this->attributesOf($this->code($output, 'Pet.php'), 'cfg'));
        self::assertSame(['Assert\\Valid'], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
        self::assertSame([], array_values(array_filter($this->messages($output), static fn (string $m): bool => strpos($m, 'may go unvalidated') !== false || strpos($m, 'are not checked') !== false)));
    }

    public function testChecksWhatFollowsAConstraintTheParentHas(): void
    {
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['tag' => ['type' => 'string', 'maxLength' => 3, 'pattern' => '^t']]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['tag' => ['type' => 'string', 'maxLength' => 3]]]]);

        self::assertSame(['RuntimeAssert\\Property(name: \'tag\', constraints: [new ' . self::ASSERT . "Regex(pattern: '/^t/uD')])"], $this->classAttributesOf($this->code($output, 'Pet.php')));
    }

    public function testWarnsThatAnObjectTheParentDoesNotTraverseIsNotValidated(): void
    {
        $cfg = ['$ref' => '#/components/schemas/Cfg'];
        $output = $this->withRuntime(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['cfg' => $cfg, 'other' => $cfg, 'kept' => $cfg]],
        ]], [
            'Base' => ['type' => 'object', 'properties' => [
                'cfg' => $cfg + ['x-validator-groups' => ['api']],
                'other' => $cfg + ['x-validator-skip' => true],
                'kept' => $cfg,
            ]],
            'Cfg' => ['type' => 'object', 'properties' => ['on' => ['type' => 'boolean']]],
        ]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        $message = static fn (string $name): string => 'warning /api.yaml#/components/schemas/Pet/allOf/1/properties/' . $name . ': The parent skips "' . $name . '" or checks it in groups of its own, so the object it holds may go unvalidated in the groups of this class: Symfony allows no Valid inside Property.';
        $warnings = array_values(array_filter($this->messages($output), static fn (string $m): bool => strpos($m, 'may go unvalidated') !== false));
        self::assertSame([$message('cfg'), $message('other')], $warnings);
    }

    public function testSaysNothingOfTheRuntimeWhenTheChildAddsNothing(): void
    {
        $output = $this->generate(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'maxLength' => 8], 'own' => ['type' => 'string']]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'maxLength' => 8]]]]);

        self::assertSame([], array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'runtime') !== false)));
    }

    public function testNeedsTheRuntime(): void
    {
        $output = $this->generate(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'minLength' => 2]]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]]]);

        self::assertSame([], $this->classAttributesOf($this->code($output, 'Pet.php')));
        self::assertContains('warning /api.yaml#/components/schemas/Pet: "properties" is checked by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.', $this->messages($output));
    }

    public function testWarnsThatAVariantsObjectOverTheParentsMapIsNotChecked(): void
    {
        $output = $this->withRuntime(
            ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'links' => ['type' => 'object']], 'oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
            ['Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string', 'maxLength' => 3]]]]]]]],
        );

        self::assertContains(
            'warning /api.yaml#/components/schemas/Cat/allOf/1/properties/links: "links" keeps the type array<array-key, mixed>|null of the parent, which holds no object to traverse, so the constraints of the object this class declares are not checked.',
            $this->messages($output),
        );
        self::assertSame([], array_values(array_filter($this->messages($output), static fn (string $m): bool => strpos($m, 'may go unvalidated') !== false)));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     */
    private function withRuntime(array $pet, array $others): Output
    {
        return $this->generate($pet, $others, '8.2', [], 'v7.1.0', self::RUNTIME);
    }
}
