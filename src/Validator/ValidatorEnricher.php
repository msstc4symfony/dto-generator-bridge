<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
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
    private const OBJECT_KEYWORDS = [
        'patternProperties', 'propertyNames', 'unevaluatedProperties', 'minProperties', 'maxProperties',
        'dependentRequired', 'dependentSchemas', 'not', 'if', 'const',
    ];

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
        $scope = ClassScope::of($context);
        $schema = $scope->schema();
        $diagnostics = $scope->diagnostics();
        $version = $this->gate->version($scope->packages(), $scope->target(), $diagnostics, $schema->location());
        $extensions = new ExtensionReader($schema, $diagnostics);
        $keywords = $this->keywordsOf($scope);
        $properties = new ClassProperties($scope->class(), $scope->ancestors(), $keywords, $scope->references());
        // A contradiction of the schema itself, whatever the Validator writes.
        $this->warnAboutRejectedNames($scope, $keywords, $properties);
        if (!$version instanceof SymfonyVersion || $extensions->flag('x-validator-skip')) {
            return [];
        }

        if ($scope->class()->kind()->isInterface()) {
            $this->warnAboutInterfaceKeywords($scope);

            return [];
        }

        $groups = $this->groups($extensions);
        $patterns = $this->patterns($scope, $keywords);
        $this->classes[$scope->class()->name()->fqcn()] = new ClassKeywords($keywords, $schema->location(), $groups, $patterns);
        $this->warnAboutNamesNotKept($scope, $keywords);
        // The ancestor marks its own places: one whose class skips the validator stays unmarked, and the generator warns.
        foreach (['dependentRequired', 'if', 'then', 'else', 'not', 'dependentSchemas'] as $keyword) {
            foreach ($keywords->schemas() as $place) {
                if ($place->hasKeyword($keyword) && !$properties->ofAncestor($place)) {
                    $scope->keywordChecks()->checked($place->location(), $keyword);
                }
            }
        }

        // Symfony gives the class the constraints of its ancestors too: one they write already would report twice.
        $inherited = [];
        $ancestors = $scope->ancestors();
        foreach ($ancestors as $index => $ancestor) {
            array_push($inherited, ...$this->writtenBy($ancestor, array_slice($ancestors, $index + 1), $scope, $groups));
        }

        $attributes = [];
        foreach ($this->classSpecs($scope, $keywords, $properties, $groups, $patterns) as $constraint) {
            if (!in_array(serialize($constraint), $inherited, true)) {
                $attributes[] = $constraint->toAttribute();
            }
        }

        return $attributes;
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
        $builder = new ConstraintBuilder($context->references(), $context->target(), $diagnostics, new RuntimeConstraints($context->packages(), $diagnostics), $context->packages(), $this->settings->formatChecks(), $context->keywordChecks(), $groups);
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
            $constraints = array_merge($constraints, $this->otherDeclarations($context, $builder, $constraints));
        }

        return array_merge(
            array_map(static fn (ConstraintSpec $constraint): AttributeModel => $constraint->withGroups($groups)->toAttribute(), $constraints),
            $this->classConstraints($context, $builder),
        );
    }

    /**
     * An interface has no property to check, and Symfony would merge its class constraints into the variants under a
     * group of its own, so what its schema asserts of the object is left to the variants.
     */
    private function warnAboutInterfaceKeywords(ClassScope $context): void
    {
        $schema = $context->schema();
        $asserted = array_merge(
            $schema->required() === [] ? [] : ['required'],
            $schema->additionalProperties() === null ? [] : ['additionalProperties'],
            $schema->enum() === null ? [] : ['enum'],
            array_filter(self::OBJECT_KEYWORDS, static fn (string $keyword): bool => $schema->hasKeyword($keyword)),
        );
        foreach ($asserted as $keyword) {
            // Said here once, so the generator does not report the keyword again.
            $context->keywordChecks()->checked($schema->location(), $keyword);
        }

        if ($asserted !== []) {
            $context->diagnostics()->warning(
                sprintf('%s is an interface, which carries no constraints: the keywords "%s" of its schema are checked only where the variants\' schemas repeat them.', $context->class()->name()->fqcn(), implode('", "', $asserted)),
                $schema->location(),
            );
        }
    }

    /**
     * @param list<non-empty-string> $groups
     * @param list<PatternSchema> $patterns
     *
     * @return list<ConstraintSpec> the class constraints of the class, in its groups
     */
    private function classSpecs(ClassScope $context, Keywords $keywords, ClassProperties $properties, array $groups, array $patterns): array
    {
        $runtime = new RuntimeConstraints($context->packages(), $context->diagnostics());
        $builder = new ConstraintBuilder($context->references(), $context->target(), $context->diagnostics(), $runtime, $context->packages(), $this->settings->formatChecks(), $context->keywordChecks(), $groups);
        $constraints = array_merge(
            $this->requirements($context, $keywords, $runtime, $properties),
            $this->conditions($context, $keywords, $builder, $runtime, $groups, $properties),
            $this->inheritedMatches($context, $keywords, $patterns, $properties, $builder, $runtime),
            $this->narrowings($keywords, $properties, $builder, $runtime, $context),
        );

        return array_map(static fn (ConstraintSpec $constraint): ConstraintSpec => $constraint->withGroups($groups), $constraints);
    }

    /**
     * The class constraints an ancestor writes in every group of the class, each serialized as the class would write
     * it, whatever the order the generator enriches the classes in. The class passed the gate, so the ancestor does too.
     *
     * @param list<ClassModel> $above the ancestor's own ancestors, nearest first
     * @param list<non-empty-string> $groups the class's
     *
     * @return list<string>
     */
    private function writtenBy(ClassModel $ancestor, array $above, ClassScope $context, array $groups): array
    {
        $references = $context->references();
        $schema = $references->at($ancestor->source());
        if (!$schema instanceof Schema) {
            return [];
        }

        $quiet = $context->ancestor($ancestor, $schema, $above);
        $extensions = new ExtensionReader($schema, $quiet->diagnostics());
        if ($extensions->flag('x-validator-skip')) {
            return [];
        }

        // Without groups a constraint is in Default; one the ancestor checks in fewer groups leaves the others unchecked.
        $own = $this->groups($extensions);
        if (array_diff($groups === [] ? ['Default'] : $groups, $own === [] ? ['Default'] : $own) !== []) {
            return [];
        }

        $keywords = $this->keywordsOf($quiet);
        $properties = new ClassProperties($ancestor, $above, $keywords, $references);

        return array_map(static fn (ConstraintSpec $constraint): string => serialize($constraint), $this->classSpecs($quiet, $keywords, $properties, $groups, $this->patterns($quiet, $keywords)));
    }

    private function keywordsOf(ClassScope $context): Keywords
    {
        $schema = $context->schema();

        return new Keywords($schema, $context->references(), $this->variantStop($schema, $context->references()));
    }

    /**
     * The member of the class's own union that leads back to it is a variant, a subclass that checks its keywords
     * itself; the class reads nothing from it. Keywords reads a union only when it has one member besides null.
     *
     * @return list<string> its location, if any
     */
    private function variantStop(Schema $schema, SchemaReferences $references): array
    {
        $own = $schema->location()->toString();
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $member) {
            $reached = array_map(static fn (Schema $place): string => $place->location()->toString(), (new Keywords($member, $references))->schemas());
            if (in_array($own, $reached, true)) {
                return [$member->location()->toString()];
            }
        }

        return [];
    }

    /**
     * A property whose name `propertyNames` rejects can never be set. An ancestor's schema applies to the class too, but
     * the ancestor's class checks its own names against it.
     */
    private function warnAboutRejectedNames(ClassScope $context, Keywords $keywords, ClassProperties $properties): void
    {
        $references = $context->references();
        $places = [];
        foreach ($keywords->schemas() as $place) {
            $places[$place->location()->toString()] = $place;
        }

        // An adopted variant (one the base lists without a $ref back) reaches nothing of its ancestors' schemas.
        foreach ($context->ancestors() as $ancestor) {
            $schema = $references->at($ancestor->source());
            foreach ($schema instanceof Schema ? (new Keywords($schema, $references))->schemas() : [] as $place) {
                $places[$place->location()->toString()] = $place;
            }
        }

        foreach ($places as $place) {
            if (!$place->hasKeyword('propertyNames')) {
                continue;
            }

            $schema = RawSchema::dereferenced($place->keyword('propertyNames'), $place->location()->child('propertyNames'), $references);
            foreach ($properties->names(!$properties->ofAncestor($place)) as $name) {
                if (NameCheck::rejects($schema, $name)) {
                    $context->diagnostics()->warning(sprintf(
                        '"propertyNames" rejects "%s", the name of a declared property, which can never be set.',
                        $name,
                    ), $place->location());
                }
            }
        }
    }

    /**
     * @return list<ConstraintSpec> DependentRequired with the pairs the class checks
     */
    private function requirements(ClassScope $context, Keywords $keywords, RuntimeConstraints $runtime, ClassProperties $properties): array
    {
        $at = $context->schema()->location();
        if (!$keywords->has('dependentRequired') || !$runtime->available('dependentRequired', $at)) {
            return [];
        }

        $requirements = (new DependentRequirements($properties, $context->diagnostics(), $at))->of($keywords);
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
     * @return list<ConstraintSpec> IfThenElse for each condition and dependency the class checks, Not for each negation
     */
    private function conditions(ClassScope $context, Keywords $keywords, ConstraintBuilder $builder, RuntimeConstraints $runtime, array $groups, ClassProperties $properties): array
    {
        $at = $context->schema()->location();
        $class = new ClassConditions($properties, $builder, $context->diagnostics(), $groups);

        return array_merge(
            ClaimedConstraints::conditionPlaces($keywords) !== [] && $runtime->available('if', $at) ? $class->of($keywords) : [],
            ClaimedConstraints::forbids($keywords->values('not')) && $runtime->available('not', $at) ? $class->negations($keywords) : [],
            $keywords->has('dependentSchemas') && $runtime->available('dependentSchemas', $at) ? $class->dependencies($keywords) : [],
            ClassConditions::alternated($keywords, 'oneOf') !== [] && $runtime->available('oneOf', $at) ? $class->alternatives($keywords, 'oneOf') : [],
            ClassConditions::alternated($keywords, 'anyOf') !== [] && $runtime->available('anyOf', $at) ? $class->alternatives($keywords, 'anyOf') : [],
        );
    }

    /**
     * The patterns of the class, for its declared properties and its $additionalProperties, each place marked: every
     * pattern is checked or warned about, and undeclared properties the class does not keep are warned about.
     *
     * @return list<PatternSchema>
     */
    private function patterns(ClassScope $context, Keywords $keywords): array
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

        return PatternSchema::allOf($keywords, $diagnostics);
    }

    /**
     * A property of the parent the class's own schema declares again stays the parent's (the generator lets no child
     * narrow its type): the class checks, with Property, the constraints of its declaration the parent's lack, and with
     * Present that the property is set when its schema requires what the parent leaves optional.
     *
     * @return list<ConstraintSpec>
     */
    private function narrowings(Keywords $keywords, ClassProperties $properties, ConstraintBuilder $builder, RuntimeConstraints $runtime, ClassScope $context): array
    {
        $inherited = array_diff($properties->names(true), $properties->names(false));
        $parents = [];
        $own = [];
        foreach ($keywords->schemas() as $place) {
            foreach (array_intersect($place->propertyNames(), $inherited) as $name) {
                if ($properties->ofAncestor($place)) {
                    $parents[$name][] = $place->requireProperty($name);
                } else {
                    $own[$name][] = $place->requireProperty($name);
                }
            }
        }

        $added = [];
        foreach ($own as $name => $schemas) {
            $property = $properties->find((string) $name);
            assert($property instanceof PropertyModel);
            // A declaration of the parent in groups of its own checks in others than the class's.
            $checked = [];
            foreach ($parents[$name] ?? [] as $schema) {
                if (!$this->setsItsOwnChecks($schema)) {
                    array_push($checked, ...array_map(static fn (ConstraintSpec $spec): string => serialize($spec), $builder->build($schema, $property->type())));
                }
            }

            foreach ($schemas as $schema) {
                foreach ($builder->build($schema, $property->type()) as $spec) {
                    if (in_array(serialize($spec), $checked, true)) {
                        continue;
                    }

                    $checked[] = serialize($spec);
                    // Valid may not go inside Property; the parent's property, of the same type, traverses the value,
                    // unless the parent declares it in groups of its own or skips it.
                    if (!$spec->is('Valid')) {
                        $added[$property->name()][] = $spec;
                    } elseif (($parents[$name] ?? []) !== []) {
                        $this->warnAboutAnUncheckedObject($parents[$name], $property, $builder, $schema, $context);
                    }
                }
            }
        }

        // The parent leaves a property optional that the class's schema requires: the class checks it is set, unless a
        // declaration admits null, which then stands for a value.
        $parentRequired = [];
        foreach ($keywords->schemas() as $place) {
            array_push($parentRequired, ...$properties->ofAncestor($place) ? $place->required() : []);
        }

        $required = [];
        foreach ($keywords->schemas() as $place) {
            foreach ($properties->ofAncestor($place) ? [] : array_diff(array_intersect($place->required(), $inherited), $parentRequired) as $name) {
                $property = $properties->find($name);
                assert($property instanceof PropertyModel);
                if (!$this->admitsNull(array_merge($parents[$name] ?? [], $own[$name] ?? []), $context->references()) && !in_array($property->name(), $required, true)) {
                    $required[] = $property->name();
                }
            }
        }

        if (($added === [] && $required === []) || !$runtime->available('properties', $context->schema()->location())) {
            return [];
        }

        $constraints = [];
        foreach ($added as $name => $specs) {
            $inner = $builder->asNew('properties', $specs, $context->schema()->location());
            if ($inner !== null) {
                $constraints[] = ConstraintSpec::runtime('Property', [
                    AttributeArgument::named('name', ArgumentValue::literal($name)),
                    AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner)),
                ]);
            }
        }

        if ($required !== []) {
            $constraints[] = ConstraintSpec::runtime('Present', [AttributeArgument::named('properties', ArgumentValue::listOf(...array_map(static fn (string $name): ArgumentValue => ArgumentValue::literal($name), $required)))]);
        }

        return $constraints;
    }

    /**
     * Valid may not go inside Property: the object the class declares is checked only by the parent's property.
     *
     * @param list<Schema> $parents the parent's declarations
     */
    private function warnAboutAnUncheckedObject(array $parents, PropertyModel $property, ConstraintBuilder $builder, Schema $schema, ClassScope $context): void
    {
        $context->diagnostics()->warning(
            $this->traverses($parents, $property, $builder)
                ? sprintf('The parent skips "%s" or checks it in groups of its own, so the object it holds may go unvalidated in the groups of this class: Symfony allows no Valid inside Property.', $property->wireName())
                : sprintf('"%s" keeps the type %s of the parent, which holds no object to traverse, so the constraints of the object this class declares are not checked.', $property->wireName(), $property->type()->describe()),
            $schema->location(),
        );
    }

    /**
     * Whether the parent's declarations would traverse the value, had they no groups of their own or skip.
     *
     * @param list<Schema> $declarations
     */
    private function traverses(array $declarations, PropertyModel $property, ConstraintBuilder $builder): bool
    {
        foreach ($declarations as $declaration) {
            foreach ($builder->build($declaration, $property->type()) as $spec) {
                if ($spec->is('Valid')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A declaration that skips the validator, or has groups of its own, checks nothing in the groups of another. A skip
     * that is not true skips nothing; the property's own reading warns about it.
     */
    private function setsItsOwnChecks(Schema $declaration): bool
    {
        $extensions = $declaration->extensions();

        // A null list of groups stands for the default ones, as ExtensionReader::groups() reads it.
        return ($extensions->has('x-validator-groups') && $extensions->get('x-validator-groups') !== null)
            || ($extensions->has('x-validator-skip') && $extensions->get('x-validator-skip') === true);
    }

    /**
     * Whether a declaration lets null through: no type, or a type that lists it.
     *
     * @param list<Schema> $declarations
     */
    private function admitsNull(array $declarations, SchemaReferences $references): bool
    {
        foreach ($declarations as $declaration) {
            $resolved = $references->resolve($declaration);
            if ($resolved->types() === [] || $resolved->isNullable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The constraints of a pattern go on the properties of the class; an inherited property belongs to the parent, which
     * checks only the patterns of the schemas that declare it, so the class checks the others on it with Property. A
     * declared property the class does not have (`x-php-skip`) gives a warning.
     *
     * @param list<PatternSchema> $patterns
     *
     * @return list<ConstraintSpec>
     */
    private function inheritedMatches(ClassScope $context, Keywords $keywords, array $patterns, ClassProperties $properties, ConstraintBuilder $builder, RuntimeConstraints $runtime): array
    {
        $targets = $this->targets($keywords, $context->references(), $context->class()->source()->toString());
        $matches = [];
        foreach ($keywords->schemas() as $place) {
            // A target reaches no declaration of the class's own properties; one it inherits is the target's.
            foreach ($place->propertyNames() as $name) {
                foreach ($patterns as $pattern) {
                    if ($pattern->matches($name) && !$this->checkedByOwner($targets, $pattern->place()->toString(), $place->location()->toString())) {
                        $matches[$name][$pattern->written()->toString()] = $pattern;
                    }
                }
            }
        }

        if ($matches === [] || !$runtime->available('patternProperties', $context->schema()->location())) {
            return [];
        }

        $constraints = [];
        foreach ($matches as $name => $byPlace) {
            foreach ($byPlace as $pattern) {
                array_push($constraints, ...$this->inheritedMatch($context, $properties->find((string) $name), (string) $name, $pattern, $builder));
            }
        }

        return $constraints;
    }

    /**
     * @return list<ConstraintSpec>
     */
    private function inheritedMatch(ClassScope $context, ?PropertyModel $property, string $name, PatternSchema $pattern, ConstraintBuilder $builder): array
    {
        if (!$property instanceof PropertyModel) {
            $context->diagnostics()->warning(sprintf(
                '"patternProperties" matches "%s", which the schema of a parent declares and this class does not have; it is not checked on it.',
                $name,
            ), $pattern->place());

            return [];
        }

        $specs = $builder->subschema('patternProperties', $pattern->schema(), $pattern->place(), $property->type(), true, $pattern->written());
        $inner = $specs === null ? null : $builder->asNew('patternProperties', $specs, $pattern->place());
        if ($inner === null || $inner === []) {
            return [];
        }

        return [ConstraintSpec::runtime('Property', [
            AttributeArgument::named('name', ArgumentValue::literal($property->name())),
            AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner)),
        ])];
    }

    /**
     * The schemas the `$ref`s of the `allOf` branches lead to, at any depth, each with the locations of its places.
     *
     * @param string $own where the class's own schema is: a target's union may lead back to it, a variant's places stay
     *                    its own
     *
     * @return array<string, list<string>> by the target's location
     */
    private function targets(Keywords $keywords, SchemaReferences $references, string $own): array
    {
        $targets = [];
        $queue = [$keywords];
        while (($current = array_pop($queue)) instanceof Keywords) {
            foreach ($current->schemas() as $place) {
                foreach ($place->allOf() as $branch) {
                    $target = $references->resolve($branch);
                    $location = $target->location()->toString();
                    if ($branch->ref() !== null && !isset($targets[$location])) {
                        $reached = new Keywords($target, $references, [$own]);
                        $targets[$location] = array_map(static fn (Schema $schema): string => $schema->location()->toString(), $reached->schemas());
                        $queue[] = $reached;
                    }
                }
            }
        }

        return $targets;
    }

    /**
     * The class an inherited property belongs to is generated from the narrowest target that reaches its declaration
     * (each wider one reaches it through that one); it checks the property against the patterns of its own places
     * only, not against those beside its `$ref` or in a schema between it and this class. A declaration no target
     * reaches is not inherited: the class's own schema leaves it out (`x-php-skip`).
     *
     * @param array<string, list<string>> $targets
     */
    private function checkedByOwner(array $targets, string $pattern, string $declaration): bool
    {
        foreach ($targets as $places) {
            if (in_array($declaration, $places, true) && !in_array($pattern, $places, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The constraints of the property's other declarations in the class's schema (members of an allOf): the generator
     * takes one of them for the property, but a value must satisfy them all.
     *
     * @param list<ConstraintSpec> $own the constraints of the declaration the property has
     *
     * @return list<ConstraintSpec>
     */
    private function otherDeclarations(PropertyContext $context, ConstraintBuilder $builder, array $own): array
    {
        $class = $this->classes[$context->owner()->name()->fqcn()] ?? null;
        $wireName = $context->property()->wireName();
        $seen = array_map(static fn (ConstraintSpec $spec): string => serialize($spec), $own);
        $added = [];
        foreach ($class instanceof ClassKeywords ? $class->keywords()->schemas() : [] as $place) {
            $declaration = in_array($wireName, $place->propertyNames(), true) ? $place->requireProperty($wireName) : null;
            // One that skips the validator, or has groups of its own, checks nothing in the property's groups.
            if (!$declaration instanceof Schema || $declaration->location()->equals($context->schema()->location())
                || $this->setsItsOwnChecks($declaration)) {
                continue;
            }

            foreach ($builder->build($declaration, $context->property()->type()) as $spec) {
                if (!in_array(serialize($spec), $seen, true)) {
                    $seen[] = serialize($spec);
                    $added[] = $spec;
                }
            }
        }

        return $added;
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
                    array_push($constraints, ...($builder->subschema('patternProperties', $pattern->schema(), $pattern->place(), $type, true, $pattern->written()) ?? []));
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
    private function markChecked(ClassScope $context, Keywords $keywords, string $keyword): void
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
    private function warnAboutNamesNotKept(ClassScope $context, Keywords $keywords): void
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

    private function keepsUndeclared(ClassScope $context): bool
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
