<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime;

use DateTimeImmutable;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalPropertiesNormalizer;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Animal;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Apple;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Bag;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\BodyStyled;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Box;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Car;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Cat;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Circle;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Coloured;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Crate;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Dog;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Fruit;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Note;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Owner;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Pair;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Plain;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Primary;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Shape;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Tagged;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Tree;
use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture\Vehicle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorMapping;
use Symfony\Component\Serializer\Mapping\ClassMetadataInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The normalizer in a Serializer built as FrameworkBundle builds it. The attribute fixtures need Symfony 6.4 or newer;
 * the generated DTOs are checked against 5.4 by the integration suite.
 */
final class AdditionalPropertiesNormalizerTest extends TestCase
{
    private Serializer $serializer;

    private AdditionalPropertiesNormalizer $normalizer;

    protected function setUp(): void
    {
        if (!class_exists(SerializedName::class) || !class_exists(AttributeLoader::class)) {
            self::markTestSkipped('The fixtures need the attributes of symfony/serializer 6.4 or newer.');
        }

        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $types = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $objects = new ObjectNormalizer($metadata, new MetadataAwareNameConverter($metadata), null, $types, new ClassDiscriminatorFromClassMetadata($metadata));
        $this->normalizer = new AdditionalPropertiesNormalizer($objects, $metadata);
        $this->serializer = new Serializer([new DateTimeNormalizer(), $this->normalizer, new ArrayDenormalizer(), $objects]);
    }

    public function testWritesTheUndeclaredPropertiesBesideTheDeclaredOnes(): void
    {
        $bag = new Bag('box', 'ann', new DateTimeImmutable('2026-10-10T08:00:00+00:00'), ['vet' => new Owner('bo'), 'groomer' => new Owner('cy')]);

        self::assertSame([
            'label' => 'box',
            'first_name' => 'ann',
            'seen' => '2026-10-10T08:00:00+00:00',
            'vet' => ['name' => 'bo'],
            'groomer' => ['name' => 'cy'],
        ], $this->serializer->normalize($bag));
    }

    public function testReadsTheUndeclaredPropertiesIntoTheMap(): void
    {
        $bag = $this->serializer->denormalize(['label' => 'box', 'first_name' => 'ann', 'vet' => ['name' => 'bo'], 'additionalProperties' => ['name' => 'cy']], Bag::class);

        self::assertInstanceOf(Bag::class, $bag);
        self::assertSame('box', $bag->getLabel());
        self::assertSame('ann', $bag->getFirstName());
        self::assertSame(['vet', 'additionalProperties'], array_keys($bag->getAdditionalProperties()));
        self::assertContainsOnlyInstancesOf(Owner::class, $bag->getAdditionalProperties());
        self::assertSame('cy', $bag->getAdditionalProperties()['additionalProperties']->getName());
    }

    public function testReadsAnObjectWithoutUndeclaredProperties(): void
    {
        $bag = $this->serializer->denormalize(['label' => 'box'], Bag::class);

        self::assertInstanceOf(Bag::class, $bag);
        self::assertSame([], $bag->getAdditionalProperties());
        self::assertSame(['label' => 'box', 'first_name' => null, 'seen' => null], $this->serializer->normalize($bag));
    }

    public function testSpreadsNestedObjectsOfTheSameClass(): void
    {
        $data = ['name' => 'a', 'children' => [['name' => 'b', 'children' => [], 'x' => ['name' => 'c', 'children' => []]]], 'y' => ['name' => 'd', 'children' => [], 'z' => ['name' => 'e', 'children' => []]]];

        $tree = $this->serializer->denormalize($data, Tree::class);

        self::assertInstanceOf(Tree::class, $tree);
        self::assertSame('c', $tree->children[0]->additionalProperties['x']->name);
        self::assertSame('e', $tree->additionalProperties['y']->additionalProperties['z']->name);
        self::assertSame($data, $this->serializer->normalize($tree));
    }

    public function testFindsTheMapInAParentClass(): void
    {
        $crate = $this->serializer->denormalize(['size' => 3, 'apples' => 5], Crate::class);

        self::assertInstanceOf(Crate::class, $crate);
        self::assertSame(3, $crate->getSize());
        self::assertSame(['apples' => 5], $crate->getExtra());
        self::assertSame(['size' => 3, 'apples' => 5], $this->serializer->normalize($crate));
        self::assertSame(['pears' => 1], $this->serializer->normalize(new Box(['pears' => 1])));
    }

