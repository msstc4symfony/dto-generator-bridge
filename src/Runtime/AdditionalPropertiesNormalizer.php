<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Runtime;

use ReflectionClass;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorMapping;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Writes the entries of the property marked with AdditionalProperties as keys of the object, and reads the keys the
 * class does not declare into it. The object itself goes to the wrapped normalizer, which must also be in the
 * Serializer's chain: values and nested objects go through the chain, so nested DTOs are spread too.
 *
 * Runs on PHP 8.0+ with symfony/serializer 5.4 to 8, but stays parseable by PHP 7.4, which lints the whole package:
 * hence no union types, and normalize() declares array, the one type of the interface's union it returns.
 *
 * @api
 */
final class AdditionalPropertiesNormalizer implements NormalizerInterface, DenormalizerInterface
{
    private AbstractObjectNormalizer $objects;

    private ClassMetadataFactoryInterface $metadata;

    /** @var array<class-string, array{string, list<string>}|false> per class: its map and the keys it declares, or false */
    private array $classes = [];

    /**
     * @param ClassMetadataFactoryInterface $metadata the factory the wrapped normalizer reads, for the declared keys
     */
    public function __construct(AbstractObjectNormalizer $objects, ClassMetadataFactoryInterface $metadata)
    {
        $this->objects = $objects;
        $this->metadata = $metadata;
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
        $normalized = $this->objects->normalize($data, $format, $context);
        // An object with nothing to write; with preserve_empty_objects it is an ArrayObject, which array cannot carry.
        if (!is_array($normalized) || !is_object($data)) {
            return [];
        }

        $class = get_class($data);
        $described = $this->describe($class);
        if ($described === false || !array_key_exists($described[0], $normalized)) {
            return $normalized;
        }

        [$property, $declared] = $described;
        $entries = $normalized[$property];
        unset($normalized[$property]);
        foreach (is_array($entries) ? $entries : [] as $key => $value) {
            if (in_array((string) $key, $declared, true)) {
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
        return is_object($data) && $this->describe(get_class($data)) !== false;
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
        $class = is_array($data) ? $this->concrete($data, $type) : null;
        $described = $class === null ? false : $this->describe($class);
        if ($described !== false && is_array($data)) {
            [$property, $declared] = $described;
            $undeclared = array_diff_key($data, array_flip($declared));
            $data = array_diff_key($data, $undeclared);
            if ($undeclared !== []) {
                $data[$property] = $undeclared;
            }
        }

        return $this->objects->denormalize($data, $type, $format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        $class = is_array($data) ? $this->concrete($data, $type) : null;

        return $class !== null && $this->describe($class) !== false;
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
     * The class the data stands for: the variant a discriminated base maps it to, else the type itself.
     *
     * @param array<array-key, mixed> $data
     *
     * @return class-string|null
     */
    private function concrete(array $data, string $type): ?string
    {
        if (!class_exists($type)) {
            return null;
        }

        $mapping = $this->metadata->getMetadataFor($type)->getClassDiscriminatorMapping();
        if (!$mapping instanceof ClassDiscriminatorMapping) {
            return $type;
        }

        $value = $data[$mapping->getTypeProperty()] ?? null;
        $variant = is_string($value) || is_int($value) ? $mapping->getClassForType((string) $value) : null;

        return $variant !== null && class_exists($variant) ? $variant : null;
    }

    /**
     * @param class-string $class
     *
     * @return array{string, list<string>}|false
     */
    private function describe(string $class)
    {
        return $this->classes[$class] ??= $this->read($class);
    }

    /**
     * The property marked in the class or a parent, which may declare it private, and the keys the class declares: its
     * serialized attributes other than the map, and the type property of every discriminated ancestor, whose mapping a
     * subclass's metadata does not repeat.
     *
     * @param class-string $class
     *
     * @return array{string, list<string>}|false
     */
    private function read(string $class)
    {
        $metadata = $this->metadata->getMetadataFor($class);
        $map = $this->marked($metadata->getReflectionClass());
        if ($map === null) {
            return false;
        }

        $declared = [];
        foreach ($metadata->getAttributesMetadata() as $name => $attribute) {
            if ($name !== $map) {
                $declared[] = $attribute->getSerializedName() ?? $name;
            }
        }

        for ($ancestor = $class; $ancestor !== false; $ancestor = get_parent_class($ancestor)) {
            $mapping = $this->metadata->getMetadataFor($ancestor)->getClassDiscriminatorMapping();
            if ($mapping instanceof ClassDiscriminatorMapping) {
                $declared[] = $mapping->getTypeProperty();
            }
        }

        return [$map, $declared];
    }

    /**
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
