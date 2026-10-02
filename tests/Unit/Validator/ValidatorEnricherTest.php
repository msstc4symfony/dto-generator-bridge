<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use FilesystemIterator;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use Msstc4Symfony\DtoGeneratorBridge\SymfonyExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

/**
 * The real generator with the bridge, on a project whose composer.lock pins symfony/validator.
 */
final class ValidatorEnricherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dto-bridge-validator-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);
    }

    public function testConstrainsStringsByRequirementLengthAndPattern(): void
    {
        $code = $this->pet(['type' => 'object', 'required' => ['name'], 'properties' => [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10, 'pattern' => '^a/b$'],
            'nick' => ['type' => 'string', 'maxLength' => 5],
        ]]);

        self::assertStringContainsString("use Symfony\\Component\\Validator\\Constraints as Assert;\n", $code);
        self::assertSame(['Assert\\NotNull', 'Assert\\Length(min: 1, max: 10)', "Assert\\Regex(pattern: '/^a\\/b\$/u')"], $this->attributesOf($code, 'name'));
        self::assertSame(['Assert\\Length(max: 5)'], $this->attributesOf($code, 'nick'));
    }

    public function testConstrainsNumbersByBoundsAndMultiples(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 30],
            'weight' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 99.5, 'multipleOf' => 0.5],
            'legs' => ['type' => 'integer', 'minimum' => 2],
            'tail' => ['type' => 'integer', 'exclusiveMaximum' => 3],
        ]]);

        self::assertSame(['Assert\\Range(min: 0, max: 30)'], $this->attributesOf($code, 'age'));
        self::assertSame(['Assert\\LessThanOrEqual(value: 99.5)', 'Assert\\GreaterThan(value: 0)', 'Assert\\DivisibleBy(value: 0.5)'], $this->attributesOf($code, 'weight'));
        self::assertSame(['Assert\\GreaterThanOrEqual(value: 2)'], $this->attributesOf($code, 'legs'));
        self::assertSame(['Assert\\LessThan(value: 3)'], $this->attributesOf($code, 'tail'));
    }

    public function testConstrainsCollectionsAndTheirItems(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'uniqueItems' => true, 'items' => ['type' => 'string', 'maxLength' => 8]],
            'scores' => ['type' => 'object', 'maxProperties' => 4, 'additionalProperties' => ['type' => 'integer', 'minimum' => 0]],
        ]]);

        self::assertSame(['Assert\\Count(min: 1, max: 3)', 'Assert\\Unique', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 8)])'], $this->attributesOf($code, 'tags'));
        self::assertSame(['Assert\\Count(max: 4)', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\GreaterThanOrEqual(value: 0)])'], $this->attributesOf($code, 'scores'));
    }

    public function testCascadesIntoNestedObjects(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'owner' => ['$ref' => '#/components/schemas/Owner'],
            'friends' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Owner']],
        ]], ['Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]);

        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'owner'));
        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'friends'));
    }

    public function testDecidesCascadingByTheSchemaOfTheItems(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'pals' => ['type' => 'array', 'items' => ['anyOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'null']]]],
            'dates' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']],
            'loose' => ['type' => 'array', 'minItems' => 1],
            'anything' => ['type' => 'object', 'maxProperties' => 2, 'additionalProperties' => true],
            'tags' => ['$ref' => '#/components/schemas/Tags'],
            'born' => ['type' => 'string', 'format' => 'date-time'],
        ]], [
            'Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 4]],
        ]);

        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'pals'));
        self::assertSame([], $this->attributesOf($code, 'dates'));
        self::assertSame(['Assert\\Count(min: 1)'], $this->attributesOf($code, 'loose'));
        self::assertSame(['Assert\\Count(max: 2)'], $this->attributesOf($code, 'anything'));
        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 4)])'], $this->attributesOf($code, 'tags'));
        self::assertSame([], $this->attributesOf($code, 'born'));
    }

    public function testPrefersTheKeywordsBesideAReferenceAndReadsTheRestFromItsTarget(): void
    {
        $schemas = [
            'Email' => ['type' => 'string', 'format' => 'email'],
            'Cat' => ['type' => 'string', 'const' => 'cat'],
            'Status' => ['type' => 'string', 'enum' => ['active', 'gone']],
            'Blank' => ['type' => 'object'],
        ];
        $code = $this->pet(['type' => 'object', 'required' => ['maybe'], 'properties' => [
            'address' => ['$ref' => '#/components/schemas/Email', 'format' => 'ipv4'],
            'kind' => ['$ref' => '#/components/schemas/Cat'],
            'status' => ['$ref' => '#/components/schemas/Status', 'enum' => ['active']],
            'blank' => ['$ref' => '#/components/schemas/Blank'],
            'maybe' => ['type' => ['string', 'null'], 'maxLength' => 2],
            'odd' => ['type' => 'integer', 'minimum' => '5'],
        ]], $schemas, '8.0');

        self::assertSame(["Assert\\Ip(version: '4')"], $this->attributesOf($code, 'address'));
        self::assertSame(["Assert\\IdenticalTo(value: 'cat')"], $this->attributesOf($code, 'kind'));
        self::assertSame(["Assert\\Choice(choices: ['active'])"], $this->attributesOf($code, 'status'));
        self::assertSame([], $this->attributesOf($code, 'blank'));
        self::assertSame(['Assert\\Length(max: 2)'], $this->attributesOf($code, 'maybe'));
        self::assertSame([], $this->attributesOf($code, 'odd'));
    }

    public function testWarnsAboutGroupNamesThatAreNoNames(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['', 'api', 3]]]]);

        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($this->code($output, 'Pet.php'), 'a'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/a: x-validator-groups names a group that is no name; it is left out.'], $this->messages($output));
    }

    public function testLeavesAFormatMappedToAClassToThatClass(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid']]], [], '8.2', [], 'v7.1.0', [], true, ['uuid' => ['type' => 'Symfony\\Component\\Uid\\Uuid']]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'id'));
    }

    public function testReadsTheConstraintsOfAReferencedScalar(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'email' => ['$ref' => '#/components/schemas/Email'],
            'contact' => ['$ref' => '#/components/schemas/Contact'],
            'short' => ['$ref' => '#/components/schemas/Email', 'maxLength' => 20],
        ]], ['Email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 64], 'Contact' => ['$ref' => '#/components/schemas/Email']]);

        self::assertSame(['Assert\\Length(max: 64)', "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'email'));
        self::assertSame(['Assert\\Length(max: 64)', "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'contact'));
        self::assertSame(['Assert\\Length(max: 20)', "Assert\\Email(mode: 'html5')"], $this->attributesOf($code, 'short'));
    }

    public function testChecksFormatsWithExplicitOptions(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'ip4' => ['type' => 'string', 'format' => 'ipv4'],
            'ip6' => ['type' => 'string', 'format' => 'ipv6'],
            'host' => ['type' => 'string', 'format' => 'hostname'],
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'site' => ['type' => 'string', 'format' => 'uri'],
            'born' => ['type' => 'string', 'format' => 'date-time'],
        ]]);

        self::assertSame(["Assert\\Ip(version: '4')"], $this->attributesOf($code, 'ip4'));
        self::assertSame(["Assert\\Ip(version: '6')"], $this->attributesOf($code, 'ip6'));
        self::assertSame(['Assert\\Hostname(requireTld: false)'], $this->attributesOf($code, 'host'));
        self::assertSame(['Assert\\Uuid'], $this->attributesOf($code, 'id'));
        self::assertSame([], $this->attributesOf($code, 'site'));
        self::assertStringNotContainsString('Url', $code);
        self::assertSame([], $this->attributesOf($code, 'born'));
    }

    public function testComparesWithAConstant(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'const' => 'cat'],
            'nothing' => ['type' => ['null', 'string'], 'const' => null],
            'shape' => ['type' => 'array', 'items' => ['type' => 'integer'], 'const' => [1, 2]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(["Assert\\IdenticalTo(value: 'cat')"], $this->attributesOf($code, 'kind'));
        self::assertSame(['Assert\\IsNull'], $this->attributesOf($code, 'nothing'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/shape: "const" with an array or object has no Symfony constraint; it is not checked.', $this->messages($output));
    }

    public function testChoosesAmongEnumValuesWhereTheTargetHasNoEnums(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['active', 'gone']],
            'size' => ['type' => 'integer', 'enum' => [1, 2]],
            'maybe' => ['type' => ['string', 'null'], 'enum' => ['x', null]],
            'mood' => ['$ref' => '#/components/schemas/Plain', 'enum' => ['calm']],
            'level' => ['type' => 'integer', 'enum' => [1, 2], 'const' => 2],
        ]];
        $others = ['Plain' => ['type' => 'string']];

        $old = $this->pet($schema, $others, '8.0');
        $new = $this->pet($schema, $others, '8.2');

        self::assertSame(["Assert\\Choice(choices: ['active', 'gone'])"], $this->attributesOf($old, 'status'));
        self::assertSame(['Assert\\Choice(choices: [1, 2])'], $this->attributesOf($old, 'size'));
        self::assertSame(["Assert\\Choice(choices: ['x'])"], $this->attributesOf($old, 'maybe'));
        self::assertSame(["Assert\\Choice(choices: ['calm'])"], $this->attributesOf($old, 'mood'));
        self::assertSame(['Assert\\Choice(choices: [1, 2])', 'Assert\\IdenticalTo(value: 2)'], $this->attributesOf($old, 'level'));
        self::assertSame([], $this->attributesOf($new, 'status'));
        self::assertSame([], $this->attributesOf($new, 'maybe'));
        // The generator types a $ref by its target and ignores the enum beside it: only the constraint checks it.
        self::assertSame(["Assert\\Choice(choices: ['calm'])"], $this->attributesOf($new, 'mood'));
    }

    public function testAddsGroupsAndKeepsTheDefaultOne(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api']],
            'b' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api', 'Default']],
            'c' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api'], 'x-validator-groups-exclusive' => true],
            'd' => ['type' => 'string', 'maxLength' => 1],
        ]], [], '8.2', ['groups' => ['admin']]);

        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($code, 'a'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($code, 'b'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api'])"], $this->attributesOf($code, 'c'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['admin', 'Default'])"], $this->attributesOf($code, 'd'));
    }

    public function testSkipsAPropertyThatOptsOut(): void
    {
        $code = $this->pet(['type' => 'object', 'required' => ['name'], 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 3, 'x-validator-skip' => true],
        ]]);

        self::assertStringNotContainsString('Assert', $code);
    }

    public function testWritesNothingWhenTheValidatorIsOff(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 3]]];

        self::assertStringNotContainsString('Assert', $this->pet($schema, [], '8.2', ['validator' => false]));
        self::assertStringNotContainsString('Assert', $this->pet($schema, [], '8.2', [], null));
    }

    public function testWarnsOnceWhenForcedWithoutTheValidatorInstalled(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'string', 'maxLength' => 3]]], [], '8.2', ['validator' => true], null);

        self::assertStringContainsString('#[Assert\\Length(max: 3)]', $this->code($output, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.'], $this->messages($output));
    }

    public function testDecidesOncePerRunAcrossDocuments(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3]]];
        $output = $this->generate($schema, [], '8.2', ['validator' => true], null, [], true, [], [], ['other' => $schema]);

        self::assertSame(['warning /api.yaml#: symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.'], $this->messages($output));
    }

    public function testWritesNothingForASymfonyItDoesNotSupport(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'string', 'maxLength' => 3]]], [], '8.2', [], 'v4.4.49');

        self::assertStringNotContainsString('Assert', $this->code($output, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: The bridge writes constraints for symfony/validator 5.4 or newer, the project has 4.4; none are written.'], $this->messages($output));
    }

    public function testWritesAnnotationsForPhp74(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 3]]], [], '7.4', [], 'v6.4.1', ['doctrine/annotations' => '2.0.2']);
        $code = $this->code($output, 'Pet.php');

        self::assertStringContainsString("use Symfony\\Component\\Validator\\Constraints as Assert;\n", $code);
        self::assertStringContainsString("     * @Assert\\Length(max=3)\n", $code);
        self::assertSame([], $this->messages($output));
    }

    public function testWarnsWhenAnnotationsCannotBeRead(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'string', 'maxLength' => 3]]];

        $missing = $this->generate($schema, [], '7.4', [], 'v6.4.1');
        self::assertStringContainsString('@Assert\\Length(max=3)', $this->code($missing, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: symfony/validator reads annotations through doctrine/annotations, which the project does not install.'], $this->messages($missing));

        $strict = $this->generate($schema, [], '7.4', [], 'v7.1.0', ['doctrine/annotations' => '2.0.2']);
        self::assertSame(['error /api.yaml#: symfony/validator 7.1 reads no annotations, and PHP 7.4 has no attributes; no constraints are written.'], $this->messages($strict));

        $loose = $this->generate($schema, [], '7.4', [], 'v7.1.0', [], false);
        self::assertStringNotContainsString('Assert', $this->code($loose, 'Pet.php'));
        self::assertSame(['warning /api.yaml#: symfony/validator 7.1 reads no annotations, and PHP 7.4 has no attributes; no constraints are written.'], $this->messages($loose));
    }

    public function testEscapesOnlyTheDelimiterAndTranslatesUnicodeEscapes(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'path' => ['type' => 'string', 'pattern' => '^a\/b/c$'],
            'accent' => ['type' => 'string', 'pattern' => '^\\u00e9+$'],
            'broken' => ['type' => 'string', 'pattern' => '(a'],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(["Assert\\Regex(pattern: '/^a\\/b\\/c\$/u')"], $this->attributesOf($code, 'path'));
        self::assertSame(["Assert\\Regex(pattern: '/^\\x{00e9}+\$/u')"], $this->attributesOf($code, 'accent'));
        self::assertSame([], $this->attributesOf($code, 'broken'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/broken: "pattern" is no regular expression PHP can run; it is not checked.'], $this->messages($output));
    }

    public function testKeepsValidOnThePropertyAtAnyDepth(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'teams' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/components/schemas/Owner']]],
            'words' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3]]],
        ]], ['Owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]);

        self::assertSame(['Assert\\Count(max: 2)', 'Assert\\Valid', 'Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Count(min: 1)])'], $this->attributesOf($code, 'teams'));
        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Length(max: 3)])])'], $this->attributesOf($code, 'words'));
    }

    public function testWarnsInsteadOfAllWhereAttributesAllowNoNew(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 8]],
        ]], [], '8.0');

        self::assertSame(['Assert\\Count(max: 3)'], $this->attributesOf($this->code($output, 'Pet.php'), 'tags'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/tags: The constraints of the items go inside All as "new", which PHP 8.0 does not allow in attributes; they are not checked.'], $this->messages($output));
    }

    public function testComparesWithTheConstantAsThePropertyHoldsIt(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'ratio' => ['type' => 'number', 'const' => 1],
            'count' => ['type' => 'integer', 'const' => 1],
            'odd' => ['type' => 'number', 'const' => 'x'],
            'level' => ['type' => 'integer', 'enum' => [1, 2], 'const' => 2],
            'tone' => ['type' => 'string', 'enum' => ['low', 'high'], 'const' => 'low'],
        ]]);
        $output = $this->generate(['type' => 'object', 'properties' => ['shade' => ['type' => 'string', 'enum' => ['dark'], 'const' => 'light']]]);

        self::assertSame(['Assert\\IdenticalTo(value: 1.0)'], $this->attributesOf($code, 'ratio'));
        self::assertSame(['Assert\\IdenticalTo(value: 1)'], $this->attributesOf($code, 'count'));
        self::assertSame(["Assert\\IdenticalTo(value: 'x')"], $this->attributesOf($code, 'odd'));
        self::assertSame(['Assert\\IdenticalTo(value: PetLevel::VALUE_2)'], $this->attributesOf($code, 'level'));
        self::assertSame(['Assert\\IdenticalTo(value: PetTone::LOW)'], $this->attributesOf($code, 'tone'));
        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'shade'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/shade: "const" is none of the enum values; it is not checked.'], $this->messages($output));
    }

    public function testLeavesKeywordsOfOneTypeOffAValueOfSeveral(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'code' => ['type' => ['string', 'integer'], 'minLength' => 2, 'minimum' => 3],
            'either' => ['anyOf' => [['type' => 'string', 'maxLength' => 3], ['type' => 'integer']]],
            'free' => ['maxLength' => 3],
            'born' => ['type' => 'string', 'format' => 'date-time', 'maxLength' => 30],
        ]], [], '8.2');
        $code = $this->code($output, 'Pet.php');

        self::assertSame([], $this->attributesOf($code, 'code'));
        self::assertSame([], $this->attributesOf($code, 'either'));
        self::assertSame([], $this->attributesOf($code, 'free'));
        self::assertSame([], $this->attributesOf($code, 'born'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/code: "minLength" check only a string, and the value may be of another type; they are not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/code: "minimum" check only a number, and the value may be of another type; they are not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/free: "maxLength" check only a string, and the value may be of another type; they are not checked.',
        ], $this->messages($output));
    }

    public function testReadsKeywordsWrittenAsOtherNumbersAndWarnsAboutTheRest(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 2.0, 'minLength' => -1],
            'nick' => ['type' => 'string', 'minLength' => 0, 'maxLength' => 2.5],
            'tag' => ['type' => 'string', 'minLength' => 0.0],
            'bio' => ['type' => 'string', 'maxLength' => 9.2233720368547758E18, 'minLength' => -1.0],
            'age' => ['type' => 'integer', 'minimum' => '5'],
        ]]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Length(max: 2)'], $this->attributesOf($code, 'name'));
        self::assertSame(['Assert\\Length(min: 0)'], $this->attributesOf($code, 'nick'));
        self::assertSame(['Assert\\Length(min: 0)'], $this->attributesOf($code, 'tag'));
        self::assertSame([], $this->attributesOf($code, 'bio'));
        self::assertSame([], $this->attributesOf($code, 'age'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/name: "minLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/nick: "maxLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/bio: "minLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/bio: "maxLength" must be a non-negative integer; it is not checked.',
            'warning /api.yaml#/components/schemas/Pet/properties/age: "minimum" must be a number; it is not checked.',
        ], $this->messages($output));
    }

    public function testChecksTheKeywordsBesideAReferenceAndThoseOfItsTarget(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'wide' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 200, 'pattern' => '^a'],
            'broken' => ['$ref' => '#/components/schemas/Code', 'pattern' => '(a'],
            'narrow' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 4, 'minLength' => 2],
            'step' => ['$ref' => '#/components/schemas/Step', 'multipleOf' => 3, 'minimum' => 1, 'const' => 6],
        ]], [
            'Code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z]+$'],
            'Step' => ['type' => 'integer', 'multipleOf' => 2, 'minimum' => 0, 'const' => 4],
        ]);

        self::assertSame(['Assert\\Length(min: 1, max: 64)', "Assert\\Regex(pattern: '/^a/u')", "Assert\\Regex(pattern: '/^[a-z]+\$/u')"], $this->attributesOf($code, 'wide'));
        self::assertSame(['Assert\\Length(min: 2, max: 4)', "Assert\\Regex(pattern: '/^[a-z]+\$/u')"], $this->attributesOf($code, 'narrow'));
        self::assertSame(['Assert\\Length(min: 1, max: 64)', "Assert\\Regex(pattern: '/^[a-z]+\$/u')"], $this->attributesOf($code, 'broken'));
        self::assertSame([
            'Assert\\GreaterThanOrEqual(value: 1)',
            'Assert\\DivisibleBy(value: 3)',
            'Assert\\DivisibleBy(value: 2)',
            'Assert\\IdenticalTo(value: 6)',
            'Assert\\IdenticalTo(value: 4)',
        ], $this->attributesOf($code, 'step'));
    }

    public function testWarnsThatAnEnumBesideAReferenceDoesNotNarrowAPhpEnum(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'status' => ['$ref' => '#/components/schemas/Status', 'enum' => ['active']],
        ]], ['Status' => ['type' => 'string', 'enum' => ['active', 'gone']]]);

        self::assertSame([], $this->attributesOf($this->code($output, 'Pet.php'), 'status'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/status: "enum" beside "$ref" narrows the referenced enum, which the generated enum type does not; the narrowing is not checked.'], $this->messages($output));
    }

    public function testWarnsAboutGroupsThatAreNoListAndDropsRepeatedOnes(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'a' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => 'api'],
            'b' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api', 'api']],
            'c' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['first' => 'api']],
            'd' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['api', 'admin']],
        ]], [], '8.2', ['groups' => ['admin']]);
        $code = $this->code($output, 'Pet.php');

        self::assertSame(['Assert\\Length(max: 1)'], $this->attributesOf($code, 'a'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($code, 'b'));
        self::assertSame(['Assert\\Length(max: 1)'], $this->attributesOf($code, 'c'));
        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'admin', 'Default'])"], $this->attributesOf($code, 'd'));
        self::assertSame([
            'warning /api.yaml#/components/schemas/Pet/properties/a: x-validator-groups must be a list of group names; the property gets no groups.',
            'warning /api.yaml#/components/schemas/Pet/properties/c: x-validator-groups must be a list of group names; the property gets no groups.',
        ], $this->messages($output));
    }

    public function testSaysWhichSettingAsksForAnnotationsOnATargetWithAttributes(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3]]], [], '8.2', [], 'v7.1.0', [], false, [], ['metadata' => 'annotations']);

        self::assertSame(['warning /api.yaml#: symfony/validator 7.1 reads no annotations, which target.metadata asks for; no constraints are written.'], $this->messages($output));
    }

    public function testAssumesAVersionReadingAnnotationsWhenNothingPinsOne(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3]]], [], '7.4', ['validator' => true], null);

        self::assertStringContainsString('@Assert\\Length(max=3)', $this->code($output, 'Pet.php'));
        self::assertSame([
            'warning /api.yaml#: symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.',
            'warning /api.yaml#: symfony/validator reads annotations through doctrine/annotations, which the project does not install.',
        ], $this->messages($output));
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     * @param array<string, mixed> $settings
     */
    private function pet(array $pet, array $others = [], string $php = '8.2', array $settings = [], ?string $validator = 'v7.1.0'): string
    {
        $output = $this->generate($pet, $others, $php, $settings, $validator);
        self::assertSame([], array_filter($this->messages($output), static fn (string $message): bool => strncmp($message, 'error', 5) === 0));

        return $this->code($output, 'Pet.php');
    }

    /**
     * @param array<string, mixed> $pet
     * @param array<string, mixed> $others
     * @param array<string, mixed> $settings
     * @param array<string, string> $packages more locked packages
     * @param array<string, array<string, string>> $formats
     * @param array<string, string> $target more target settings
     * @param array<string, array<string, mixed>> $documents more documents, each with its own Pet schema
     */
    private function generate(
        array $pet,
        array $others = [],
        string $php = '8.2',
        array $settings = [],
        ?string $validator = 'v7.1.0',
        array $packages = [],
        bool $strict = true,
        array $formats = [],
        array $target = [],
        array $documents = []
    ): Output {
        $locked = $validator === null ? $packages : ['symfony/validator' => $validator] + $packages;
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/composer.lock', (string) json_encode(['packages' => array_map(
            static fn (string $name, string $version): array => ['name' => $name, 'version' => $version],
            array_keys($locked),
            array_values($locked),
        )]));
        file_put_contents($this->root . '/api.yaml', (string) json_encode(['openapi' => '3.1.0', 'components' => ['schemas' => ['Pet' => $pet] + $others]], JSON_PRESERVE_ZERO_FRACTION));
        $sources = [['spec' => 'api.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'out']];
        foreach ($documents as $name => $schema) {
            file_put_contents($this->root . '/' . $name . '.yaml', (string) json_encode(['openapi' => '3.1.0', 'components' => ['schemas' => ['Pet' => $schema]]]));
            $sources[] = ['spec' => $name . '.yaml', 'namespace' => 'App\\Dto\\' . ucfirst($name), 'outputDir' => 'out/' . $name];
        }

        file_put_contents($this->root . '/dto-generator.yaml', (string) json_encode([
            'version' => 1,
            'target' => ['php' => $php, 'strict' => $strict] + $target,
            'verifyClasses' => false,
            'discoverExtensions' => false,
            'formats' => $formats === [] ? new stdClass() : $formats,
            'extensions' => [SymfonyExtension::class],
            'extensionConfig' => ['symfony' => $settings],
            'sources' => $sources,
        ]));

        return DtoGenerator::generator()(new Input($this->root . '/dto-generator.yaml', Mode::from(Mode::DRY_RUN)));
    }

    /**
     * The attributes written before a promoted constructor parameter, without "#[" and "]", on one line each.
     *
     * @return list<string>
     */
    private function attributesOf(string $code, string $property): array
    {
        $open = strpos($code, 'function __construct(');
        self::assertIsInt($open);
        $parameters = [];
        $current = '';
        $depth = 0;
        for ($i = $open + strlen('function __construct('), $length = strlen($code); $i < $length; $i++) {
            $char = $code[$i];
            if ($depth === 0 && ($char === ',' || $char === ')')) {
                $parameters[] = $current;
                $current = '';
                if ($char === ')') {
                    break;
                }

                continue;
            }

            $depth += (int) in_array($char, ['(', '['], true) - (int) in_array($char, [')', ']'], true);
            $current .= $char;
        }

        foreach ($parameters as $parameter) {
            if (preg_match('~\$' . $property . '\b~', $parameter) === 1) {
                preg_match_all('~#\[((?:[^\[\]]|\[(?:[^\[\]]|\[[^\[\]]*\])*\])*)\]~', $parameter, $matches);

                return array_map(static fn (string $attribute): string => (string) preg_replace(['~([(\[])\s+~', '~,?\s+([)\]])~', '~\s+~'], ['$1', '$1', ' '], trim($attribute)), $matches[1]);
            }
        }

        self::fail('No parameter $' . $property);
    }

    private function code(Output $output, string $file): string
    {
        foreach ($output->files() as $generated) {
            if ($generated->relativePath() === $file) {
                return $generated->contents();
            }
        }

        self::fail(sprintf('No %s among: %s', $file, implode(' ', $this->messages($output))));
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(
            fn (Diagnostic $diagnostic): string => str_replace($this->root, '', $diagnostic->toString()),
            $output->diagnostics()->all(),
        );
    }
}