    public function testReadsAVariantThroughItsDiscriminatedBase(): void
    {
        $cat = $this->serializer->denormalize(['kind' => 'cat', 'name' => 'tom', 'mood' => 'calm'], Animal::class);
        $dog = $this->serializer->denormalize(['kind' => 'dog', 'name' => 'rex'], Animal::class);

        self::assertInstanceOf(Cat::class, $cat);
        self::assertSame(['mood' => 'calm'], $cat->additionalProperties);
        self::assertSame(['kind' => 'cat', 'name' => 'tom', 'mood' => 'calm'], $this->serializer->normalize($cat));
        self::assertInstanceOf(Dog::class, $dog);
        self::assertFalse($this->normalizer->supportsDenormalization(['kind' => 'dog'], Animal::class));
        self::assertFalse($this->normalizer->supportsDenormalization(['kind' => 'cow'], Animal::class));
        self::assertFalse($this->normalizer->supportsDenormalization(['kind' => ['cat']], Animal::class));
        self::assertFalse($this->normalizer->supportsDenormalization([], Animal::class));
        self::assertTrue($this->normalizer->supportsDenormalization(['kind' => 'cat'], Animal::class));
        self::assertTrue($this->normalizer->supportsDenormalization(['name' => 'tom'], Cat::class));
    }

    public function testRefusesAnUndeclaredPropertyNamedLikeADeclaredOne(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The additional property "first_name" of ' . Bag::class . ' has the name of a declared property.');

        $this->serializer->normalize(new Bag('box', null, null, ['first_name' => new Owner('bo')]));
    }

    public function testRefusesAnUndeclaredPropertyNamedLikeTheDiscriminator(): void
    {
        $cat = new Cat();
        $cat->additionalProperties = ['kind' => 'dog'];

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The additional property "kind" of ' . Cat::class . ' has the name of a declared property.');

        $this->serializer->normalize($cat);
    }

    public function testRefusesAnUndeclaredPropertyWhoseDeclaredNamesakeIsNotWritten(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The additional property "label" of ' . Bag::class . ' has the name of a declared property.');

        $this->serializer->normalize(new Bag('box', null, null, ['label' => new Owner('bo')]), null, [AbstractNormalizer::GROUPS => ['extra']]);
    }

    public function testWritesWhatTheGroupsLeave(): void
    {
        $bag = new Bag('box', null, null, ['vet' => new Owner('bo')]);

        // The groups reach the values too: Owner::$name is in none.
        self::assertSame(['vet' => []], $this->serializer->normalize($bag, null, [AbstractNormalizer::GROUPS => ['extra']]));
        self::assertSame([], $this->serializer->normalize($bag, null, [AbstractNormalizer::GROUPS => ['none']]));
        // A limitation of the declared array return type: no ArrayObject for an empty object.
        self::assertSame([], $this->serializer->normalize($bag, null, [AbstractNormalizer::GROUPS => ['none'], AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true]));
    }

    public function testReadsWhenExtraAttributesAreNotAllowed(): void
    {
        $bag = $this->serializer->denormalize(['label' => 'box', 'vet' => ['name' => 'bo']], Bag::class, null, [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false]);

        self::assertInstanceOf(Bag::class, $bag);
        self::assertSame(['vet'], array_keys($bag->getAdditionalProperties()));
    }

    public function testLeavesAClassWithoutTheMapToTheWrappedNormalizer(): void
    {
        $dog = $this->normalizer->denormalize(['kind' => 'dog', 'name' => 'rex'], Animal::class);

        self::assertInstanceOf(Dog::class, $dog);
        self::assertSame(['kind' => 'dog', 'name' => 'rex'], $this->normalizer->normalize($dog));
        self::assertSame(['additionalProperties' => ['a' => 'b']], $this->normalizer->normalize($this->plain(['a' => 'b'])));
    }

    public function testLeavesAnUnknownVariantToTheWrappedNormalizer(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('"cow"');

        $this->normalizer->denormalize(['kind' => 'cow'], Animal::class);
    }

    public function testReadsTheMetadataOfAClassOnce(): void
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $counting = $this->createMock(ClassMetadataFactoryInterface::class);
        $counting->expects(self::exactly(3))->method('getMetadataFor')->willReturnCallback(static fn (string $class): ClassMetadataInterface => $metadata->getMetadataFor($class));
        $normalizer = new AdditionalPropertiesNormalizer(new ObjectNormalizer($metadata), $counting);

