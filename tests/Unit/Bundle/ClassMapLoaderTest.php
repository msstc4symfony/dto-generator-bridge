<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Bundle;

use Msstc4Symfony\DtoGeneratorBridge\Bundle\Build\ClassMapLoader;
use PHPUnit\Framework\TestCase;

final class ClassMapLoaderTest extends TestCase
{
    public function testLoadsTheClassesOfItsMapOnly(): void
    {
        $class = 'App\\Mapped' . bin2hex(random_bytes(4)) . '\\Pet';
        $file = sys_get_temp_dir() . '/dto-bridge-map-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, sprintf("<?php\nnamespace %s;\nfinal class Pet {}\n", substr($class, 0, -4)));

        try {
            ClassMapLoader::register([$class => $file]);

            self::assertTrue(class_exists($class));
            self::assertFalse(class_exists($class . 'Missing'));
        } finally {
            unlink($file);
        }
    }

    public function testSkipsAnEntryThatIsNoFile(): void
    {
        $class = 'App\\Broken' . bin2hex(random_bytes(4)) . '\\Pet';

        ClassMapLoader::register([$class => 42]);

        self::assertFalse(class_exists($class));
    }

    public function testRegistersOneLoaderPerMap(): void
    {
        $map = ['App\\Never' . bin2hex(random_bytes(4)) . '\\Pet' => '/nowhere/Pet.php'];
        $before = count(spl_autoload_functions());

        ClassMapLoader::register($map);
        ClassMapLoader::register($map);

        self::assertCount($before + 1, spl_autoload_functions());
    }
}
