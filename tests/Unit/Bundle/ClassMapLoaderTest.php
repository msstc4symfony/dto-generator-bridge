<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\Build\ClassMapLoader;
use PHPUnit\Framework\TestCase;

final class ClassMapLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        // Registered by an earlier test, the loader would stay where it is and hide whether it is prepended.
        spl_autoload_unregister([ClassMapLoader::class, 'load']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
    }

    public function testKeepsTheClassesOfEveryMapWithTheLatestFileOfEach(): void
    {
        $first = $this->className();
        $second = $this->className();

        ClassMapLoader::add([$first => $this->file($first, 'old'), $second => $this->file($second, 'old')]);
        ClassMapLoader::add([$second => $this->file($second)]);

        self::assertSame('old', constant($first . '::BY'));
        self::assertSame('ours', constant($second . '::BY'));
    }

    public function testRunsAheadOfTheOtherLoaders(): void
    {
        $class = $this->className();
        $theirs = static function (string $name) use ($class): void {
            if ($name === $class) {
                eval(sprintf('namespace %s; final class Pet { public const BY = "theirs"; }', substr($class, 0, -4)));
            }
        };
        spl_autoload_register($theirs);

        try {
            ClassMapLoader::add([$class => $this->file($class)]);

            self::assertSame('ours', constant($class . '::BY'));
        } finally {
            spl_autoload_unregister($theirs);
        }
    }

    public function testKeepsOneLoader(): void
    {
        ClassMapLoader::add([]);
        $count = count(spl_autoload_functions());

        ClassMapLoader::add([$this->className() => '/nowhere/Pet.php']);

        self::assertCount($count, spl_autoload_functions());
    }

    public function testSkipsAFileThatIsGone(): void
    {
        $class = $this->className();

        ClassMapLoader::add([$class => '/nowhere/Pet.php']);

        self::assertFalse(class_exists($class));
    }

    private function className(): string
    {
        return 'App\\Mapped' . bin2hex(random_bytes(4)) . '\\Pet';
    }

    private function file(string $class, string $by = 'ours'): string
    {
        $file = sys_get_temp_dir() . '/dto-bridge-map-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, sprintf("<?php\nnamespace %s;\nfinal class Pet { public const BY = '%s'; }\n", substr($class, 0, -4), $by));
        $this->files[] = $file;

        return $file;
    }
}