        // Bag: its attributes, then its discriminated ancestors (none); Plain: no map, so nothing more. Then the cache.
        self::assertTrue($normalizer->supportsNormalization(new Bag('box')));
        self::assertTrue($normalizer->supportsNormalization(new Bag('box')));
        self::assertFalse($normalizer->supportsNormalization($this->plain([])));
        self::assertFalse($normalizer->supportsNormalization($this->plain([])));
    }

    public function testReadsAVariantThroughADiscriminatedInterface(): void
    {
        $circle = $this->serializer->denormalize(['type' => 'circle', 'radius' => 2, 'colour' => 'red'], Shape::class);

        self::assertInstanceOf(Circle::class, $circle);
        self::assertSame(['colour' => 'red'], $circle->additionalProperties);
        self::assertSame(['type' => 'circle', 'radius' => 2, 'colour' => 'red'], $this->serializer->normalize($circle));
        self::assertFalse($this->normalizer->supportsDenormalization(['radius' => 2], Shape::class));

        $circle->additionalProperties = ['type' => 'square'];
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The additional property "type" of ' . Circle::class . ' has the name of a declared property.');
        $this->serializer->normalize($circle);
    }

    public function testNamesKeysAsTheWrappedNormalizerDoes(): void
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $names = new MetadataAwareNameConverter($metadata, new CamelCaseToSnakeCaseNameConverter());
        $objects = new ObjectNormalizer($metadata, $names, null, new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]));
        $serializer = new Serializer([new AdditionalPropertiesNormalizer($objects, $metadata, $names), new ArrayDenormalizer(), $objects]);
        $data = ['label' => 'box', 'first_name' => 'ann', 'seen' => null, 'vet' => ['name' => 'bo']];

        $bag = $serializer->denormalize($data, Bag::class);

        self::assertInstanceOf(Bag::class, $bag);
        self::assertSame('ann', $bag->getFirstName());
        self::assertSame(['vet'], array_keys($bag->getAdditionalProperties()));
        self::assertSame($data, $serializer->normalize($bag));
    }

    public function testNamesDeclaredKeysThroughTheConverter(): void
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $names = new MetadataAwareNameConverter($metadata, new CamelCaseToSnakeCaseNameConverter());
        $objects = new ObjectNormalizer($metadata, $names);
        $serializer = new Serializer([new AdditionalPropertiesNormalizer($objects, $metadata, $names), $objects]);

        $note = $serializer->denormalize(['last_seen_at' => 'today', 'mood' => 'calm'], Note::class);

        self::assertInstanceOf(Note::class, $note);
        self::assertSame('today', $note->lastSeenAt);
        self::assertSame(['mood' => 'calm'], $note->additionalProperties);

        $note->additionalProperties = ['last_seen_at' => 'never'];
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"last_seen_at"');
        $serializer->normalize($note);
    }

    public function testDeclaresOnlyTheDiscriminatorSymfonyWrites(): void
    {
        $cat = $this->serializer->denormalize(['kind' => 'cat', 'name' => 'tom', 'tag' => 'striped'], Animal::class);

        self::assertInstanceOf(Cat::class, $cat);
        self::assertSame(['tag' => 'striped'], $cat->additionalProperties);
        self::assertSame(['kind' => 'cat', 'name' => 'tom', 'tag' => 'striped'], $this->serializer->normalize($cat));
    }

    public function testReadsThroughADiscriminatorSymfonyDoesNotWrite(): void
    {
        $circle = $this->serializer->denormalize(['colour' => 'circle', 'radius' => 2, 'type' => 'circle'], Coloured::class);
        $cat = $this->serializer->denormalize(['tag' => 'cat', 'name' => 'tom'], Tagged::class);

        self::assertInstanceOf(Circle::class, $circle);
        self::assertSame([], $circle->additionalProperties);
        self::assertInstanceOf(Cat::class, $cat);
        self::assertSame([], $cat->additionalProperties);
        self::assertSame(['kind' => 'cat', 'name' => 'tom'], $this->serializer->normalize($cat));
    }

    public function testFollowsTheParentsOfEachInterfaceAsSymfonyDoes(): void
    {
        $pair = $this->serializer->denormalize(['p' => 'c', 'a' => 'x'], Primary::class);

        self::assertInstanceOf(Pair::class, $pair);
        self::assertSame(['a' => 'x'], $pair->additionalProperties);
        self::assertSame(['p' => 'c', 'a' => 'x'], $this->serializer->normalize($pair));
    }

    public function testNamesTheDiscriminatorThroughTheConverter(): void
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $names = new MetadataAwareNameConverter($metadata, new CamelCaseToSnakeCaseNameConverter());
        $objects = new ObjectNormalizer($metadata, $names, null, null, new ClassDiscriminatorFromClassMetadata($metadata));
        $serializer = new Serializer([new AdditionalPropertiesNormalizer($objects, $metadata, $names), $objects]);

        $raw = $serializer->denormalize(['vehicleType' => 'car', 'wheels' => 4, 'roof' => 'open'], Vehicle::class);

        self::assertInstanceOf(Car::class, $raw);
        self::assertSame(['roof' => 'open'], $raw->additionalProperties);
        $raw->additionalProperties = ['vehicleType' => 'bus'];
        try {
            $serializer->normalize($raw);
            self::fail('The type property itself is declared too.');
        } catch (UnexpectedValueException $exception) {
            self::assertStringContainsString('"vehicleType"', $exception->getMessage());
        }

        try {
            $objects->denormalize(['vehicle_type' => 'car'], Vehicle::class);
        } catch (NotNormalizableValueException $exception) {
            self::markTestSkipped('This Serializer reads the discriminator by its type property only (before 8.1).');
        }

        $car = $serializer->denormalize(['vehicle_type' => 'car', 'wheels' => 4, 'roof' => 'open'], Vehicle::class);

        self::assertInstanceOf(Car::class, $car);
        self::assertSame(['roof' => 'open'], $car->additionalProperties);

        $both = $serializer->denormalize(['vehicle_type' => 'car', 'vehicleType' => 'bus', 'roof' => 'open'], Vehicle::class);
        self::assertInstanceOf(Car::class, $both);
        self::assertSame(['roof' => 'open'], $both->additionalProperties, 'the converted key wins, as in Symfony');

        $styled = $serializer->denormalize(['vehicle_type' => 'car', 'body_style' => 'car', 'roof' => 'open'], BodyStyled::class);
        self::assertInstanceOf(Car::class, $styled);
        self::assertSame(['roof' => 'open'], $styled->additionalProperties);

        $car->additionalProperties = ['vehicle_type' => 'bus'];
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"vehicle_type"');
        $serializer->normalize($car);
    }

    public function testReadsTheDefaultTypeOfADiscriminator(): void
    {
        if (!(new ReflectionClass(ClassDiscriminatorMapping::class))->hasMethod('getDefaultType')) {
            self::markTestSkipped('Discriminators have a default type from symfony/serializer 7.3.');
        }

        $apple = $this->serializer->denormalize(['size' => 3, 'ripe' => 'yes'], Fruit::class);

        self::assertInstanceOf(Apple::class, $apple);
        self::assertSame(['ripe' => 'yes'], $apple->additionalProperties);
    }

    public function testReadsTheNamesOfEachFormat(): void
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $names = new class implements NameConverterInterface {
            public function normalize(string $propertyName, ?string $class = null, ?string $format = null, array $context = []): string
            {
                return $format === 'upper' ? strtoupper($propertyName) : $propertyName;
            }

            public function denormalize(string $propertyName, ?string $class = null, ?string $format = null, array $context = []): string
            {
                return $format === 'upper' ? strtolower($propertyName) : $propertyName;
            }
        };
        $objects = new ObjectNormalizer($metadata, $names);
        $serializer = new Serializer([new AdditionalPropertiesNormalizer($objects, $metadata, $names), $objects]);

        self::assertSame(['pears' => 1], $serializer->normalize(new Box(['pears' => 1]), 'upper'));
        self::assertSame(['pears' => 1], $serializer->normalize(new Box(['pears' => 1])));
    }

    public function testGivesTheWrappedNormalizerTheSerializer(): void
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new AdditionalPropertiesNormalizer(new ObjectNormalizer($metadata), $metadata)]);

        self::assertSame(['pears' => 1], $serializer->normalize(new Box(['pears' => 1])));
    }

    public function testWrapsOnlyANormalizerThatAlsoDenormalizes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wraps a normalizer that also denormalizes');

        new AdditionalPropertiesNormalizer($this->createMock(NormalizerInterface::class), new ClassMetadataFactory(new AttributeLoader()));
    }

    public function testNormalizesOnlyObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->normalize(['label' => 'box']);
    }

    public function testHandlesOnlyClassesWithTheMap(): void
    {
        self::assertTrue($this->normalizer->supportsNormalization(new Bag('box')));
        self::assertTrue($this->normalizer->supportsNormalization(new Crate(1)));
        self::assertFalse($this->normalizer->supportsNormalization(new Plain()));
        self::assertFalse($this->normalizer->supportsNormalization(new Plain()), 'answered from the cache');
        self::assertFalse($this->normalizer->supportsNormalization(['label' => 'box']));
        self::assertFalse($this->normalizer->supportsDenormalization(['label' => 'box'], Plain::class));
        self::assertFalse($this->normalizer->supportsDenormalization('box', Bag::class));
        self::assertFalse($this->normalizer->supportsDenormalization(['label' => 'box'], 'App\Missing'));
        self::assertFalse($this->normalizer->supportsDenormalization(['label' => 'box'], 'int'));
        self::assertSame(['object' => false], $this->normalizer->getSupportedTypes(null));
    }

    /**
     * @param array<string, string> $additionalProperties
     */
    private function plain(array $additionalProperties): Plain
    {
        $plain = new Plain();
        $plain->additionalProperties = $additionalProperties;

        return $plain;
    }
}
