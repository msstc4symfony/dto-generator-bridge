<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Runtime;

use ReflectionClass;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorMapping;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Writes the entries of the property marked with AdditionalProperties as keys of the object, and reads the keys the
 * class does not declare into it. The object itself goes to the wrapped object normalizer; its values and nested
 * objects go through the Serializer, so nested DTOs are spread too.
 *
 * Runs on PHP 8.0+ with symfony/serializer 5.4 to 8, but stays parseable by PHP 7.4, which lints the whole package:
 * hence no union or intersection types, and normalize() declares array, the one type of the interface's union it
 * returns.
 *
 * @api
 */
final class AdditionalPropertiesNormalizer implements NormalizerInterface, DenormalizerInterface, SerializerAwareInterface
{
    /** @var NormalizerInterface&DenormalizerInterface */
    private NormalizerInterface $objects;

    private ClassMetadataFactoryInterface $metadata;

    private NameConverterInterface $names;

    /** @var array<string, ClassLayout|null> per class and format; null for a class without the marker */
    private array $layouts = [];

    /** @var array<string, ClassDiscriminatorMapping|null> per class or interface */
    private array $mappings = [];

    /**
     * @param NormalizerInterface $objects the object normalizer, usually ObjectNormalizer; it must denormalize too
     * @param ClassMetadataFactoryInterface $metadata the factory the wrapped normalizer reads
     * @param NameConverterInterface|null $names the wrapped normalizer's name converter; by default the one that reads
     *                                           SerializedName from the metadata
     *
     * @throws InvalidArgumentException when the wrapped normalizer cannot denormalize
     */
    public function __construct(NormalizerInterface $objects, ClassMetadataFactoryInterface $metadata, ?NameConverterInterface $names = null)
    {
        // Native intersection types need PHP 8.1, and the package must parse on 7.4.
        if (!$objects instanceof DenormalizerInterface) {
            throw new InvalidArgumentException(sprintf('%s wraps a normalizer that also denormalizes, %s does not.', self::class, get_class($objects)));
        }

        $this->objects = $objects;
        $this->metadata = $metadata;
        $this->names = $names ?? new MetadataAwareNameConverter($metadata);
    }

    /**
     * Hands the Serializer on, so the wrapped normalizer works outside the Serializer's chain too.
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->objects instanceof SerializerAwareInterface) {
            $this->objects->setSerializer($serializer);
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException when an entry of the map has the name of a declared property
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!is_object($data)) {
            throw new InvalidArgumentException(sprintf('%s normalizes objects only.', self::class));
        }

        $normalized = $this->objects->normalize($data, $format, $context);
        // An object with nothing to write; with preserve_empty_objects it is an ArrayObject, which array cannot carry.
        if (!is_array($normalized)) {
            return [];
        }

        $class = get_class($data);
        $layout = $this->layout($class, $format);
        if (!$layout instanceof ClassLayout || !array_key_exists($layout->key(), $normalized)) {
            return $normalized;
        }

        $entries = $normalized[$layout->key()];
        unset($normalized[$layout->key()]);
        foreach (is_array($entries) ? $entries : [] as $key => $value) {
            if ($layout->declares((string) $key)) {
                throw new UnexpectedValueException(sprintf('The additional property "%s" of %s has the name of a declared property.', $key, $class));
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_object($data) && $this->layout(get_class($data), $format) instanceof ClassLayout;
    }

    /**
     * @template TObject of object
     *
     * @param class-string<TObject>|string $type
     * @param array<string, mixed> $context
     *
     * @return ($type is class-string<TObject> ? TObject : mixed)
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $target = is_array($data) ? $this->target($data, $type, $format) : null;
        $layout = $target === null ? null : $this->layout($target[0], $format);
        if ($target !== null && $layout instanceof ClassLayout && is_array($data)) {
            // Read through another discriminated base than the one Symfony writes, its keys select the variant too.
            $undeclared = array_diff_key($data, $layout->declared(), array_flip($target[1]));
            $data = array_diff_key($data, $undeclared);
            if ($undeclared !== []) {
                $data[$layout->key()] = $undeclared;
            }
        }

        return $this->objects->denormalize($data, $type, $format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        $target = is_array($data) ? $this->target($data, $type, $format) : null;

        return $target !== null && $this->layout($target[0], $format) instanceof ClassLayout;
    }

    /**
     * Not cacheable: reading a discriminated base depends on the data.
     *
     * @return array{object: false}
     */
    public function getSupportedTypes(?string $format): array
    {
        return ['object' => false];
    }

