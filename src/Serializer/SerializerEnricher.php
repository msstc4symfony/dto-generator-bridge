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
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use Msstc4Symfony\DtoGeneratorBridge\ComponentGate;
use Msstc4Symfony\DtoGeneratorBridge\ExtensionReader;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\CollectionNormalizer;
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

    /** DATE_RFC2822, for writing and reading alike. */
    private const RFC_2822 = 'D, d M Y H:i:s O';

    /** The Serializer that deprecates reading a date-time off its default format without a context saying so. */
    private const LOOSE_DATE_TIME_SINCE = '8.1';

    /** The Serializer whose DiscriminatorMap takes a default type. */
    private const DEFAULT_TYPE_SINCE = '7.3';

    private ComponentGate $gate;

    private Settings $settings;

    private bool $refusedToSpread = false;

    public function __construct(Settings $settings)
    {
        $this->gate = ComponentGate::serializer($settings);
        $this->settings = $settings;
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
        $arguments = [
            AttributeArgument::named('typeProperty', ArgumentValue::literal($discriminator->propertyName())),
            AttributeArgument::named('mapping', ArgumentValue::mapOf($mapping)),
        ];
        $defaultType = $this->defaultType($context, $discriminator, $version);
        if ($defaultType !== null) {
            $arguments[] = AttributeArgument::named('defaultType', ArgumentValue::literal($defaultType));
        }

        return [$this->attribute($version, 'DiscriminatorMap', $arguments)];
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
        $attributes = [];
        // x-serializer-ignore on the schema of the values confirms the choice, so it needs no warning.
        if ($property->isAdditionalProperties() && !$extensions->flag('x-serializer-ignore')) {
            if (!$this->spreads($context)) {
                return [$this->attribute($version, 'Ignore')];
            }

            $attributes[] = new AttributeModel(ClassName::fromFqcn(AdditionalProperties::class));
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
        // One Context per property: Symfony keeps only the last one for a group.
        $shared = [];
        $contexts = ['normalizationContext' => [], 'denormalizationContext' => []];
        if ($this->holds('date', $schema, $property->type(), $references, $target)) {
            $contexts['normalizationContext'][self::FORMAT_KEY] = ArgumentValue::literal('Y-m-d');
            // "!" resets the time; createFromFormat() would take it from the clock otherwise.
            $contexts['denormalizationContext'][self::FORMAT_KEY] = ArgumentValue::literal('!Y-m-d');
        }

        // Twilio's dates: `Mon, 16 Aug 2010 03:45:01 +0000`.
        if ($this->holds('date-time-rfc-2822', $schema, $property->type(), $references, $target)) {
            $shared[self::FORMAT_KEY] = ArgumentValue::literal(self::RFC_2822);
        }

        // Serializer 8.1 deprecates reading a date-time off its default format, such as RFC 3339 with fractions of a
        // second, unless the context asks for the loose parser; 9.0 rejects it.
        if ($version->isAtLeast(SymfonyVersion::fromString(self::LOOSE_DATE_TIME_SINCE)) && $this->holds('date-time', $schema, $property->type(), $references, $target)) {
            $contexts['denormalizationContext'][self::FORMAT_KEY] = ArgumentValue::literal(null);
        }

        $collection = $this->collection($property->type(), $target);
        if ($collection instanceof ArgumentValue) {
            if ($contexts['normalizationContext'] !== [] || $contexts['denormalizationContext'] !== []) {
                $contexts['normalizationContext'][CollectionNormalizer::CONTEXT] = $collection;
                $contexts['denormalizationContext'][CollectionNormalizer::CONTEXT] = $collection;
            } else {
                $shared[CollectionNormalizer::CONTEXT] = $collection;
            }
        }

        $arguments = $shared === [] ? [] : [AttributeArgument::named('context', ArgumentValue::mapOf($shared))];
        foreach ($contexts as $name => $entries) {
            if ($entries !== []) {
                $arguments[] = AttributeArgument::named($name, ArgumentValue::mapOf($entries));
            }
        }

        if ($arguments !== []) {
            $attributes[] = $this->attribute($version, 'Context', $arguments);
        }

        return $attributes;
    }

    /**
     * What CollectionNormalizer reads for a list held by a collection object: the class and the Serializer type of an
     * item.
     */
    private function collection(TypeModel $type, TargetProfile $target): ?ArgumentValue
    {
        $value = $type instanceof NullableType ? $type->inner() : $type;
        if (!$value instanceof CollectionType) {
            return null;
        }

        return ArgumentValue::mapOf([
            'class' => ArgumentValue::literal($value->className()->fqcn()),
            'item' => ArgumentValue::literal($this->serializerType($value->list()->item(), $target)),
        ]);
    }

    /**
     * The type the Serializer denormalizes an item as; "mixed" for one it has no type for (a union, a list of scalars),
     * which CollectionNormalizer takes as it is.
     */
    private function serializerType(TypeModel $item, TargetProfile $target): string
    {
        if ($item instanceof EnumType && !$target->supports(Capability::from(Capability::ENUMS))) {
            return $item->backing()->value();
        }

        if ($item instanceof ClassType || $item instanceof EnumType) {
            return $item->className()->fqcn();
        }

        if ($item instanceof ScalarType) {
            return $item->kind();
        }

        $inner = $item instanceof ListType ? $this->serializerType($item->item(), $target) : 'mixed';

        return in_array($inner, ['mixed', 'int', 'float', 'string', 'bool'], true) ? 'mixed' : $inner . '[]';
    }

    /**
     * The value of the default variant, which Symfony takes only when the payload lacks the property.
     */
    private function defaultType(ClassContext $context, DiscriminatorModel $discriminator, SymfonyVersion $version): ?string
    {
        $default = $discriminator->defaultClass();
        if (!$default instanceof ClassName) {
            return null;
        }

        $at = $context->schema()->location()->child('discriminator', 'defaultMapping');
        if (!$version->isAtLeast(SymfonyVersion::fromString(self::DEFAULT_TYPE_SINCE))) {
            $context->diagnostics()->warning(
                sprintf('The Serializer of Symfony %s has no default type in DiscriminatorMap (7.3 adds it): a payload without "%s" does not select %s.', $version->toString(), $discriminator->propertyName(), $default->fqcn()),
                $at,
            );

            return null;
        }

        $value = $discriminator->defaultValue();
        if ($value === null) {
            $context->diagnostics()->warning(
                sprintf('%s has no discriminator value, so DiscriminatorMap cannot name it its default type: a payload without "%s" does not select it.', $default->fqcn(), $discriminator->propertyName()),
                $at,
            );
        }

        return $value;
    }

    /**
     * Whether $additionalProperties gets the marker of AdditionalPropertiesNormalizer rather than Ignore. A target that
     * writes annotations cannot carry it: that is an error, once per run, and the run writes nothing.
     */
    private function spreads(PropertyContext $context): bool
    {
        $at = $context->schema()->location();
        if (!$this->settings->spreadsAdditionalProperties()) {
            $context->diagnostics()->warning(
                'Symfony Serializer would carry the undeclared properties as one key "additionalProperties", so $additionalProperties is ignored: they are dropped when reading and not written.',
                $at,
            );

            return false;
        }

        if (!$context->target()->metadata()->isAttributes() && !$this->refusedToSpread) {
            $this->refusedToSpread = true;
            $context->diagnostics()->error(
                'extensionConfig.symfony.additionalProperties: spread needs PHP attributes, which AdditionalPropertiesNormalizer reads; the target writes annotations.',
                $at,
            );
        }

        return true;
    }

    /**
     * Whether the value, or every item of it, has the format and the generator typed it as the target's date class.
     *
     * @param 'date'|'date-time'|'date-time-rfc-2822' $format
     */
    private function holds(string $format, Schema $schema, TypeModel $type, SchemaReferences $references, TargetProfile $target): bool
    {
        $value = $type instanceof NullableType ? $type->inner() : $type;
        $value = $value instanceof CollectionType ? $value->list() : $value;

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
            && $resolved->normalizedFormat() === $format;
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
