<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Integration;

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

/**
 * The bridge inside the real generator, as a project configures it.
 */
final class GeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dto-generator-bridge-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        file_put_contents($this->root . '/api.yaml', "openapi: 3.1.0\ncomponents:\n  schemas:\n    Pet:\n      type: object\n      properties:\n        name: {type: string, x-validator-groups: [api]}\n");
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

    public function testRegistersWithTheGeneratorAndOwnsItsKeys(): void
    {
        $output = $this->generate('');

        self::assertSame('ok', $output->status()->value());
        self::assertSame([], $this->messages($output));
    }

    public function testReportsASectionItCannotUse(): void
    {
        $output = $this->generate("extensionConfig:\n  symfony: {colour: red}\n");

        self::assertContains(
            'error ' . $this->root . '/dto-generator.yaml#: Extension "symfony" failed to register: extensionConfig.symfony.colour is not a setting of the Symfony bridge.',
            $this->messages($output),
        );
    }

    private function generate(string $extra): Output
    {
        file_put_contents($this->root . '/dto-generator.yaml', sprintf(
            "version: 1\ntarget: {php: '8.2'}\nverifyClasses: false\ndiscoverExtensions: false\nextensions: ['%s']\n%ssources:\n  - {spec: api.yaml, namespace: App\\Dto, outputDir: out}\n",
            SymfonyExtension::class,
            $extra,
        ));

        return DtoGenerator::generator()(new Input($this->root . '/dto-generator.yaml', Mode::from(Mode::DRY_RUN)));
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->all());
    }
}