    /**
     * The class the data stands for, as AbstractObjectNormalizer::getMappedClass() picks it: the variant a discriminated
     * base or interface maps the data's type key (or its default type, Symfony 7.3+) to, else the type itself; with the
     * keys that type key may have.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array{class-string, list<string>}|null
     */
    private function target(array $data, string $type, ?string $format): ?array
    {
        if (!class_exists($type) && !interface_exists($type)) {
            return null;
        }

        $mapping = $this->mapping($type);
        if (!$mapping instanceof ClassDiscriminatorMapping) {
            return class_exists($type) ? [$type, []] : null;
        }

        $keys = $this->typeKeysOf($mapping, $type, $format);
        // Serializer 7.3 added the default type.
        $default = (new ReflectionClass($mapping))->hasMethod('getDefaultType') ? $mapping->getDefaultType() : null;
        $value = $data[$keys[0]] ?? $data[$keys[1]] ?? $default;
        $variant = is_string($value) || is_int($value) ? $mapping->getClassForType((string) $value) : null;

        return $variant !== null && class_exists($variant) ? [$variant, $keys] : null;
    }

    /**
     * The converted key of the discriminator, which Symfony writes, and the type property itself, which Symfony reads
     * when the converted key is missing (only that one before 8.1).
     *
     * @param class-string $class
     *
     * @return array{string, string}
     */
    private function typeKeysOf(ClassDiscriminatorMapping $mapping, string $class, ?string $format): array
    {
        return [$this->names->normalize($mapping->getTypeProperty(), $class, $format), $mapping->getTypeProperty()];
    }

    private function mapping(string $class): ?ClassDiscriminatorMapping
    {
        if (!array_key_exists($class, $this->mappings)) {
            $this->mappings[$class] = $this->metadata->getMetadataFor($class)->getClassDiscriminatorMapping();
        }

        return $this->mappings[$class];
    }

    /**
     * @param class-string $class
     */
    private function layout(string $class, ?string $format): ?ClassLayout
    {
        $key = serialize([$class, $format]);
        if (!array_key_exists($key, $this->layouts)) {
            $this->layouts[$key] = $this->read($class, $format);
        }

        return $this->layouts[$key];
    }

    /**
     * The keys a class declares are its serialized attributes other than the map, named as the wrapped normalizer
     * names them, and the type property of the discriminator Symfony writes for it. Names are read once per class and
     * format, so a name converter must not name by context.
     *
     * @param class-string $class
     */
    private function read(string $class, ?string $format): ?ClassLayout
    {
        $metadata = $this->metadata->getMetadataFor($class);
        $map = $this->marked($metadata->getReflectionClass());
        if ($map === null) {
            return null;
        }

        $declared = [];
        foreach (array_keys($metadata->getAttributesMetadata()) as $name) {
            if ($name !== $map) {
                $declared[$this->names->normalize($name, $class, $format)] = true;
            }
        }

        $mapping = $this->mappedBy($class);
        if ($mapping instanceof ClassDiscriminatorMapping) {
            foreach ($this->typeKeysOf($mapping, $class, $format) as $key) {
                $declared[$key] = true;
            }
        }

        return new ClassLayout($this->names->normalize($map, $class, $format), $declared);
    }

    /**
     * The discriminator Symfony writes for an object of the class: its own, else its parent's (recursively), else its
     * interfaces', each with its own parents (ClassDiscriminatorFromClassMetadata::getMappingForMappedObject()). A
     * subclass's metadata does not repeat the mapping of its base.
     *
     * @param class-string $class
     */
    private function mappedBy(string $class): ?ClassDiscriminatorMapping
    {
        $mapping = $this->mapping($class);
        $parent = get_parent_class($class);
        if (!$mapping instanceof ClassDiscriminatorMapping && $parent !== false) {
            $mapping = $this->mappedBy($parent);
        }

        foreach ($mapping instanceof ClassDiscriminatorMapping ? [] : (new ReflectionClass($class))->getInterfaceNames() as $interface) {
            $mapping ??= $this->mappedBy($interface);
        }

        return $mapping;
    }

    /**
     * The property marked in the class or a parent, which may declare it private.
     *
     * @param ReflectionClass<object> $class
     */
    private function marked(ReflectionClass $class): ?string
    {
        for ($reflection = $class; $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                if ($property->getAttributes(AdditionalProperties::class) !== []) {
                    return $property->getName();
                }
            }
        }

        return null;
    }
}
