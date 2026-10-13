<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The constraints a value's schema and type call for (bridge spec §5.1), in the order of that table: those of the
 * keywords of its kind (KindConstraints), of the keywords the bridge claims (ClaimedConstraints), of its enum and
 * constant (ValueConstraints), and of the elements it holds. Subschemas are built here too, by a builder of their own.
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class ConstraintBuilder
{
    private SchemaReferences $references;

    private TargetProfile $target;

    private Diagnostics $diagnostics;

    private RuntimeConstraints $runtime;

    private KindConstraints $kinds;

    private ValueConstraints $values;

    private ClaimedConstraints $claimed;

    /** @var list<non-empty-string> */
    private array $groups;

    /**
     * @param KeywordChecks $checks where the keywords the bridge claims are marked as checked or warned about
     * @param list<non-empty-string> $groups the groups of the value, which nested composite constraints need too
     */
    public function __construct(
        SchemaReferences $references,
        TargetProfile $target,
        Diagnostics $diagnostics,
        RuntimeConstraints $runtime,
        KeywordChecks $checks,
        array $groups
    ) {
        $this->references = $references;
        $this->target = $target;
        $this->diagnostics = $diagnostics;
        $this->runtime = $runtime;
        $this->kinds = new KindConstraints($diagnostics);
        $this->values = new ValueConstraints($target, $diagnostics);
        $this->claimed = new ClaimedConstraints($this, $references, $diagnostics, $runtime, $checks);
        $this->groups = $groups;
    }

    /**
     * @return list<ConstraintSpec>
     */
    public function build(Schema $schema, TypeModel $type): array
    {
        $keywords = new Keywords($schema, $this->references);
        $value = $type instanceof NullableType ? $type->inner() : $type;
        $at = $schema->location();
        $reader = new KeywordReader($keywords, $this->diagnostics, $at);
        // As in the generator, which stays quiet about what a schema whose type x-php-type replaces says.
        $replaced = $keywords->resolved()->extensions()->has('x-php-type');
        $this->kinds->warnAboutFormatsOfOneKind($keywords, $value, $at);

        if (!$replaced) {
            $this->claimed->warnAboutUnreachedMembers($keywords, $at);
            $this->claimed->warnAboutDependencies($keywords, $at);
        }

        return array_merge(
            $this->kinds->of($keywords, $reader, $value, $at),
            $replaced ? [] : $this->claimed->contains($keywords, $reader, $value, $at),
            $replaced ? [] : $this->claimed->tuples($keywords, $value, $at),
            $replaced ? [] : $this->claimed->keys($keywords, $value, $at),
            $replaced ? [] : $this->claimed->patterns($keywords, $value, $at),
            $this->values->choices($keywords, $value, $at),
            $this->values->constants($keywords, $value, $at),
            $this->kinds->formats($keywords, $value),
            $this->nested($keywords, $value, $at),
            $replaced ? [] : $this->claimed->conditions($keywords, $value, $at),
            $replaced ? [] : $this->claimed->negations($keywords, $value, $at),
        );
    }

    /**
     * The groups of the value, which the composite constraints inside the value's own need too.
     *
     * @return list<non-empty-string>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * The runtime's Keys for the names of the undeclared properties of a class.
     *
     * @return list<ConstraintSpec>
     */
    public function names(Keywords $keywords, SchemaLocation $at): array
    {
        return $this->claimed->names($keywords, $at);
    }

    /**
     * The runtime's PatternProperty for each pattern of a class, on its $additionalProperties.
     *
     * @param list<PatternSchema> $patterns
     * @param TypeModel $value the type of the values
     *
     * @return list<ConstraintSpec>
     */
    public function patternProperties(array $patterns, TypeModel $value): array
    {
        return $this->claimed->patternProperties($patterns, $value);
    }

    /**
     * The constraints of each element of a list or map, from the schema of the elements.
     *
     * @return list<ConstraintSpec>
     */
    public function elements(Schema $itemSchema, TypeModel $item, SchemaLocation $at): array
    {
        $valid = [];
        $inner = [];
        foreach ($this->build($itemSchema, $item) as $constraint) {
            if ($constraint->is('Valid')) {
                $valid = [$constraint];
            } else {
                $inner[] = $constraint;
            }
        }

        return array_merge($valid, $this->each($inner, $at));
    }

    /**
     * All of constraints that each element of a list or map passes; none when the target's attributes cannot hold them.
     *
     * @param list<ConstraintSpec> $constraints
     *
     * @return list<ConstraintSpec>
     */
    public function each(array $constraints, SchemaLocation $at): array
    {
        // Symfony fixes the groups of a nested composite when it is built; the outer All does not pass its own on.
        $inner = array_map(fn (ConstraintSpec $constraint): ArgumentValue => ($constraint->isComposite() ? $constraint->withGroups($this->groups) : $constraint)->toNewInstance(), $constraints);
        if ($inner === [] || !$this->allowsNew($at, 'The constraints of the elements go inside All as "new", which PHP %s does not allow in attributes; they are not checked.')) {
            return [];
        }

        return [new ConstraintSpec('All', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))])];
    }

    /**
     * The constraints of a subschema for one value; null when it is not checked, with a warning.
     *
     * @param non-empty-string $keyword the keyword that holds the subschema, as warnings name it
     * @param JsonValue $value
     * @param SchemaLocation $at the schema that holds the keyword, where the warnings go
     * @param bool $nullIsAbsent whether null stands for a missing value, which the subschema does not describe
     *
     * @return list<ConstraintSpec>|null
     */
    public function subschema(string $keyword, $value, SchemaLocation $at, TypeModel $type, bool $nullIsAbsent): ?array
    {
        $parsed = Subschema::read($keyword, $value, $this->withoutEnums($type), $at, $this->diagnostics);
        if (!$parsed instanceof Subschema) {
            return null;
        }

        // Diagnostics drops a repeated message, so the subschema gets its own to tell whether any keyword went unchecked.
        $own = new Diagnostics();
        $builder = new self($this->references, $this->target, $own, $this->runtime, new KeywordChecks(), []);
        $nullable = !$nullIsAbsent && ($type instanceof NullableType || $type instanceof MixedType);
        $typeConstraint = $parsed->typeConstraint();
        $constraints = array_merge(
            $parsed->admitsOnlyNull() ? [new ConstraintSpec('IsNull')] : [],
            $nullable && $parsed->excludesNull() ? [new ConstraintSpec('NotNull')] : [],
            $typeConstraint instanceof ConstraintSpec ? [$typeConstraint] : [],
            $builder->build($parsed->schema(), $parsed->type()),
        );
        $this->diagnostics->merge($own);
        if ($own->all() !== []) {
            // A keyword of the schema went unchecked, so fewer values would match, or more.
            $this->diagnostics->warning(sprintf('"%s" is not checked, as part of its schema cannot be.', $keyword), $at);

            return null;
        }

        return $constraints;
    }

    /**
     * Constraints as `new` arguments of a composite constraint; null when the target's attributes cannot hold them.
     *
     * @param non-empty-string $keyword the keyword they check, as the warning names it
     * @param list<ConstraintSpec> $constraints
     *
     * @return list<ArgumentValue>|null
     */
    public function asNew(string $keyword, array $constraints, SchemaLocation $at): ?array
    {
        $inner = array_map(static fn (ConstraintSpec $constraint): ArgumentValue => $constraint->toNewInstance(), $constraints);
        if ($inner !== [] && !$this->allowsNew($at, sprintf('"%s" goes into the attribute as "new", which PHP %%s does not allow; it is not checked.', $keyword))) {
            return null;
        }

        return $inner;
    }

    /**
     * IfThenElse of the branches' constraints; null when neither `then` nor `else` checks anything, or the target's
     * attributes cannot hold them.
     *
     * @param array{if: list<ConstraintSpec>, then: list<ConstraintSpec>, else: list<ConstraintSpec>} $branches
     * @param non-empty-string|null $keyword the keyword the warnings name; each branch's own when null
     */
    public function condition(array $branches, SchemaLocation $at, ?string $keyword = null): ?ConstraintSpec
    {
        if ($branches['then'] === [] && $branches['else'] === []) {
            return null;
        }

        $arguments = [];
        foreach ($branches as $branch => $specs) {
            $inner = $this->asNew($keyword ?? $branch, $specs, $at);
            if ($inner === null) {
                return null;
            }

            if ($inner !== []) {
                $arguments[] = AttributeArgument::named($branch, ArgumentValue::listOf(...$inner));
            }
        }

        return ConstraintSpec::runtime('IfThenElse', $arguments);
    }

    /**
     * Valid traverses arrays itself and may not go inside All, so it stays on the property at any depth.
     *
     * @return list<ConstraintSpec>
     */
    private function nested(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if ($keywords->describesObject()) {
            return [new ConstraintSpec('Valid')];
        }

        $resolved = $keywords->resolved();
        if ($value instanceof ListType) {
            [$item, $itemSchema] = [$value->item(), $resolved->items()];
        } elseif ($value instanceof MapType) {
            [$item, $itemSchema] = [$value->value(), $resolved->additionalProperties()];
        } else {
            return [];
        }

        return $itemSchema instanceof Schema ? $this->elements($itemSchema, $item, $at) : [];
    }

    /**
     * A target without PHP enums holds an enum's value as its plain string or int, which the keywords of strings and
     * numbers check too. Subschema reads the value apart from null, which subschema() decides on from the given type.
     */
    private function withoutEnums(TypeModel $type): TypeModel
    {
        $value = $type instanceof NullableType ? $type->inner() : $type;
        if (!$value instanceof EnumType || $this->target->supports(Capability::from(Capability::ENUMS))) {
            return $type;
        }

        return $value->backing()->value() === EnumBacking::INT ? ScalarType::int() : ScalarType::string();
    }

    /**
     * @param string $warning with "%s" for the PHP version
     */
    private function allowsNew(SchemaLocation $at, string $warning): bool
    {
        if ($this->target->metadata()->isAnnotations() || $this->target->supports(Capability::from(Capability::NEW_IN_INITIALIZERS))) {
            return true;
        }

        $this->diagnostics->warning(sprintf($warning, $this->target->php()->toString()), $at);

        return false;
    }
}
