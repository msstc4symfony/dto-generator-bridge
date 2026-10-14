<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * propertyNames against the names of the properties a class declares, checked while generating.
 */
final class DeclaredNamesTest extends TestCase
{
    use GeneratesDtos;

    private const AT = 'warning /api.yaml#/components/schemas/Pet';

    public function testWarnsAboutADeclaredNameThePatternRejects(): void
    {
        $output = $this->generate(['type' => 'object', 'propertyNames' => ['pattern' => '^[a-z]+$'], 'properties' => [
            'name' => ['type' => 'string'],
            'Tag' => ['type' => 'string'],
        ]]);

        self::assertSame([self::AT . ': "propertyNames" rejects "Tag", the name of a declared property, which can never be set.'], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testWarnsAboutNamesOutsideTheLengthsConstantAndEnum(): void
    {
        $output = $this->generate(['type' => 'object', 'allOf' => [
            ['propertyNames' => ['minLength' => 2, 'maxLength' => 3]],
            ['propertyNames' => ['enum' => ['ab', 'abc', 'ä']]],
            ['propertyNames' => ['const' => 'ab']],
        ], 'properties' => [
            'ab' => ['type' => 'string'],
            'abc' => ['type' => 'string'],
            'ä' => ['type' => 'string', 'x-php-name' => 'ae'],
            '7' => ['type' => 'string', 'x-php-name' => 'seven'],
        ]]);

        self::assertSame([
            self::AT . '/allOf/0: "propertyNames" rejects "ä", the name of a declared property, which can never be set.',
            self::AT . '/allOf/0: "propertyNames" rejects "7", the name of a declared property, which can never be set.',
            self::AT . '/allOf/1: "propertyNames" rejects "7", the name of a declared property, which can never be set.',
            self::AT . '/allOf/2: "propertyNames" rejects "abc", the name of a declared property, which can never be set.',
            self::AT . '/allOf/2: "propertyNames" rejects "ä", the name of a declared property, which can never be set.',
            self::AT . '/allOf/2: "propertyNames" rejects "7", the name of a declared property, which can never be set.',
        ], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testChecksTheInheritedNamesAndLeavesTheParentsSchemaToIt(): void
    {
        $output = $this->generate(['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'propertyNames' => ['maxLength' => 1]],
        ]], ['Base' => ['type' => 'object', 'properties' => ['aa' => ['type' => 'string'], 'zz' => ['type' => 'string']], 'propertyNames' => ['pattern' => '^a']]]);

        self::assertSame([
            'warning /api.yaml#/components/schemas/Base: "propertyNames" rejects "b", the name of a declared property, which can never be set.',
            self::AT . '/allOf/1: "propertyNames" rejects "aa", the name of a declared property, which can never be set.',
            self::AT . '/allOf/1: "propertyNames" rejects "zz", the name of a declared property, which can never be set.',
            'warning /api.yaml#/components/schemas/Base: "propertyNames" rejects "zz", the name of a declared property, which can never be set.',
        ], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testRejectsEveryNameByASchemaFalse(): void
    {
        $output = $this->generate(['type' => 'object', 'allOf' => [['propertyNames' => false], ['propertyNames' => true]], 'properties' => [
            'name' => ['type' => 'string'],
        ]]);

        self::assertSame([self::AT . '/allOf/0: "propertyNames" rejects "name", the name of a declared property, which can never be set.'], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testRejectsByTypeAndWholeLengths(): void
    {
        $output = $this->generate(['type' => 'object', 'allOf' => [
            ['propertyNames' => ['type' => 'integer']],
            ['propertyNames' => ['type' => ['string', 'null'], 'maxLength' => 2.0]],
            ['propertyNames' => ['enum' => ['a' => 'name']]],
        ], 'properties' => ['name' => ['type' => 'string']]]);

        self::assertSame([
            self::AT . '/allOf/0: "propertyNames" rejects "name", the name of a declared property, which can never be set.',
            self::AT . '/allOf/1: "propertyNames" rejects "name", the name of a declared property, which can never be set.',
        ], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testChecksTheNamesOfAClassTheValidatorSkips(): void
    {
        $output = $this->generate(['type' => 'object', 'x-validator-skip' => true, 'propertyNames' => ['maxLength' => 2], 'properties' => [
            'name' => ['type' => 'string'],
        ]]);

        self::assertSame([self::AT . ': "propertyNames" rejects "name", the name of a declared property, which can never be set.'], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testChecksTheNamesWithoutTheValidator(): void
    {
        $output = $this->generate(['type' => 'object', 'propertyNames' => ['maxLength' => 2], 'properties' => [
            'name' => ['type' => 'string'],
        ]], [], '8.2', [], null);

        self::assertSame([self::AT . ': "propertyNames" rejects "name", the name of a declared property, which can never be set.'], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testDecidesNothingByMalformedKeywords(): void
    {
        $output = $this->generate(['type' => 'object', 'allOf' => [
            ['propertyNames' => ['type' => 5]],
            ['propertyNames' => ['type' => []]],
            ['propertyNames' => ['type' => ['a' => 'integer']]],
            ['propertyNames' => ['type' => [1]]],
            ['propertyNames' => ['maxLength' => 2.5]],
            ['propertyNames' => ['maxLength' => -1]],
        ], 'properties' => ['name' => ['type' => 'string']]]);

        self::assertSame([], $this->namesMessages($output->diagnostics()->all()));
    }

    public function testLeavesKeywordsItCannotRunAlone(): void
    {
        $output = $this->generate(['type' => 'object', 'propertyNames' => ['pattern' => '(?<=a)\\p{Foo}', 'format' => 'email', 'minLength' => 'two', 'enum' => 'x'], 'properties' => [
            'name' => ['type' => 'string'],
        ]]);

        self::assertSame([], $this->namesMessages($output->diagnostics()->all()));
    }

    /**
     * @param list<Diagnostic> $diagnostics
     *
     * @return list<string>
     */
    private function namesMessages(array $diagnostics): array
    {
        $messages = [];
        foreach ($diagnostics as $diagnostic) {
            if (strpos($diagnostic->toString(), 'rejects') !== false) {
                $messages[] = str_replace($this->root, '', $diagnostic->toString());
            }
        }

        return $messages;
    }
}
