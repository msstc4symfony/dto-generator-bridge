<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Integration\Symfony;

use Composer\InstalledVersions;
use DateTimeInterface;
use Doctrine\Common\Annotations\AnnotationReader;
use FilesystemIterator;
use InvalidArgumentException;
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
 * DTOs the bridge generated, checked by the installed Symfony Validator and Serializer (bridge spec §8.1). CI runs it
 * once per Symfony line of the matrix; without Symfony (the PHP 7.4 job) it skips itself.
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
        'gone' => null,
        'ip6' => '::1',
        'legs' => 4,
        'tail' => 2,
        'nick' => 'rx',
        'count' => 5,
        'blob' => 'QUJD',
        'level' => 'low',
    ];

    /**
     * Deprecations Symfony itself raises here, which the bridge's output does not cause: reading annotations (6.4), the
     * EmailValidator's default "loose" mode in this hand-built validator (6.2–6.4; FrameworkBundle configures it), and
     * Symfony 5.4's code on PHP 8.4/8.5.
     */
    private const FOREIGN_DEPRECATIONS = [
        // The PHP 7.4 target has no attributes; Symfony 6.4 deprecates the annotations it gets instead (spec §5.5).
        '~uses Doctrine Annotations to configure (serialization|validation constraints), which is deprecated~',
        '~Passing a "Doctrine\\\\Common\\\\Annotations\\\\AnnotationReader" instance as argument 1 to ".*AttributeLoader::__construct\(\)" is deprecated~',
        '~The "loose" mode is deprecated~',
        '~Implicitly marking parameter .* as nullable is deprecated~',
        '~Using null as an array offset is deprecated~',
        '~Use of "static" in callables is deprecated~',
        '~setAccessible\(\) is deprecated~',
    ];

    /** @var array<string, string> target PHP version → namespace of the generated classes */
    private static array $generated = [];

    /** @var list<callable(string): void> */
    private static array $autoloaders = [];

    private static ?string $root = null;

    protected function setUp(): void
    {
        foreach ([Validation::class, Serializer::class, PropertyInfoExtractor::class, PhpDocExtractor::class] as $class) {
            if (!class_exists($class)) {
                self::markTestSkipped(sprintf('%s is not installed; the CI matrix installs it.', $class));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        self::$autoloaders = [];
        self::$generated = [];
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
            'gone not null' => [$all, ['gone' => 'here'], ['gone'], []],
            'age above the maximum' => [$all, ['age' => 31], ['age'], []],
            'age below the minimum' => [$all, ['age' => -1], ['age'], []],
            'legs below the minimum' => [$all, ['legs' => 1], ['legs'], []],
            'tail at the exclusive maximum' => [$all, ['tail' => 3], ['tail'], []],
            'weight at the exclusive minimum' => [$all, ['weight' => 0.0], ['weight'], []],
            'weight not a multiple' => [$all, ['weight' => 2.3], ['weight'], []],
            'no tags' => [$all, ['tags' => []], ['tags'], []],
            'too many tags' => [$all, ['tags' => ['a', 'b', 'c', 'd']], ['tags'], []],
            'repeated tags' => [$all, ['tags' => ['a', 'a']], ['tags'], []],
            'tag too long' => [$withNew, ['tags' => ['abcde']], ['tags[0]'], []],
            'tag too long, no All on PHP 8.0' => [['8.0'], ['tags' => ['abcde']], [], []],
            'too many scores' => [$all, ['scores' => ['a' => 1, 'b' => 2, 'c' => 3]], ['scores'], []],
            'negative score' => [$withNew, ['scores' => ['a' => -1]], ['scores[a]'], []],
            'email' => [$all, ['email' => 'nope'], ['email'], []],
            'email that only the loose mode takes' => [$all, ['email' => 'a b@example.com'], ['email'], []],
            'ipv4' => [$all, ['ip' => '300.1.1.1'], ['ip'], []],
            'ipv6' => [$all, ['ip6' => '10.0.0.1'], ['ip6'], []],
            'host' => [$all, ['host' => 'bad host'], ['host'], []],
            'uuid' => [$all, ['id' => 'not-a-uuid'], ['id'], []],
            'status outside the enum' => [['8.0', '7.4'], ['status' => 'lost'], ['status'], []],
            'owner name too long' => [$all, ['owner' => ['name' => 'toolong']], ['owner.name'], []],
            'friend name too long' => [$all, ['friends' => [['name' => 'toolong']]], ['friends[0].name'], []],
            'renamed property too long' => [$all, ['first_name' => 'abcdefghi'], ['firstName'], []],
            'note checked only in its group' => [$all, [], ['note'], ['strict']],
            'count beyond int32' => [$all, ['count' => 2147483648], ['count'], []],
            'count below the minimum' => [$all, ['count' => -1], ['count'], []],
            'blob outside the base64 alphabet' => [$all, ['blob' => 'QU*D'], ['blob'], []],
            'blob in base64url' => [$all, ['blob' => 'QU-_'], ['blob'], []],
            'blob with padding inside' => [$all, ['blob' => 'QQ==QQ=='], ['blob'], []],
            'blob of a length base64 has not, which the pattern does not check' => [$all, ['blob' => 'QUJ'], [], []],
            'blob with a trailing newline' => [$all, ['blob' => "QUJD\n"], ['blob'], []],
            'blob with padding' => [$all, ['blob' => 'QUI='], [], []],
            'level outside the mixed enum' => [$all, ['level' => 'high'], ['level'], []],
            'level as the integer of the mixed enum' => [$all, ['level' => 1], [], []],
        ];
    }

    /**
     * @dataProvider violations
     *
     * @param list<string> $targets
     * @param array<string, JsonValue> $change
     * @param list<string> $expected property paths of the violations, one per violation
     * @param list<string> $groups
     */
    public function testValidatesWhatTheSchemaAllows(array $targets, array $change, array $expected, array $groups): void
    {
        $checked = 0;
        foreach ($targets as $php) {
            $checked += (int) $this->withTarget($php, function (string $namespace, bool $annotations) use ($change, $expected, $groups, $php): void {
                $pet = $this->serializer($annotations)->denormalize(array_replace(self::VALID, $change), $namespace . '\Pet');
                self::assertIsObject($pet);

                self::assertSame($expected, $this->violationPaths($pet, $annotations, $groups), 'PHP ' . $php);
            });
        }

        if ($checked === 0) {
            self::markTestSkipped('No target of this case runs here.');
        }
    }

    /**
     * Symfony's RegexValidator reports a failed preg_match() as a violation, so the pattern must hold any size.
     */
    public function testAcceptsLargeBase64(): void
    {
        $this->runTarget('8.2', function (string $namespace, bool $annotations): void {
            foreach ([1, 8] as $megabytes) {
                $blob = base64_encode(str_repeat("\xFB\xEF\xBE", $megabytes * 349526));
                $pet = $this->serializer($annotations)->denormalize(['blob' => $blob] + self::VALID, $namespace . '\\Pet');
                self::assertIsObject($pet);

                self::assertSame([], $this->violationPaths($pet, $annotations), $megabytes . ' MB');
            }
        });
    }

    /**
     * Facts the spec documents as known differences from JSON Schema (§5.6): Symfony takes UUID versions 7 and 8 only
     * from 6.2, and no version takes the nil UUID.
     */
    public function testChecksUuidsAsTheInstalledVersionDoes(): void
    {
        $this->runTarget('8.2', function (string $namespace, bool $annotations): void {
            $accepts = function (string $id) use ($namespace, $annotations): bool {
                $pet = $this->serializer($annotations)->denormalize(['id' => $id] + self::VALID, $namespace . '\Pet');
                self::assertIsObject($pet);

                return $this->violationPaths($pet, $annotations) === [];
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
        $this->runTarget($php, function (string $namespace, bool $annotations): void {
            $serializer = $this->serializer($annotations);
            $pet = $serializer->denormalize(self::VALID + ['secret' => 'kept out'], $namespace . '\Pet');
            self::assertIsObject($pet);
            $written = $serializer->normalize($pet);
            self::assertIsArray($written);

            ksort($written);
            $expected = self::VALID;
            ksort($expected);
            self::assertSame($expected, $written);
            self::assertNull((new ReflectionProperty($pet, 'secret'))->getValue($pet), 'Ignore keeps the property out of reading too');
            self::assertSame(['nick' => 'rx'], $serializer->normalize($pet, null, ['groups' => ['public']]));
        });
    }

    /**
     * @dataProvider targets
     */
    public function testReadsFractionsOfSeconds(string $php): void
    {
        $this->runTarget($php, function (string $namespace, bool $annotations): void {
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
        $this->runTarget($php, function (string $namespace, bool $annotations): void {
            $serializer = $this->serializer($annotations);
            $cat = $serializer->denormalize(['animal_type' => 'cat', 'lives' => 10], $namespace . '\Animal');

            self::assertIsObject($cat);
            self::assertSame($namespace . '\Cat', get_class($cat));
            self::assertSame(['animal_type' => 'cat', 'lives' => 10], $serializer->normalize($cat));
            self::assertSame(['lives'], $this->violationPaths($cat, $annotations));
        });
    }

    /**
     * The discriminator is an enum: a PHP enum on 8.1+ targets, a string with class constants before.
     *
     * @dataProvider targets
     */
    public function testBuildsTheVariantAnEnumDiscriminatorSelects(string $php): void
    {
        $this->runTarget($php, function (string $namespace, bool $annotations): void {
            $serializer = $this->serializer($annotations);
            $fish = $serializer->denormalize(['kind' => 'fish', 'fins' => -1], $namespace . '\Creature');
            $bird = $serializer->denormalize(['kind' => 'bird', 'wings' => 2], $namespace . '\Creature');

            self::assertIsObject($fish);
            self::assertIsObject($bird);
            self::assertSame($namespace . '\Fish', get_class($fish));
            self::assertSame($namespace . '\Bird', get_class($bird));
            self::assertSame(['kind' => 'fish', 'fins' => -1], $serializer->normalize($fish));
            self::assertSame(['kind' => 'bird', 'wings' => 2], $serializer->normalize($bird));
            self::assertSame(['fins'], $this->violationPaths($fish, $annotations));
        });
    }

    /**
     * A variant selected by one value takes it as the default of its constructor.
     *
     * @dataProvider targets
     */
    public function testTakesTheOnlyValueOfAVariantAsTheDefault(string $php): void
    {
        $this->runTarget($php, function (string $namespace, bool $annotations): void {
            $serializer = $this->serializer($annotations);
            $cat = $serializer->denormalize(['lives' => 3], $namespace . '\Cat');
            $fish = $serializer->denormalize(['fins' => 2], $namespace . '\Fish');

            self::assertIsObject($cat);
            self::assertIsObject($fish);
            self::assertSame(['animal_type' => 'cat', 'lives' => 3], $serializer->normalize($cat));
            self::assertSame(['kind' => 'fish', 'fins' => 2], $serializer->normalize($fish));
        });
    }

    /**
     * Denormalizing straight into a variant hands the payload's discriminator to its constructor, which rejects
     * another class's value; Symfony turns only a TypeError there into its own exception.
     *
     * @dataProvider targets
     */
    public function testRejectsAForeignDiscriminatorValueInAVariant(string $php): void
    {
        $this->runTarget($php, function (string $namespace, bool $annotations): void {
            $serializer = $this->serializer($annotations);

            self::assertSame('"dog" does not select Cat by "animal_type".', $this->rejection(static fn () => $serializer->denormalize(['animal_type' => 'dog'], $namespace . '\Cat')));
            self::assertSame('"bird" does not select Fish by "kind".', $this->rejection(static fn () => $serializer->denormalize(['kind' => 'bird'], $namespace . '\Fish')));
        });
    }

    /**
     * @dataProvider targets
     */
    public function testCarriesNoUndeclaredPropertiesButChecksTheirValues(string $php): void
    {
        $this->runTarget($php, function (string $namespace, bool $annotations) use ($php): void {
            $serializer = $this->serializer($annotations);
            $tally = $serializer->denormalize(['label' => 'x', 'apples' => 2], $namespace . '\Tally');

            self::assertIsObject($tally);
            self::assertSame(['label' => 'x'], $serializer->normalize($tally));
            $class = $namespace . '\Tally';
            // PHP 8.0 attributes allow no "new", so the bridge writes no All there.
            self::assertSame($php === '8.0' ? [] : ['additionalProperties[apples]'], $this->violationPaths(new $class('x', ['apples' => -1]), $annotations));
        });
    }

    /**
     * @param callable(): mixed $denormalize
     *
     * @return string the message of the \InvalidArgumentException it throws, exactly that class
     */
    private function rejection(callable $denormalize): string
    {
        try {
            $denormalize();
        } catch (InvalidArgumentException $exception) {
            self::assertSame(InvalidArgumentException::class, get_class($exception));

            return $exception->getMessage();
        }

        self::fail('No InvalidArgumentException');
    }

    /**
     * @param list<string> $groups
     *
     * @return list<string>
     */
    private function violationPaths(object $value, bool $annotations, array $groups = []): array
    {
        $paths = [];
        foreach ($this->validator($annotations)->validate($value, null, $groups === [] ? null : $groups) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    /**
     * @param callable(string, bool): void $check
     */
    private function runTarget(string $php, callable $check): void
    {
        if (!$this->withTarget($php, $check)) {
            self::markTestSkipped(sprintf('PHP %s targets do not run with PHP %s and Symfony %.1F.', $php, PHP_VERSION, $this->symfony()));
        }
    }

    /**
     * Whether the target runs here: its classes need that PHP, and annotations need Symfony < 7 with Doctrine's reader.
     *
     * @param callable(string, bool): void $check the namespace of the generated classes, and whether they carry annotations
     */
    private function withTarget(string $php, callable $check): bool
    {
        $annotations = $php === '7.4';
        if (version_compare(PHP_VERSION, $php, '<') || ($annotations && ($this->symfony() >= 7.0 || !class_exists(AnnotationReader::class)))) {
            return false;
        }

        $namespace = self::$generated[$php] ??= $this->generate($php);
        $deprecations = [];
        $previous = null;
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous, &$deprecations): bool {
            if (strpos($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
                $deprecations[] = $message;

                return true;
            }

            return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
        }, E_USER_DEPRECATED | E_DEPRECATED);

        try {
            $check($namespace, $annotations);
        } finally {
            restore_error_handler();
        }

        // Symfony reports its own deprecations silently (@trigger_error); one that is not foreign points at the
        // metadata the bridge wrote, like a constraint option a newer version drops.
        foreach ($deprecations as $deprecation) {
            $foreign = array_filter(self::FOREIGN_DEPRECATIONS, static fn (string $pattern): bool => preg_match($pattern, $deprecation) === 1);
            self::assertNotSame([], $foreign, 'Deprecation of the generated metadata: ' . $deprecation);
        }

        return true;
    }

    private function generate(string $php): string
    {
        $namespace = 'App\Matrix\V' . str_replace('.', '', $php);
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
        $messages = array_map(static fn (Diagnostic $diagnostic): string => str_replace($dir, '', $diagnostic->toString()), $output->diagnostics()->all());
        self::assertSame([], array_values(array_filter($messages, static fn (string $message): bool => strncmp($message, 'error', 5) === 0)));
        $dropped = array_values(array_filter($messages, static fn (string $message): bool => strpos($message, 'inside All') !== false));
        self::assertCount($php === '8.0' ? 3 : 0, $dropped, implode("\n", $messages));

        $out = $dir . '/out/';
        $autoloader = static function (string $class) use ($namespace, $out): void {
            $file = $out . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
            if (strncmp($class, $namespace . '\\', strlen($namespace) + 1) === 0 && is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($autoloader);
        self::$autoloaders[] = $autoloader;

        return $namespace;
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
