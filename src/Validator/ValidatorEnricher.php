<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\ComponentGate;
use Msstc4Symfony\DtoGeneratorBridge\ExtensionReader;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;
use Msstc4Symfony\DtoGeneratorBridge\Settings;
use Msstc4Symfony\DtoGeneratorBridge\SymfonyVersion;

/**
 * Symfony Validator constraints for every property and class (bridge spec §5); ComponentGate decides whether to write
 * any.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ValidatorEnricher implements ClassEnricher, PropertyEnricher
{
    private Settings $settings;

    private ComponentGate $gate;

    /**
     * The keywords of each checked class and where they are, by FQCN: the generator enriches a class before its
     * properties, and the context of $additionalProperties does not carry the class's schema.
     *
     * @var array<string, array{Keywords, SchemaLocation}>
     */
    private array $classes = [];

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->gate = ComponentGate::validator($settings);
    }

    public function enrichClass(ClassContext $context): array
    {
        $schema = $context->schema();
        $diagnostics = $context->diagnostics();
        $version = $this->gate->version($context->packages(), $context->target(), $diagnostics, $schema->location());
        $extensions = new ExtensionReader($schema, $diagnostics);
        if (!$version instanceof SymfonyVersion || $extensions->flag('x-validator-skip')) {
            return [];
        }

        $keywords = new Keywords($schema, $context->references());
        $this->classes[$context->class()->name()->fqcn()] = [$keywords, $schema->location()];
        if (!$keywords->has('dependentRequired') || !(new RuntimeConstraints($context->packages(), $diagnostics))->available('dependentRequired', $schema->location())) {
            return [];
        }

        $requirements = (new DependentRequirements($context->class(), $diagnostics, $schema->location()))->of($keywords);
        if ($requirements === []) {
            return [];
        }

        $map = array_map(
            static fn (array $dependents): ArgumentValue => ArgumentValue::listOf(...array_map(static fn (string $name): ArgumentValue => ArgumentValue::literal($name), $dependents)),
            $requirements,
        );

        return [ConstraintSpec::runtime('DependentRequired', [AttributeArgument::named('requirements', ArgumentValue::mapOf($map))])
            ->withGroups($this->groups($extensions))
            ->toAttribute()];
    }

    public function enrichProperty(PropertyContext $context): array
    {
        $schema = $context->schema();
        $version = $this->gate->version($context->packages(), $context->target(), $context->diagnostics(), $schema->location());
        $extensions = new ExtensionReader($schema, $context->diagnostics());
        if (!$version instanceof SymfonyVersion || $extensions->flag('x-validator-skip')) {
            return [];
        }

        $property = $context->property();
        $diagnostics = $context->diagnostics();
        $builder = new ConstraintBuilder($context->references(), $context->target(), $diagnostics, new RuntimeConstraints($context->packages(), $diagnostics));
        $groups = $this->groups($extensions);

        $type = $property->type();
        if ($property->isAdditionalProperties()) {
            // Its schema is that of each undeclared property, not of the map.
            assert($type instanceof MapType);
            $constraints = array_merge($builder->elements($schema, $type->value(), $schema->location()), $this->ownerNames($context, $builder));
        } else {
            // A required property is non-nullable, except a mixed one (null among its values): the generator makes a
            // nullable property optional otherwise.
            $constraints = array_merge(
                $property->isRequired() && !$type instanceof MixedType ? [new ConstraintSpec('NotNull')] : [],
                $builder->build($schema, $type),
            );
        }

        return array_map(
            static fn (ConstraintSpec $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(),
            $constraints,
        );
    }

    /**
     * @return list<ConstraintSpec> the Keys of `propertyNames`, a keyword of the class
     */
    private function ownerNames(PropertyContext $context, ConstraintBuilder $builder): array
    {
        $class = $this->classes[$context->owner()->name()->fqcn()] ?? null;

        return $class === null ? [] : $builder->names(...$class);
    }

    /**
     * Constraints with groups leave the Default group, so validate($dto) would skip them: Default is added unless the
     * schema says x-validator-groups-exclusive.
     *
     * @return list<non-empty-string>
     */
    private function groups(ExtensionReader $extensions): array
    {
        $groups = $extensions->groups('x-validator-groups') ?? $this->settings->groups();
        $exclusive = $extensions->flag('x-validator-groups-exclusive');
        if ($groups === [] || $exclusive || in_array('Default', $groups, true)) {
            return $groups;
        }

        $groups[] = 'Default';

        return $groups;
    }
}
