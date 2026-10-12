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
     * @var array<string, array{Keywords, SchemaLocation, list<non-empty-string>}> with the groups of the class
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
        $groups = $this->groups($extensions);
        $this->classes[$context->class()->name()->fqcn()] = [$keywords, $schema->location(), $groups];
        $this->warnAboutNamesNotKept($context, $keywords);
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
            ->withGroups($groups)
            ->toAttribute()];
    }

    public function enrichProperty(PropertyContext $context): array
    {
        $schema = $context->schema();
        $diagnostics = $context->diagnostics();
        $version = $this->gate->version($context->packages(), $context->target(), $diagnostics, $schema->location());
        if (!$version instanceof SymfonyVersion) {
            return [];
        }

        $property = $context->property();
        $builder = new ConstraintBuilder($context->references(), $context->target(), $diagnostics, new RuntimeConstraints($context->packages(), $diagnostics));
        // `propertyNames` belongs to the class, so the schema of its undeclared properties neither skips nor groups it.
        $names = $property->isAdditionalProperties() ? $this->classNames($context, $builder) : [];
        $extensions = new ExtensionReader($schema, $diagnostics);
        $skipped = $extensions->flag('x-validator-skip');
        $groups = $this->groups($extensions);
        $type = $property->type();
        if ($skipped) {
            $constraints = [];
        } elseif ($property->isAdditionalProperties()) {
            // Its schema is that of each undeclared property, not of the map.
            assert($type instanceof MapType);
            $constraints = $builder->elements($schema, $type->value(), $schema->location());
        } else {
            // A required property is non-nullable, except a mixed one (null among its values): the generator makes a
            // nullable property optional otherwise.
            $constraints = array_merge(
                $property->isRequired() && !$type instanceof MixedType ? [new ConstraintSpec('NotNull')] : [],
                $builder->build($schema, $type),
            );
        }

        return array_merge(
            array_map(static fn (ConstraintSpec $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(), $constraints),
            $names,
        );
    }

    /**
     * @return list<AttributeModel> the Keys of `propertyNames`, a keyword of the class, in the groups of the class
     */
    private function classNames(PropertyContext $context, ConstraintBuilder $builder): array
    {
        $class = $this->classes[$context->owner()->name()->fqcn()] ?? null;
        if ($class === null) {
            return [];
        }

        [$keywords, $at, $groups] = $class;

        return array_map(static fn (ConstraintSpec $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(), $builder->names($keywords, $at));
    }

    /**
     * Without $additionalProperties the class keeps no undeclared property whose name could be checked; with
     * `additionalProperties: false` there is none to check.
     */
    private function warnAboutNamesNotKept(ClassContext $context, Keywords $keywords): void
    {
        $schema = $context->schema();
        foreach ($context->class()->properties() as $property) {
            if ($property->isAdditionalProperties()) {
                return;
            }
        }

        if ($keywords->has('propertyNames') && $schema->additionalProperties() !== false) {
            $context->diagnostics()->warning('"propertyNames" is checked on the undeclared properties of a class, which this class does not keep; it is not checked.', $schema->location());
        }
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
