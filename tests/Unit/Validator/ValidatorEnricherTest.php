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
        self::assertSame(['Assert\\GreaterThan(value: 0)', 'Assert\\LessThanOrEqual(value: 99.5)', 'Assert\\DivisibleBy(value: 0.5)'], $this->attributesOf($code, 'weight'));
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

    public function testIgnoresGroupNamesThatAreNoNames(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 1, 'x-validator-groups' => ['', 'api', 3]]]]);

        self::assertSame(["Assert\\Length(max: 1, groups: ['api', 'Default'])"], $this->attributesOf($code, 'a'));
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
        $schema = ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['active', 'gone']], 'size' => ['type' => 'integer', 'enum' => [1, 2]]]];

        $old = $this->pet($schema, [], '8.0');
        $new = $this->pet($schema, [], '8.2');

        self::assertSame(["Assert\\Choice(choices: ['active', 'gone'])"], $this->attributesOf($old, 'status'));
        self::assertSame(['Assert\\Choice(choices: [1, 2])'], $this->attributesOf($old, 'size'));
        self::assertStringNotContainsString('Choice', $new);
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
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/a: symfony/validator is not installed, but the bridge writes its constraints because extensionConfig.symfony.validator is true.'], $this->messages($output));
    }

    public function testWritesNothingForASymfonyItDoesNotSupport(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'string', 'maxLength' => 3]]], [], '8.2', [], 'v4.4.49');

        self::assertStringNotContainsString('Assert', $this->code($output, 'Pet.php'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/a: The bridge writes constraints for symfony/validator 5.4 or newer, the project has 4.4; none are written.'], $this->messages($output));
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
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/a: symfony/validator reads annotations through doctrine/annotations, which the project does not install.'], $this->messages($missing));

        $strict = $this->generate($schema, [], '7.4', [], 'v7.1.0', ['doctrine/annotations' => '2.0.2']);
        self::assertSame(['error /api.yaml#/components/schemas/Pet/properties/a: symfony/validator 7.1 reads no annotations, and PHP 7.4 has no attributes; no constraints are written.'], $this->messages($strict));

        $loose = $this->generate($schema, [], '7.4', [], 'v7.1.0', [], false);
        self::assertStringNotContainsString('Assert', $this->code($loose, 'Pet.php'));
        self::assertSame(['warning /api.yaml#/components/schemas/Pet/properties/a: symfony/validator 7.1 reads no annotations, and PHP 7.4 has no attributes; no constraints are written.'], $this->messages($loose));
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
     */
    private function generate(array $pet, array $others = [], string $php = '8.2', array $settings = [], ?string $validator = 'v7.1.0', array $packages = [], bool $strict = true, array $formats = []): Output
    {
        $locked = $validator === null ? $packages : ['symfony/validator' => $validator] + $packages;
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/composer.lock', (string) json_encode(['packages' => array_map(
            static fn (string $name, string $version): array => ['name' => $name, 'version' => $version],
            array_keys($locked),
            array_values($locked),
        )]));
        file_put_contents($this->root . '/api.yaml', (string) json_encode(['openapi' => '3.1.0', 'components' => ['schemas' => ['Pet' => $pet] + $others]]));
        file_put_contents($this->root . '/dto-generator.yaml', (string) json_encode([
            'version' => 1,
            'target' => ['php' => $php, 'strict' => $strict],
            'verifyClasses' => false,
            'discoverExtensions' => false,
            'formats' => $formats === [] ? new stdClass() : $formats,
            'extensions' => [SymfonyExtension::class],
            'extensionConfig' => ['symfony' => $settings],
            'sources' => [['spec' => 'api.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'out']],
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

                return array_map(static fn (string $attribute): string => (string) preg_replace(['~\(\s+~', '~,?\s+\)~', '~\s+~'], ['(', ')', ' '], trim($attribute)), $matches[1]);
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
