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
 * Symfony Serializer attributes for classes and their properties (bridge spec §6). One instance serves both
 * extension points: the generator enriches a class before its properties, so the gate decides on the class.
 */
final class SerializerEnricher implements ClassEnricher, PropertyEnricher
{
    /** DateTimeNormalizer::FORMAT_KEY in every version the bridge supports. */
    private const FORMAT_KEY = 'datetime_format';

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
        $extensions = new ExtensionReader($schema, $context->diagnostics());
        if (!$version instanceof SymfonyVersion || $extensions->flag('x-serializer-skip')) {
            return [];
        }

        if ($extensions->flag('x-serializer-ignore')) {
            return [$this->attribute($version, 'Ignore')];
        }

        $property = $context->property();
        $attributes = [];
        if ($property->wireName() !== $property->name()) {
            $attributes[] = $this->attribute($version, 'SerializedName', [AttributeArgument::positional(ArgumentValue::literal($property->wireName()))]);
        }

        $groups = $extensions->groups('x-serializer-groups') ?? [];
        if ($groups !== []) {
            $names = array_map(static fn (string $group): ArgumentValue => ArgumentValue::literal($group), $groups);
            $attributes[] = $this->attribute($version, 'Groups', [AttributeArgument::positional(ArgumentValue::listOf(...$names))]);
        }

        if ($this->holdsDates($schema, $property->type(), $context->references(), $context->target())) {
            $attributes[] = $this->attribute($version, 'Context', [
                AttributeArgument::named('normalizationContext', ArgumentValue::mapOf([self::FORMAT_KEY => ArgumentValue::literal('Y-m-d')])),
                // "!" resets the time; createFromFormat() would take it from the clock otherwise.
                AttributeArgument::named('denormalizationContext', ArgumentValue::mapOf([self::FORMAT_KEY => ArgumentValue::literal('!Y-m-d')])),
            ]);
        }

        return $attributes;
    }

    /**
     * Whether the value, or every item of it, is a `format: date` the generator typed as the target's date class.
     */
    private function holdsDates(Schema $schema, TypeModel $type, SchemaReferences $references, TargetProfile $target): bool
    {
        $value = $type instanceof NullableType ? $type->inner() : $type;
        $resolved = (new Keywords($schema, $references))->resolved();
        if ($value instanceof ListType) {
            $items = $resolved->items();

            return $items instanceof Schema && $this->holdsDates($items, $value->item(), $references, $target);
        }

        if ($value instanceof MapType) {
            $items = $resolved->additionalProperties();

            return $items instanceof Schema && $this->holdsDates($items, $value->value(), $references, $target);
        }

        return $value instanceof ClassType
            && $value->className()->fqcn() === $target->dateTimeClass()->className()
            && $resolved->format() === 'date';
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
