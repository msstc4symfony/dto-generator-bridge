<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Serializer;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use Msstc4Symfony\DtoGeneratorBridge\ComponentGate;
use Msstc4Symfony\DtoGeneratorBridge\ExtensionReader;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;
use Msstc4Symfony\DtoGeneratorBridge\Settings;
use Msstc4Symfony\DtoGeneratorBridge\SymfonyVersion;

/**
 * Symfony Serializer attributes for classes and their properties (bridge spec §6). One instance, and so one gate,
 * serves both extension points: whether to write is decided once per run.
 */
final class SerializerEnricher implements ClassEnricher, PropertyEnricher
{
    /** DateTimeNormalizer::FORMAT_KEY in every version the bridge supports. */
    private const FORMAT_KEY = 'datetime_format';

    /** The Serializer that deprecates reading a date-time off its default format without a context saying so. */
    private const LOOSE_DATE_TIME_SINCE = '8.1';

    private ComponentGate $gate;

    public function __construct(Settings $settings)
    {
        $this->gate = ComponentGate::serializer($settings);
    }

    public function enrichClass(ClassContext $context): array
    {
        $version = $this->gate->version($context->packages(), $context->target(), $context->diagnostics(), $context->schema()->location());
        // The generator gives a discriminator to the abstract base of a union or an allOf hierarchy only.
        $discriminator = $context->class()->discriminator();
        if (!$version instanceof SymfonyVersion || !$discriminator instanceof DiscriminatorModel) {
            return [];
        }

        $mapping = array_map(static fn (ClassName $subclass): ArgumentValue => ArgumentValue::classReference($subclass), $discriminator->mapping());

        return [$this->attribute($version, 'DiscriminatorMap', [
            AttributeArgument::named('typeProperty', ArgumentValue::literal($discriminator->propertyName())),
            AttributeArgument::named('mapping', ArgumentValue::mapOf($mapping)),
        ])];
    }

    public function enrichProperty(PropertyContext $context): array
    {
        $schema = $context->schema();
        $version = $this->gate->version($context->packages(), $context->target(), $context->diagnostics(), $schema->location());
        if (!$version instanceof SymfonyVersion) {
            return [];
        }

        $extensions = new ExtensionReader($schema, $context->diagnostics());
        if ($extensions->flag('x-serializer-skip')) {
            return [];
        }

        $property = $context->property();
        // x-serializer-ignore on the schema of the values confirms the choice, so it needs no warning.
        if ($property->isAdditionalProperties() && !$extensions->flag('x-serializer-ignore')) {
            $context->diagnostics()->warning(
                'Symfony Serializer would carry the undeclared properties as one key "additionalProperties", so $additionalProperties is ignored: they are dropped when reading and not written.',
                $schema->location(),
            );

            return [$this->attribute($version, 'Ignore')];
        }

        if ($extensions->flag('x-serializer-ignore')) {
            // A required property is a constructor parameter without a default, even with a schema default.
            if ($property->isRequired()) {
                $context->diagnostics()->warning(
                    'x-serializer-ignore on a required property leaves the serializer nothing to pass to the constructor; denormalizing fails.',
                    $schema->location(),
                );
            }

            return [$this->attribute($version, 'Ignore')];
        }

        $attributes = [];
        if ($property->wireName() !== $property->name()) {
            $attributes[] = $this->attribute($version, 'SerializedName', [AttributeArgument::positional(ArgumentValue::literal($property->wireName()))]);
        }

        $groups = $extensions->groups('x-serializer-groups') ?? [];
        if ($groups !== []) {
            $names = array_map(static fn (string $group): ArgumentValue => ArgumentValue::literal($group), $groups);
            $attributes[] = $this->attribute($version, 'Groups', [AttributeArgument::positional(ArgumentValue::listOf(...$names))]);
        }

        $references = $context->references();
        $target = $context->target();
        if ($this->holds('date', $schema, $property->type(), $references, $target)) {
            $attributes[] = $this->attribute($version, 'Context', [
                AttributeArgument::named('normalizationContext', ArgumentValue::mapOf([self::FORMAT_KEY => ArgumentValue::literal('Y-m-d')])),
                // "!" resets the time; createFromFormat() would take it from the clock otherwise.
                AttributeArgument::named('denormalizationContext', ArgumentValue::mapOf([self::FORMAT_KEY => ArgumentValue::literal('!Y-m-d')])),
            ]);
        }

        // Serializer 8.1 deprecates reading a date-time off its default format, such as RFC 3339 with fractions of a
        // second, unless the context asks for the loose parser; 9.0 rejects it.
        if ($version->isAtLeast(SymfonyVersion::fromString(self::LOOSE_DATE_TIME_SINCE)) && $this->holds('date-time', $schema, $property->type(), $references, $target)) {
            $attributes[] = $this->attribute($version, 'Context', [
                AttributeArgument::named('denormalizationContext', ArgumentValue::mapOf([self::FORMAT_KEY => ArgumentValue::literal(null)])),
            ]);
        }

        return $attributes;
    }

    /**
     * Whether the value, or every item of it, has the format and the generator typed it as the target's date class.
     *
     * @param 'date'|'date-time' $format
     */
    private function holds(string $format, Schema $schema, TypeModel $type, SchemaReferences $references, TargetProfile $target): bool
    {
        $value = $type instanceof NullableType ? $type->inner() : $type;
        $resolved = (new Keywords($schema, $references))->resolved();
        if ($value instanceof ListType) {
            $items = $resolved->items();

            return $items instanceof Schema && $this->holds($format, $items, $value->item(), $references, $target);
        }

        if ($value instanceof MapType) {
            $items = $resolved->additionalProperties();

            return $items instanceof Schema && $this->holds($format, $items, $value->value(), $references, $target);
        }

        return $value instanceof ClassType
            && $value->className()->fqcn() === $target->dateTimeClass()->className()
            && $resolved->format() === $format;
    }

    /**
     * Attributes moved from Serializer\Annotation to Serializer\Attribute in 6.4.
     *
     * @param non-empty-string $name
     * @param list<AttributeArgument> $arguments
     */
    private function attribute(SymfonyVersion $version, string $name, array $arguments = []): AttributeModel
    {
        $namespace = $version->isAtLeast(SymfonyVersion::fromString('6.4'))
            ? 'Symfony\Component\Serializer\Attribute'
            : 'Symfony\Component\Serializer\Annotation';

        return new AttributeModel(ClassName::fromFqcn($namespace . '\\' . $name), $arguments, new ImportAlias($namespace, 'Serializer'));
    }
}
