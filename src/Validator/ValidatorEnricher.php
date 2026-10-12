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
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
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
     * Each checked class by FQCN: the generator enriches a class before its properties.
     *
     * @var array<string, ClassKeywords>
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
        $runtime = new RuntimeConstraints($context->packages(), $diagnostics);
        $this->classes[$context->class()->name()->fqcn()] = new ClassKeywords($keywords, $schema->location(), $groups, $this->patterns($context, $keywords));
        $this->warnAboutNamesNotKept($context, $keywords);
        foreach (['dependentRequired', 'if', 'then', 'else'] as $keyword) {
            $this->markChecked($context, $keywords, $keyword);
        }

        $builder = new ConstraintBuilder($context->references(), $context->target(), $diagnostics, $runtime, $context->keywordChecks(), $groups);
        $constraints = array_merge($this->requirements($context, $keywords, $runtime), $this->conditions($context, $keywords, $builder, $runtime, $groups));

        return array_map(static fn (ConstraintSpec $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(), $constraints);
    }

    /**
     * @return list<ConstraintSpec> DependentRequired with the pairs the class checks
     */
    private function requirements(ClassContext $context, Keywords $keywords, RuntimeConstraints $runtime): array
    {
        $at = $context->schema()->location();
        if (!$keywords->has('dependentRequired') || !$runtime->available('dependentRequired', $at)) {
            return [];
        }

        $requirements = (new DependentRequirements($context->class(), $context->diagnostics(), $at))->of($keywords);
        if ($requirements === []) {
            return [];
        }

        $map = array_map(
            static fn (array $dependents): ArgumentValue => ArgumentValue::listOf(...array_map(static fn (string $name): ArgumentValue => ArgumentValue::literal($name), $dependents)),
            $requirements,
        );

        return [ConstraintSpec::runtime('DependentRequired', [AttributeArgument::named('requirements', ArgumentValue::mapOf($map))])];
    }

    /**
     * @param list<non-empty-string> $groups
     *
     * @return list<ConstraintSpec> IfThenElse for each condition the class checks
     */
    private function conditions(ClassContext $context, Keywords $keywords, ConstraintBuilder $builder, RuntimeConstraints $runtime, array $groups): array
    {
        if (ConstraintBuilder::conditionPlaces($keywords) === [] || !$runtime->available('if', $context->schema()->location())) {
            return [];
        }

        return (new ClassConditions($context->class(), $builder, $context->diagnostics(), $groups))->of($keywords);
    }

    /**
     * The patterns of the class, for its declared properties and its $additionalProperties, each place marked: every
     * pattern is checked or warned about, and undeclared properties the class does not keep are warned about.
     *
     * @return list<PatternSchema>
     */
    private function patterns(ClassContext $context, Keywords $keywords): array
    {
        $this->markChecked($context, $keywords, 'patternProperties');
        if (!$keywords->has('patternProperties')) {
            return [];
        }

        $schema = $context->schema();
        $diagnostics = $context->diagnostics();
        if (!$this->keepsUndeclared($context)) {
            $diagnostics->warning('"patternProperties" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; they are not checked.', $schema->location());
        } elseif ($schema->additionalProperties() instanceof Schema) {
            $diagnostics->warning(PatternSchema::ALSO_ADDITIONAL, $schema->location());
        }

        $patterns = PatternSchema::allOf($keywords, $diagnostics);
        $this->warnAboutInheritedMatches($context, $keywords, $patterns);

        return $patterns;
    }

    /**
     * The constraints of a pattern go on the properties of the class; an inherited property belongs to the parent,
     * which checks only the patterns of the schemas that declare it.
     *
     * @param list<PatternSchema> $patterns
     */
    private function warnAboutInheritedMatches(ClassContext $context, Keywords $keywords, array $patterns): void
    {
        $own = [];
        foreach ($context->class()->properties() as $property) {
            $own[] = $property->wireName();
        }

        foreach ($keywords->schemas() as $place) {
            foreach (array_diff($place->propertyNames(), $own) as $name) {
                foreach ($patterns as $pattern) {
                    if ($pattern->matches($name) && !$pattern->declares($name)) {
                        $context->diagnostics()->warning(sprintf(
                            '"patternProperties" matches "%s", which this class inherits; it is not checked on it: write the pattern in the schema that declares the property.',
                            $name,
                        ), $pattern->place());
                    }
                }
            }
        }
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
        $extensions = new ExtensionReader($schema, $diagnostics);
        $skipped = $extensions->flag('x-validator-skip');
        $groups = $this->groups($extensions);
        $builder = new ConstraintBuilder($context->references(), $context->target(), $diagnostics, new RuntimeConstraints($context->packages(), $diagnostics), $context->keywordChecks(), $groups);
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
            $this->classConstraints($context, $builder),
        );
    }

    /**
     * The constraints the keywords of the class call for on the property: `propertyNames` and `patternProperties` on
     * $additionalProperties, and the schemas of the patterns its name matches on a declared one. They belong to the
     * class, so the property's schema neither skips nor groups them.
     *
     * @return list<AttributeModel>
     */
    private function classConstraints(PropertyContext $context, ConstraintBuilder $builder): array
    {
        $class = $this->classes[$context->owner()->name()->fqcn()] ?? null;
        if (!$class instanceof ClassKeywords) {
            return [];
        }

        $property = $context->property();
        $type = $property->type();
        if ($property->isAdditionalProperties()) {
            assert($type instanceof MapType);
            $patterns = $class->patterns();
            $constraints = array_merge(
                $builder->names($class->keywords(), $class->location()),
                $patterns !== [] && (new RuntimeConstraints($context->packages(), $context->diagnostics()))->available('patternProperties', $class->location())
                    ? $builder->patternProperties($patterns, $type->value())
                    : [],
            );
        } else {
            $constraints = [];
            foreach ($class->patterns() as $pattern) {
                if ($pattern->matches($property->wireName())) {
                    array_push($constraints, ...($builder->subschema('patternProperties', $pattern->schema(), $pattern->place(), $type, true) ?? []));
                }
            }
        }

        $groups = $class->groups();

        return array_map(static fn (ConstraintSpec $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(), $constraints);
    }

    /**
     * Every pair of `dependentRequired` is checked or warned about below, and `propertyNames` without
     * $additionalProperties is warned about, or has nothing left to check.
     */
    private function markChecked(ClassContext $context, Keywords $keywords, string $keyword): void
    {
        foreach ($keywords->schemas() as $place) {
            if ($place->hasKeyword($keyword)) {
                $context->keywordChecks()->checked($place->location(), $keyword);
            }
        }
    }

    /**
     * Without $additionalProperties the class keeps no undeclared property whose name could be checked; with
     * `additionalProperties: false` there is none to check.
     */
    private function warnAboutNamesNotKept(ClassContext $context, Keywords $keywords): void
    {
        $schema = $context->schema();
        if ($this->keepsUndeclared($context)) {
            return;
        }

        $this->markChecked($context, $keywords, 'propertyNames');
        if ($keywords->has('propertyNames') && $schema->additionalProperties() !== false) {
            $context->diagnostics()->warning('"propertyNames" is checked on the undeclared properties a class keeps in its own $additionalProperties, which this class does not declare; it is not checked.', $schema->location());
        }
    }

    private function keepsUndeclared(ClassContext $context): bool
    {
        foreach ($context->class()->properties() as $property) {
            if ($property->isAdditionalProperties()) {
                return true;
            }
        }

        return false;
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
