<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The constraints of a value for the keywords the bridge claims from the generator, mostly the runtime's. Every place
 * of such a keyword is marked: checked, or warned about.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class ClaimedConstraints
{
    /**
     * The keywords the generator calls unsupported and the bridge checks, with the runtime's constraints or Symfony's;
     * the bridge claims them and warns where it cannot check one.
     */
    public const KEYWORDS = [
        'dependentRequired', 'propertyNames', 'contains', 'minContains', 'maxContains', 'patternProperties', 'if', 'then', 'else', 'not',
        'dependentSchemas', 'prefixItems', 'unevaluatedItems',
    ];

    /** The branches of a condition, in the order IfThenElse takes them. */
    private const BRANCHES = ['if', 'then', 'else'];

    private ConstraintBuilder $builder;

    private SchemaReferences $references;

    private Diagnostics $diagnostics;

    private RuntimeConstraints $runtime;

    private KeywordChecks $checks;

    private TupleConstraints $tuples;

    /**
     * @param ConstraintBuilder $builder which turns the subschemas of the keywords into constraints
     * @param list<non-empty-string> $groups the groups of the value
     */
    public function __construct(
        ConstraintBuilder $builder,
        SchemaReferences $references,
        Diagnostics $diagnostics,
        RuntimeConstraints $runtime,
        KeywordChecks $checks,
        array $groups
    ) {
        $this->builder = $builder;
        $this->references = $references;
        $this->diagnostics = $diagnostics;
        $this->runtime = $runtime;
        $this->checks = $checks;
        $this->tuples = new TupleConstraints($builder, $references, $diagnostics, $groups);
    }

    /**
     * Whether any of the values of `not` forbids something: `not: false` does not.
     *
     * @param list<JsonValue> $values
     */
    public static function forbids(array $values): bool
    {
        return array_filter($values, static fn ($value): bool => $value !== false) !== [];
    }

    /**
     * The places with a condition that has an effect: `if` with `then` or `else` beside it.
     *
     * @return list<Schema>
     */
    public static function conditionPlaces(Keywords $keywords): array
    {
        $places = [];
        foreach ($keywords->schemas() as $place) {
            if ($place->hasKeyword('if') && ($place->hasKeyword('then') || $place->hasKeyword('else'))) {
                $places[] = $place;
            }
        }

        return $places;
    }

    /**
     * The runtime's Contains for each `contains`, bounded by the `minContains` and `maxContains` of its own schema.
     *
     * @return list<ConstraintSpec>
     */
    public function contains(Keywords $keywords, KeywordReader $reader, TypeModel $value, SchemaLocation $at): array
    {
        $places = array_filter($keywords->schemas(), static fn (Schema $place): bool => $place->hasKeyword('contains'));
        $this->markClaimed($keywords, 'minContains');
        $this->markClaimed($keywords, 'maxContains');
        $this->markClaimed($keywords, 'contains');
        foreach ($keywords->schemas() as $place) {
            foreach (['minContains', 'maxContains'] as $bound) {
                if ($place->hasKeyword($bound) && !$place->hasKeyword('contains')) {
                    $this->diagnostics->warning(sprintf('"%s" has no effect without "contains" in the same schema.', $bound), $place->location());
                }
            }
        }

        if ($places === []) {
            return [];
        }

        if (!$value instanceof ListType) {
            $this->diagnostics->warning('"contains" is checked on arrays only; it is not checked here.', $at);

            return [];
        }

        $checked = [];
        foreach ($places as $place) {
            $min = $this->bound($reader, $place, 'minContains') ?? 1;
            $max = $this->bound($reader, $place, 'maxContains');
            if (($min !== 0 || $max !== null) && ($max === null || $reader->consistent('minContains', $min, 'maxContains', $max))) {
                $checked[] = [$place, $min, $max];
            }
        }

        if ($checked === [] || !$this->runtime->available('contains', $at)) {
            return [];
        }

        $constraints = [];
        foreach ($checked as [$place, $min, $max]) {
            $inner = $this->newSubschema('contains', $place, $value->item());
            if ($inner !== null) {
                $constraints[] = ConstraintSpec::runtime('Contains', array_merge(
                    $inner === [] ? [] : [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))],
                    $min === 1 ? [] : [AttributeArgument::named('min', ArgumentValue::literal($min))],
                    $max === null ? [] : [AttributeArgument::named('max', ArgumentValue::literal($max))],
                ));
            }
        }

        return $constraints;
    }

    /**
     * A map gets Keys here; a class gets them on its $additionalProperties, from ValidatorEnricher, which reads the
     * keywords of the class's own schema: those beside a `$ref` to the class stay unmarked, and the generator warns.
     *
     * @return list<ConstraintSpec>
     */
    public function keys(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if ($keywords->describesObject()) {
            return [];
        }

        if ($this->markClaimed($keywords, 'dependentRequired') !== []) {
            $this->diagnostics->warning('"dependentRequired" is checked on classes only; it is not checked here.', $at);
        }

        if (!$value instanceof MapType) {
            if ($this->markClaimed($keywords, 'propertyNames') !== []) {
                $this->diagnostics->warning('"propertyNames" is checked on maps and on the undeclared properties of a class only; it is not checked here.', $at);
            }

            return [];
        }

        return $this->names($keywords, $at);
    }

    /**
     * The runtime's Keys for the names of the undeclared properties of a class or of a map's keys.
     *
     * @return list<ConstraintSpec>
     */
    public function names(Keywords $keywords, SchemaLocation $at): array
    {
        $constraints = [];
        if ($this->markClaimed($keywords, 'propertyNames') === [] || !$this->runtime->available('propertyNames', $at)) {
            return [];
        }

        $name = ScalarType::string();
        foreach (array_filter($keywords->schemas(), static fn (Schema $place): bool => $place->hasKeyword('propertyNames')) as $place) {
            $inner = $this->newSubschema('propertyNames', $place, $name);
            if ($inner !== null && $inner !== []) {
                $constraints[] = ConstraintSpec::runtime('Keys', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))]);
            }
        }

        return $constraints;
    }

    /**
     * A map gets PatternProperty here; a class checks its declared properties and its $additionalProperties from
     * ValidatorEnricher, which reads the keywords of the class's own schema.
     *
     * @return list<ConstraintSpec>
     */
    public function patterns(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if ($keywords->describesObject() || $this->markClaimed($keywords, 'patternProperties') === []) {
            return [];
        }

        if (!$value instanceof MapType) {
            $this->diagnostics->warning('"patternProperties" is checked on maps and on classes only; it is not checked here.', $at);

            return [];
        }

        if ($keywords->resolved()->additionalProperties() instanceof Schema) {
            $this->diagnostics->warning(PatternSchema::ALSO_ADDITIONAL, $at);
        }

        $patterns = PatternSchema::allOf($keywords, $this->diagnostics);
        if ($patterns === [] || !$this->runtime->available('patternProperties', $at)) {
            return [];
        }

        return $this->patternProperties($patterns, $value->value());
    }

    /**
     * The runtime's PatternProperty for each pattern, on a map or the undeclared properties of a class.
     *
     * @param list<PatternSchema> $patterns
     * @param TypeModel $value the type of the values
     *
     * @return list<ConstraintSpec>
     */
    public function patternProperties(array $patterns, TypeModel $value): array
    {
        $constraints = [];
        foreach ($patterns as $pattern) {
            $specs = $this->builder->subschema('patternProperties', $pattern->schema(), $pattern->place(), $value, false, $pattern->written());
            $inner = $specs === null ? null : $this->builder->asNew('patternProperties', $specs, $pattern->place());
            // A schema of annotations only accepts every value.
            if ($inner !== null && $inner !== []) {
                $constraints[] = ConstraintSpec::runtime('PatternProperty', [
                    AttributeArgument::named('pattern', ArgumentValue::literal($pattern->pcre())),
                    AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner)),
                ]);
            }
        }

        return $constraints;
    }

    /**
     * The runtime's IfThenElse for each condition of a value; a class checks its own from ValidatorEnricher. It does
     * not check null, which therefore needs no NotNull.
     *
     * @return list<ConstraintSpec>
     */
    public function conditions(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if ($keywords->describesObject()) {
            return [];
        }

        foreach (self::BRANCHES as $branch) {
            $this->markClaimed($keywords, $branch);
        }

        $places = self::conditionPlaces($keywords);
        if ($places === [] || !$this->runtime->available('if', $at)) {
            return [];
        }

        $constraints = [];
        foreach ($places as $place) {
            $condition = $this->valueCondition($place, $value);
            if ($condition instanceof ConstraintSpec) {
                $constraints[] = $condition;
            }
        }

        return $constraints;
    }

    /**
     * The runtime's Not for each `not` of a value; a class checks its own from ValidatorEnricher. A schema that checks
     * nothing leaves no value allowed but null, which stands for a missing one.
     *
     * @return list<ConstraintSpec>
     */
    public function negations(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if ($keywords->describesObject() || !self::forbids($this->markClaimed($keywords, 'not')) || !$this->runtime->available('not', $at)) {
            return [];
        }

        $constraints = [];
        foreach ($keywords->schemas() as $place) {
            if (!$place->hasKeyword('not') || $place->keyword('not') === false) {
                continue;
            }

            $specs = $this->builder->subschema('not', $place->keyword('not'), $place->location(), $value, true, $place->location()->child('not'));
            if ($specs !== null && $specs !== [] && $specs[0]->is('IsNull')) {
                $this->diagnostics->warning('"not" of null is not checked: null stands for a missing value.', $place->location());

                continue;
            }

            $inner = $specs === null ? null : $this->builder->asNew('not', $specs, $place->location());
            if ($inner !== null) {
                $constraints[] = ConstraintSpec::runtime('Not', $inner === [] ? [] : [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))]);
            }
        }

        return $constraints;
    }

    /**
     * `dependentSchemas` names properties, which only a class checks, from ValidatorEnricher.
     */
    public function warnAboutDependencies(Keywords $keywords, SchemaLocation $at): void
    {
        if (!$keywords->describesObject() && $this->markClaimed($keywords, 'dependentSchemas') !== []) {
            $this->diagnostics->warning('"dependentSchemas" is checked on classes only; it is not checked here.', $at);
        }
    }

    /**
     * The constraints of `prefixItems` and `unevaluatedItems`, each place marked.
     *
     * @return list<ConstraintSpec>
     */
    public function tuples(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        $this->markClaimed($keywords, 'prefixItems');
        $this->markClaimed($keywords, 'unevaluatedItems');

        return $this->tuples->of($keywords, $value, $at);
    }

    /**
     * A member of a union of several values is no value of its own here, so its keywords go unchecked, with a warning
     * at the value's schema (the property, or its items): a shared component may be checked through another value,
     * which marks it. A place that is a class checks its keywords itself.
     */
    public function warnAboutUnreachedMembers(Keywords $keywords, SchemaLocation $at): void
    {
        $members = $keywords->unreachedMembers();
        $seen = [];
        while ($members !== []) {
            $member = array_shift($members);
            $places = new Keywords($member, $this->references);
            $union = $places->unreachedMembers();
            if ($places->resolved()->propertyNames() !== [] || ($union === [] && $places->describesObject())) {
                // A class of its own, whose enrichment checks its keywords (and ignores a union beside its properties).
                continue;
            }

            foreach ($places->schemas() as $place) {
                if ($place->propertyNames() === []) {
                    $this->warnAboutMemberPlace($place, $at);
                }
            }

            // Members may loop back through `$ref`; each nested one is queued once.
            foreach ($union as $nested) {
                $location = $nested->location()->toString();
                if (!in_array($location, $seen, true)) {
                    $seen[] = $location;
                    $members[] = $nested;
                }
            }
        }
    }

    private function warnAboutMemberPlace(Schema $place, SchemaLocation $at): void
    {
        foreach (self::KEYWORDS as $keyword) {
            if ($place->hasKeyword($keyword)) {
                $this->checks->checked($place->location(), $keyword);
                $this->diagnostics->warning(sprintf('"%s" in a member of "oneOf" or "anyOf" is not checked.', $keyword), $at);
            }
        }
    }

    /**
     * The first branch that is not checked leaves the others unread, so it gets the only warning.
     */
    private function valueCondition(Schema $place, TypeModel $value): ?ConstraintSpec
    {
        $if = $this->branch('if', $place, $value);
        if ($if === null) {
            return null;
        }

        $then = $this->branch('then', $place, $value);
        if ($then === null) {
            return null;
        }

        $else = $this->branch('else', $place, $value);

        return $else === null ? null : $this->builder->condition(['if' => $if, 'then' => $then, 'else' => $else], $place->location());
    }

    /**
     * @param 'if'|'then'|'else' $branch
     *
     * @return list<ConstraintSpec>|null none for a missing branch, null for one that is not checked
     */
    private function branch(string $branch, Schema $place, TypeModel $value): ?array
    {
        return $place->hasKeyword($branch) ? $this->builder->subschema($branch, $place->keyword($branch), $place->location(), $value, true, $place->location()->child($branch)) : [];
    }

    /**
     * The values of a keyword the bridge claims, each place marked as handled: checked, or warned about by the caller.
     *
     * @return list<JsonValue>
     */
    private function markClaimed(Keywords $keywords, string $name): array
    {
        foreach ($keywords->schemas() as $place) {
            if ($place->hasKeyword($name)) {
                $this->checks->checked($place->location(), $name);
            }
        }

        return $keywords->values($name);
    }

    private function bound(KeywordReader $reader, Schema $place, string $name): ?int
    {
        return $place->hasKeyword($name) ? $reader->count($name, $place->keyword($name)) : null;
    }

    /**
     * The constraints of a subschema as `new` arguments; null when it is not checked.
     *
     * @param non-empty-string $keyword
     * @param Schema $place the schema that holds the keyword, where the warnings go
     *
     * @return list<ArgumentValue>|null
     */
    private function newSubschema(string $keyword, Schema $place, TypeModel $type): ?array
    {
        $constraints = $this->builder->subschema($keyword, $place->keyword($keyword), $place->location(), $type, false, $place->location()->child($keyword));

        return $constraints === null ? null : $this->builder->asNew($keyword, $constraints, $place->location());
    }
}
