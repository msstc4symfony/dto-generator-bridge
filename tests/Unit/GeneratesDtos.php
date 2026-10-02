<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit;

use FilesystemIterator;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use Msstc4Symfony\DtoGeneratorBridge\SymfonyExtension;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

/**
 * The real generator with the bridge, in dry-run, on a temporary project whose composer.lock pins the given packages.
 */
trait GeneratesDtos
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

    /**
     * The attributes written before the class declaration, without "#[" and "]".
     *
     * @return list<string>
     */
    private function classAttributesOf(string $code): array
    {
        if (preg_match('~^(?:final |abstract )?(?:readonly )?class ~m', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('No class declaration');
        }

        $head = substr($code, 0, $match[0][1]);
        preg_match_all('~^#\[(.*)\]$~m', $head, $matches);

        return array_map(static fn (string $attribute): string => (string) preg_replace('~\s+~', ' ', $attribute), $matches[1]);
    }
}
