<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Integration\Symfony;

use Composer\InstalledVersions;
use DateTimeInterface;
use Doctrine\Common\Annotations\AnnotationReader;
use FilesystemIterator;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use Msstc4Symfony\DtoGeneratorBridge\SymfonyExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\LoaderInterface as SerializerLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Mapping\Loader\LoaderInterface as ValidatorLoader;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * DTOs the bridge generated, checked by the installed Symfony Validator and Serializer (bridge spec §8). CI runs it
 * once per Symfony line of the matrix.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class RealSymfonyTest extends TestCase
{
    private const VALID = [
        'name' => 'rex',
        'kind' => 'pet',
        'age' => 3,
        'weight' => 2.5,
        'tags' => ['a', 'b'],
        'scores' => ['x' => 1],
        'email' => 'a@b.co',
        'ip' => '10.0.0.1',
        'host' => 'localhost',
        'id' => '9b2c3f4e-1a2b-4c3d-8e9f-0a1b2c3d4e5f',
        'status' => 'active',
        'owner' => ['name' => 'ann'],
        'friends' => [['name' => 'bo']],
        'first_name' => 'rexy',
        'born' => '2026-10-02',
        'seen' => '2026-10-02T10:00:00+00:00',
        'note' => 'abcd',
    ];

    /** @var array<string, string> target PHP version → directory of the generated classes */
    private static array $generated = [];

    private static ?string $root = null;

    /** Whether the current test checks other targets, so that skipping one is no skip of the test. */
    private bool $hasOtherTargets = false;

    public static function tearDownAfterClass(): void
    {
        if (self::$root === null) {
            return;
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir(self::$root);
        self::$root = null;
        self::$generated = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targets(): array
    {
        return ['PHP 8.2, attributes' => ['8.2'], 'PHP 8.0, attributes without new' => ['8.0'], 'PHP 7.4, annotations' => ['7.4']];
    }

    /**
     * @return array<string, array{list<string>, array<string, JsonValue>, list<string>, list<string>}>
     */
    public static function violations(): array
    {
        $all = ['8.2', '8.0', '7.4'];
        $withNew = ['8.2', '7.4'];

        return [
            'valid' => [$all, [], [], []],
            'name too short' => [$all, ['name' => ''], ['name'], []],
            'name not matching the pattern' => [$all, ['name' => 'Rex'], ['name'], []],
            'name too long' => [$all, ['name' => 'abcdefghijk'], ['name'], []],
            'kind not the constant' => [$all, ['kind' => 'cat'], ['kind'], []],
            'age above the maximum' => [$all, ['age' => 31], ['age'], []],
            'age below the minimum' => [$all, ['age' => -1], ['age'], []],
            'weight at the exclusive minimum' => [$all, ['weight' => 0.0], ['weight'], []],
            'weight not a multiple' => [$all, ['weight' => 2.3], ['weight'], []],
            'no tags' => [$all, ['tags' => []], ['tags'], []],
            'too many tags' => [$all, ['tags' => ['a', 'b', 'c', 'd']], ['tags'], []],
            'repeated tags' => [$all, ['tags' => ['a', 'a']], ['tags'], []],
            'tag too long' => [$withNew, ['tags' => ['abcde']], ['tags[0]'], []],
            'too many scores' => [$all, ['scores' => ['a' => 1, 'b' => 2, 'c' => 3]], ['scores'], []],
            'negative score' => [$withNew, ['scores' => ['a' => -1]], ['scores[a]'], []],
            'email' => [$all, ['email' => 'nope'], ['email'], []],
            'ip' => [$all, ['ip' => '300.1.1.1'], ['ip'], []],
            'host' => [$all, ['host' => 'bad host'], ['host'], []],
            'uuid' => [$all, ['id' => 'not-a-uuid'], ['id'], []],
            'status outside the enum' => [['8.0', '7.4'], ['status' => 'lost'], ['status'], []],
            'owner name too long' => [$all, ['owner' => ['name' => 'toolong']], ['owner.name'], []],
            'friend name too long' => [$all, ['friends' => [['name' => 'toolong']]], ['friends[0].name'], []],
            'renamed property too long' => [$all, ['first_name' => 'abcdefghi'], ['firstName'], []],
            'note checked only in its group' => [$all, [], ['note'], ['strict']],
        ];
    }

    /**
     * @dataProvider violations
     *
     * @param list<string> $targets
     * @param array<string, JsonValue> $change
     * @param list<string> $expected property paths of the violations
     * @param list<string> $groups
     */
    public function testValidatesWhatTheSchemaAllows(array $targets, array $change, array $expected, array $groups): void
    {
        foreach ($targets as $index => $php) {
            $this->hasOtherTargets = $index < count($targets) - 1 || $index > 0;
            $this->withTarget($php, function (string $namespace, bool $annotations) use ($change, $expected, $groups, $php): void {
                $pet = $this->serializer($annotations)->denormalize(array_replace(self::VALID, $change), $namespace . '\Pet');
                $paths = [];
                foreach ($this->validator($annotations)->validate($pet, null, $groups === [] ? null : $groups) as $violation) {
                    $paths[] = $violation->getPropertyPath();
                }

                self::assertSame($expected, array_values(array_unique($paths)), 'PHP ' . $php);
            });
        }
    }

    /**
     * Facts the spec documents as known differences from JSON Schema (§5.6): Symfony takes UUID versions 7 and 8 only
     * from 6.2, and no version takes the nil UUID.
     */
    public function testChecksUuidsAsTheInstalledVersionDoes(): void
    {
        $this->withTarget('8.2', function (string $namespace, bool $annotations): void {
            $accepts = function (string $id) use ($namespace, $annotations): bool {
                $pet = $this->serializer($annotations)->denormalize(['id' => $id] + self::VALID, $namespace . '\Pet');

                return count($this->validator($annotations)->validate($pet)) === 0;
            };

            self::assertSame($this->symfony() >= 6.2, $accepts('0190a6f0-5c3a-7d4b-8e9f-0a1b2c3d4e5f'));
            self::assertFalse($accepts('00000000-0000-0000-0000-000000000000'));
        });
    }

    /**
     * @dataProvider targets
     */
    public function testSerializesBackWhatItRead(string $php): void
    {
        $this->withTarget($php, function (string $namespace, bool $annotations): void {
            $serializer = $this->serializer($annotations);
            $pet = $serializer->denormalize(self::VALID + ['secret' => 'kept out'], $namespace . '\Pet');

            self::assertEquals(self::VALID, $serializer->normalize($pet));
        });
    }

    /**
     * @dataProvider targets
     */
    public function testReadsFractionsOfSeconds(string $php): void
    {
        $this->withTarget($php, function (string $namespace, bool $annotations): void {
            $pet = $this->serializer($annotations)->denormalize(['seen' => '2026-10-02T10:00:00.123+00:00'] + self::VALID, $namespace . '\Pet');
            self::assertIsObject($pet);
            $seen = (new ReflectionProperty($pet, 'seen'))->getValue($pet);

            self::assertInstanceOf(DateTimeInterface::class, $seen);
            self::assertSame('123', $seen->format('v'));
        });
    }

    /**
     * @dataProvider targets
     */
    public function testReadsAndWritesTheDiscriminatedSubclass(string $php): void
    {
        $this->withTarget($php, function (string $namespace, bool $annotations): void {
            $serializer = $this->serializer($annotations);
            $cat = $serializer->denormalize(['animal_type' => 'cat', 'lives' => 10], $namespace . '\Animal');

            self::assertIsObject($cat);
            self::assertSame($namespace . '\Cat', get_class($cat));
            self::assertEquals(['animal_type' => 'cat', 'lives' => 10], $serializer->normalize($cat));
            self::assertCount(1, $this->validator($annotations)->validate($cat));
        });
    }

    /**
     * @param callable(string, bool): void $check the namespace of the generated classes, and whether they carry annotations
     */
    private function withTarget(string $php, callable $check): void
    {
        $annotations = $php === '7.4';
        if ($annotations && ($this->symfony() >= 7.0 || !class_exists(AnnotationReader::class))) {
            if (!$this->hasOtherTargets) {
                self::markTestSkipped(sprintf('Symfony %.1F reads no annotations.', $this->symfony()));
            }

            return;
        }

        $namespace = 'App\Matrix\V' . str_replace('.', '', $php);
        self::$generated[$php] ??= $this->generate($php, $namespace);
        // Deprecations of the installed Symfony on a newer PHP (5.4 on 8.5), or of reading annotations (6.4), are not
        // the bridge's: only those raised outside vendor/ reach PHPUnit.
        $previous = null;
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            if (strpos($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
                return true;
            }

            return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
        }, E_USER_DEPRECATED | E_DEPRECATED);

        try {
            $check($namespace, $annotations);
        } finally {
            restore_error_handler();
        }
    }

    private function generate(string $php, string $namespace): string
    {
        self::$root ??= sys_get_temp_dir() . '/dto-bridge-matrix-' . bin2hex(random_bytes(4));
        $dir = self::$root . '/' . $php;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        copy(__DIR__ . '/api.yaml', $dir . '/api.yaml');
        file_put_contents($dir . '/composer.json', '{}');
        file_put_contents($dir . '/dto-generator.yaml', (string) json_encode([
            'version' => 1,
            'target' => ['php' => $php],
            'verifyClasses' => false,
            'discoverExtensions' => false,
            'extensions' => [SymfonyExtension::class],
            'extensionConfig' => ['symfony' => ['validator' => true, 'serializer' => true, 'version' => sprintf('%.1F', $this->symfony())]],
            'sources' => [['spec' => 'api.yaml', 'namespace' => $namespace, 'outputDir' => 'out']],
        ]));

        $output = DtoGenerator::generator()(new Input($dir . '/dto-generator.yaml', Mode::from(Mode::WRITE)));
        $errors = array_filter($output->diagnostics()->all(), static fn (Diagnostic $diagnostic): bool => strncmp($diagnostic->toString(), 'error', 5) === 0);
        self::assertSame([], array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), array_values($errors)));

        $out = $dir . '/out/';
        spl_autoload_register(static function (string $class) use ($namespace, $out): void {
            if (strncmp($class, $namespace . '\\', strlen($namespace) + 1) === 0) {
                require $out . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
            }
        });

        return $out;
    }

    private function serializer(bool $annotations): Serializer
    {
        $metadata = new ClassMetadataFactory($this->loader(SerializerLoader::class, 'Symfony\Component\Serializer\Mapping\Loader', $annotations));
        $types = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $normalizers = [new DateTimeNormalizer(), new ArrayDenormalizer()];
        if (class_exists(BackedEnumNormalizer::class)) {
            $normalizers[] = new BackedEnumNormalizer();
        }

        $normalizers[] = new ObjectNormalizer($metadata, new MetadataAwareNameConverter($metadata), null, $types, new ClassDiscriminatorFromClassMetadata($metadata));

        return new Serializer($normalizers);
    }

    private function validator(bool $annotations): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->addLoader($this->loader(ValidatorLoader::class, 'Symfony\Component\Validator\Mapping\Loader', $annotations))
            ->getValidator()
        ;
    }

    /**
     * The metadata loader of the installed version: AttributeLoader from 6.4, AnnotationLoader before 7.0, which also
     * reads attributes when it has no Doctrine reader. Class names are strings because no version has both.
     *
     * @template T of object
     *
     * @param class-string<T> $interface
     *
     * @return T
     */
    private function loader(string $interface, string $namespace, bool $annotations): object
    {
        $attributeLoader = $namespace . '\AttributeLoader';
        $annotationLoader = $namespace . '\AnnotationLoader';
        if ($annotations) {
            $class = class_exists($annotationLoader) ? $annotationLoader : $attributeLoader;
            $loader = new $class(new AnnotationReader());
        } else {
            $class = class_exists($attributeLoader) ? $attributeLoader : $annotationLoader;
            $loader = new $class();
        }

        self::assertInstanceOf($interface, $loader);

        return $loader;
    }

    private function symfony(): float
    {
        $version = (string) InstalledVersions::getVersion('symfony/validator');

        return (float) implode('.', array_slice(explode('.', $version), 0, 2));
    }
}
