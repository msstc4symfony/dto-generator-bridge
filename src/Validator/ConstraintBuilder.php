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
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The constraints a value's schema and type call for (bridge spec §5.1), in the order of that table.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ConstraintBuilder
{
    /**
     * Format → the constraint that checks it, and the option it needs.
     *
     * @var array<string, array{non-empty-string, ?non-empty-string, string|bool|null}>
     */
    private const FORMATS = [
        'email' => ['Email', 'mode', 'html5'],
        'ipv4' => ['Ip', 'version', '4'],
        'ipv6' => ['Ip', 'version', '6'],
        'hostname' => ['Hostname', 'requireTld', false],
        'uuid' => ['Uuid', null, null],
        'byte' => ['Regex', 'pattern', FormatPatterns::BASE64],
        'uri' => ['Regex', 'pattern', FormatPatterns::URI],
        'time' => ['Regex', 'pattern', FormatPatterns::TIME],
    ];

    /**
     * The keywords JSON Schema applies to one kind of value only.
     *
     * @var array{string: list<non-empty-string>, number: list<non-empty-string>, collection: list<non-empty-string>}
     */
    private const KEYWORDS_OF = [
        'string' => ['minLength', 'maxLength', 'pattern'],
        'number' => ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'],
        'collection' => ['minItems', 'maxItems', 'minProperties', 'maxProperties', 'uniqueItems'],
    ];

    /**
     * The bounds of `format: int32`; `int64` is PHP's own int on 64-bit platforms and needs none. The literal
     * 2147483648 is a float on 32-bit PHP, so the minimum is computed.
     */
    private const INT32 = [-2147483647 - 1, 2147483647];

    /** The name the int32 bounds go by, beside the keywords that compete with them. */
    private const INT32_LABEL = 'format: int32';

    /**
     * The formats whose constraint checks one kind of value only, and that kind as a warning names it.
     *
     * @var array{int32: 'an integer', byte: 'a string', uri: 'a string', time: 'a string'}
     */
    private const FORMAT_KINDS = ['int32' => 'an integer', 'byte' => 'a string', 'uri' => 'a string', 'time' => 'a string'];

    private SchemaReferences $references;

    private TargetProfile $target;

    private Diagnostics $diagnostics;

    private ValueConstraints $values;

    /**
     * The keywords the generator calls unsupported and the runtime's constraints check; the bridge claims them and warns
     * where it cannot check one.
     */
    public const CLAIMED_KEYWORDS = ['dependentRequired', 'propertyNames', 'contains', 'minContains', 'maxContains'];

    private RuntimeConstraints $runtime;

    private KeywordChecks $checks;

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
        $this->values = new ValueConstraints($target, $diagnostics);
        $this->runtime = $runtime;
        $this->checks = $checks;
        $this->groups = $groups;
    }

    /**
     * @return list<ConstraintSpec>
     */
    public function build(Schema $schema, TypeModel $type): array
    {
        $keywords = new Keywords($schema, $this->references);
        $value = $type instanceof NullableType ? $type->inner() : $type;
        $kind = $this->kindOf($value);
        $at = $schema->location();
        $reader = new KeywordReader($keywords, $this->diagnostics, $at);
        // As in the generator, which stays quiet about what a schema whose type x-php-type replaces says.
        $replaced = $keywords->resolved()->extensions()->has('x-php-type');
        $this->warnAboutFormatsOfOneKind($keywords, $kind, $at);

        if (!$replaced) {
            $this->warnAboutUnreachedMembers($keywords, $at);
        }

        return array_merge(
            $this->checks($keywords, $kind, 'string', $at) ? $this->strings($keywords, $reader, $at) : [],
            $this->checks($keywords, $kind, 'number', $at)
                ? $this->numbers($reader, $kind === 'integer', $this->implied($keywords, $kind), $at)
                : [],
            $this->checks($keywords, $kind, 'collection', $at) ? $this->collections($keywords, $reader, $value, $at) : [],
            $replaced ? [] : $this->contains($keywords, $reader, $value, $at),
            $replaced ? [] : $this->keys($keywords, $value, $at),
            $this->values->choices($keywords, $value, $at),
            $this->values->constants($keywords, $value, $at),
            $this->formats($keywords, $value),
            $this->nested($keywords, $value, $at),
        );
    }

    /**
     * The constraints of each element of a list or map, from the schema of the elements.
     *
     * @return list<ConstraintSpec>
     */
    public function elements(Schema $itemSchema, TypeModel $item, SchemaLocation $at): array
    {
        $valid = null;
        $inner = [];
        foreach ($this->build($itemSchema, $item) as $constraint) {
            if ($constraint->is('Valid')) {
                $valid = $constraint;
            } else {
                // Symfony fixes the groups of a nested composite when it is built; the outer All does not pass its own on.
                $inner[] = ($constraint->isComposite() ? $constraint->withGroups($this->groups) : $constraint)->toNewInstance();
            }
        }

        $constraints = $valid === null ? [] : [$valid];
        if ($inner !== [] && $this->allowsNew($at, 'The constraints of the elements go inside All as "new", which PHP %s does not allow in attributes; they are not checked.')) {
            $constraints[] = new ConstraintSpec('All', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))]);
        }

        return $constraints;
    }

    /**
     * The runtime's Keys for the names of the undeclared properties of a class, from the keywords of its schema.
     *
     * @return list<ConstraintSpec>
     */
    public function names(Keywords $keywords, SchemaLocation $at): array
    {
        $constraints = [];
        if ($this->claimed($keywords, 'propertyNames') === [] || !$this->runtime->available('propertyNames', $at)) {
            return [];
        }

        $name = ScalarType::string();
        foreach (array_filter($keywords->schemas(), static fn (Schema $place): bool => $place->hasKeyword('propertyNames')) as $place) {
            $inner = $this->subschema('propertyNames', $place, $name);
            if ($inner !== null && $inner !== []) {
                $constraints[] = ConstraintSpec::runtime('Keys', [AttributeArgument::named('constraints', ArgumentValue::listOf(...$inner))]);
            }
        }

        return $constraints;
    }

    /**
     * Symfony checks a keyword on another kind of value anyway (Length counts the digits of a number), so a value that
     * may be of several types gets none.
     *
     * @param 'string'|'integer'|'number'|'collection'|'several'|null $actual the kind of the value
     * @param 'string'|'number'|'collection' $kind the kind the keywords apply to; an integer is a number
     */
    private function checks(Keywords $keywords, ?string $actual, string $kind, SchemaLocation $at): bool
    {
        $present = array_filter(self::KEYWORDS_OF[$kind], static fn (string $name): bool => $keywords->has($name));
        if ($actual === 'several' && $present !== []) {
            $this->diagnostics->warning(sprintf(
                '"%s" %s only a %s, and the value may be of another type; %s not checked.',
                implode('", "', $present),
                count($present) === 1 ? 'checks' : 'check',
                $kind,
                count($present) === 1 ? 'it is' : 'they are',
            ), $at);
        }

        return $actual === $kind || ($kind === 'number' && $actual === 'integer');
    }

    /**
     * The constraint of such a format would reject the value's other types, so a value of several gets none.
     *
     * @param 'string'|'integer'|'number'|'collection'|'several'|null $actual
     */
    private function warnAboutFormatsOfOneKind(Keywords $keywords, ?string $actual, SchemaLocation $at): void
    {
        if ($actual !== 'several') {
            return;
        }

        foreach (array_intersect(array_keys(self::FORMAT_KINDS), $keywords->formats()) as $format) {
            $this->diagnostics->warning(sprintf(
                '"format: %s" checks only %s, and the value may be of another type; it is not checked.',
                $format,
                self::FORMAT_KINDS[$format],
            ), $at);
        }
    }

    /**
     * @return 'string'|'integer'|'number'|'collection'|'several'|null
     */
    private function kindOf(TypeModel $value): ?string
    {
        if ($value instanceof ScalarType) {
            $kinds = ['string' => 'string', 'int' => 'integer', 'float' => 'number', 'bool' => null];

            return $kinds[$value->kind()];
        }

        if ($value instanceof ListType || $value instanceof MapType) {
            return 'collection';
        }

        return $value instanceof UnionType || $value instanceof MixedType ? 'several' : null;
    }

    /**
     * @return list<ConstraintSpec>
     */
    private function strings(Keywords $keywords, KeywordReader $reader, SchemaLocation $at): array
    {
        $constraints = [];
        $length = $this->bounds($reader, 'minLength', 'maxLength', $at);
        if ($length !== []) {
            $constraints[] = new ConstraintSpec('Length', $length);
        }

        foreach ($keywords->values('pattern') as $pattern) {
            $regex = is_string($pattern) ? Pattern::toPcre($pattern) : null;
            if ($regex === null) {
                $this->diagnostics->warning('"pattern" is no regular expression PHP can run; it is not checked.', $at);

                continue;
            }

            $constraints[] = new ConstraintSpec('Regex', [AttributeArgument::named('pattern', ArgumentValue::literal($regex))]);
        }

        return $constraints;
    }

    /**
     * The inclusive bounds the format of an integer sets, each with the name a warning gives it.
     *
     * @param 'string'|'integer'|'number'|'collection'|'several'|null $kind
     *
     * @return array{array{int, 'format: int32'}, array{int, 'format: int32'}}|null
     */
    private function implied(Keywords $keywords, ?string $kind): ?array
    {
        if ($kind !== 'integer' || !in_array('int32', $keywords->formats(), true)) {
            return null;
        }

        return [[self::INT32[0], self::INT32_LABEL], [self::INT32[1], self::INT32_LABEL]];
    }

    /**
     * One lower and one upper bound: the strictest of the inclusive and exclusive keywords and the implied bounds,
     * exclusive on a tie.
     *
     * @param array{array{int, 'format: int32'}, array{int, 'format: int32'}}|null $implied
     *
     * @return list<ConstraintSpec>
     */
    private function numbers(KeywordReader $reader, bool $integer, ?array $implied, SchemaLocation $at): array
    {
        [$impliedLower, $impliedUpper] = $implied ?? [null, null];
        $lower = $this->strictest($reader, 'exclusiveMinimum', 'minimum', true, $impliedLower);
        $upper = $this->strictest($reader, 'exclusiveMaximum', 'maximum', false, $impliedUpper);
        $constraints = [];
        if ($lower !== null && $upper !== null && !$this->satisfiable($lower, $upper, $integer, $at)) {
            $lower = null;
            $upper = null;
        }

        $lowerExclusive = $lower !== null && $lower[1] === 'exclusiveMinimum';
        $upperExclusive = $upper !== null && $upper[1] === 'exclusiveMaximum';
        if ($lower !== null && $upper !== null && !$lowerExclusive && !$upperExclusive) {
            $constraints[] = new ConstraintSpec('Range', [
                AttributeArgument::named('min', ArgumentValue::literal($lower[0])),
                AttributeArgument::named('max', ArgumentValue::literal($upper[0])),
            ]);
        } else {
            if ($lower !== null) {
                $constraints[] = $this->comparison($lowerExclusive ? 'GreaterThan' : 'GreaterThanOrEqual', $lower[0]);
            }

            if ($upper !== null) {
                $constraints[] = $this->comparison($upperExclusive ? 'LessThan' : 'LessThanOrEqual', $upper[0]);
            }
        }

        foreach ($reader->divisors('multipleOf') as $multipleOf) {
            $constraints[] = $this->comparison('DivisibleBy', $multipleOf);
        }

        return $constraints;
    }

    /**
     * The strictest bound and its keyword. On a tie the first candidate stays: an exclusive keyword before an inclusive
     * one, a keyword before an implied bound.
     *
     * @param 'exclusiveMinimum'|'exclusiveMaximum' $exclusive
     * @param 'minimum'|'maximum' $inclusive
     * @param bool $lower whether the greatest bound is the strictest
     * @param array{int, 'format: int32'}|null $implied an inclusive bound a format sets, and its name
     *
     * @return array{int|float, 'exclusiveMinimum'|'minimum'|'exclusiveMaximum'|'maximum'|'format: int32'}|null
     */
    private function strictest(
        KeywordReader $reader,
        string $exclusive,
        string $inclusive,
        bool $lower,
        ?array $implied
    ): ?array {
        $candidates = [];
        foreach ([$exclusive, $inclusive] as $keyword) {
            foreach ($reader->numbers($keyword) as $bound) {
                $candidates[] = [$bound, $keyword];
            }
        }

        if ($implied !== null) {
            $candidates[] = $implied;
        }

        $strictest = null;
        foreach ($candidates as $candidate) {
            if ($strictest === null || ($lower ? $candidate[0] > $strictest[0] : $candidate[0] < $strictest[0])) {
                $strictest = $candidate;
            }
        }

        return $strictest;
    }

    /**
     * @param array{int|float, 'exclusiveMinimum'|'minimum'|'exclusiveMaximum'|'maximum'|'format: int32'} $lower
     * @param array{int|float, 'exclusiveMinimum'|'minimum'|'exclusiveMaximum'|'maximum'|'format: int32'} $upper
     */
    private function satisfiable(array $lower, array $upper, bool $integer, SchemaLocation $at): bool
    {
        if (!Interval::isEmpty($lower[0], $lower[1] === 'exclusiveMinimum', $upper[0], $upper[1] === 'exclusiveMaximum', $integer)) {
            return true;
        }

        $this->diagnostics->warning(sprintf('"%s" and "%s" leave no valid value; they are not checked.', $lower[1], $upper[1]), $at);

        return false;
    }

    /**
     * JSON Schema applies uniqueItems to arrays only, and the properties of a map to minProperties/maxProperties.
     *
     * @return list<ConstraintSpec>
     */
    private function collections(Keywords $keywords, KeywordReader $reader, TypeModel $value, SchemaLocation $at): array
    {
        $isMap = $value instanceof MapType;
        $constraints = [];
        $count = $isMap
            ? $this->bounds($reader, 'minProperties', 'maxProperties', $at)
            : $this->bounds($reader, 'minItems', 'maxItems', $at);
        if ($count !== []) {
            $constraints[] = new ConstraintSpec('Count', $count);
        }

        if (!$isMap && in_array(true, $keywords->values('uniqueItems'), true)) {
            $constraints[] = new ConstraintSpec('Unique');
        }

        return $constraints;
    }

    /**
     * Every format applies; one the config maps to a class gives the property that type, and the class validates
     * itself.
     *
     * @return list<ConstraintSpec>
     */
    private function formats(Keywords $keywords, TypeModel $value): array
    {
        if (!$value instanceof ScalarType || $value->kind() !== 'string') {
            return [];
        }

        $constraints = [];
        foreach ($keywords->formats() as $format) {
            if (isset(self::FORMATS[$format])) {
                [$name, $option, $setting] = self::FORMATS[$format];
                $constraints[] = new ConstraintSpec($name, $option === null ? [] : [AttributeArgument::named($option, ArgumentValue::literal($setting))]);
            }
        }

        return $constraints;
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
     * The runtime's Contains for each `contains`, bounded by the `minContains` and `maxContains` of its own schema.
     *
     * @return list<ConstraintSpec>
     */
    private function contains(Keywords $keywords, KeywordReader $reader, TypeModel $value, SchemaLocation $at): array
    {
        $places = array_filter($keywords->schemas(), static fn (Schema $place): bool => $place->hasKeyword('contains'));
        $this->claimed($keywords, 'minContains');
        $this->claimed($keywords, 'maxContains');
        $this->claimed($keywords, 'contains');
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
            if (($min !== 0 || $max !== null) && ($max === null || $this->consistent('minContains', $min, 'maxContains', $max, $at))) {
                $checked[] = [$place, $min, $max];
            }
        }

        if ($checked === [] || !$this->runtime->available('contains', $at)) {
            return [];
        }

        $constraints = [];
        foreach ($checked as [$place, $min, $max]) {
            $inner = $this->subschema('contains', $place, $value->item());
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
     * A member of a union of several values is no value of its own here, so its keywords go unchecked, with a warning
     * at the value's schema (the property, or its items): a shared component may be checked through another value,
     * which marks it. A place that is a class checks its keywords itself.
     */
    private function warnAboutUnreachedMembers(Keywords $keywords, SchemaLocation $at): void
    {
        // The generator refuses a $ref chain that loops without an object, so the members end.
        $members = $keywords->unreachedMembers();
        while ($members !== []) {
            $places = new Keywords(array_shift($members), $this->references);
            $union = $places->unreachedMembers();
            if ($union === [] && $places->describesObject()) {
                // A class of its own, whose enrichment checks its keywords.
                continue;
            }

            foreach ($places->schemas() as $place) {
                if ($place->propertyNames() === []) {
                    $this->warnAboutMemberPlace($place, $at);
                }
            }

            array_push($members, ...$union);
        }
    }

    private function warnAboutMemberPlace(Schema $place, SchemaLocation $at): void
    {
        foreach (self::CLAIMED_KEYWORDS as $keyword) {
            if ($place->hasKeyword($keyword)) {
                $this->checks->checked($place->location(), $keyword);
                $this->diagnostics->warning(sprintf('"%s" in a member of "oneOf" or "anyOf" is not checked.', $keyword), $at);
            }
        }
    }

    /**
     * The values of a keyword the bridge claims, each place marked as handled: checked, or warned about by the caller.
     *
     * @return list<JsonValue>
     */
    private function claimed(Keywords $keywords, string $name): array
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
     * A map gets Keys here; a class gets them on its $additionalProperties, from ValidatorEnricher, which reads the
     * keywords of the class's own schema: those beside a `$ref` to the class stay unmarked, and the generator warns.
     *
     * @return list<ConstraintSpec>
     */
    private function keys(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if ($keywords->describesObject()) {
            return [];
        }

        if ($this->claimed($keywords, 'dependentRequired') !== []) {
            $this->diagnostics->warning('"dependentRequired" is checked on classes only; it is not checked here.', $at);
        }

        if (!$value instanceof MapType) {
            if ($this->claimed($keywords, 'propertyNames') !== []) {
                $this->diagnostics->warning('"propertyNames" is checked on maps and on the undeclared properties of a class only; it is not checked here.', $at);
            }

            return [];
        }

        return $this->names($keywords, $at);
    }

    /**
     * The constraints of a subschema as `new` arguments; null when it is not checked.
     *
     * @param non-empty-string $keyword
     * @param Schema $place the schema that holds the keyword, where the warnings go
     *
     * @return list<ArgumentValue>|null
     */
    private function subschema(string $keyword, Schema $place, TypeModel $type): ?array
    {
        $at = $place->location();
        $parsed = Subschema::read($keyword, $place->keyword($keyword), $type, $at, $this->diagnostics);
        if (!$parsed instanceof Subschema) {
            return null;
        }

        // Diagnostics drops a repeated message, so the subschema gets its own to tell whether any keyword went unchecked.
        $own = new Diagnostics();
        $builder = new self($this->references, $this->target, $own, $this->runtime, new KeywordChecks(), []);
        $element = $type instanceof NullableType ? $type->inner() : $type;
        $nullable = $type instanceof NullableType;
        $constraints = array_merge($nullable && $parsed->excludesNull() ? [new ConstraintSpec('NotNull')] : [], $builder->build($parsed->schema(), $element));
        $this->diagnostics->merge($own);
        if ($own->all() !== []) {
            // A keyword of the schema went unchecked, so fewer elements would match, or more.
            $this->diagnostics->warning(sprintf('"%s" is not checked, as part of its schema cannot be.', $keyword), $at);

            return null;
        }

        $inner = array_map(static fn (ConstraintSpec $constraint): ArgumentValue => $constraint->toNewInstance(), $constraints);
        if ($inner !== [] && !$this->allowsNew($at, sprintf('"%s" goes into the attribute as "new", which PHP %%s does not allow; it is not checked.', $keyword))) {
            return null;
        }

        return $inner;
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

    /**
     * @return list<AttributeArgument>
     */
    private function bounds(KeywordReader $reader, string $minimum, string $maximum, SchemaLocation $at): array
    {
        $lower = $reader->counts($minimum);
        $upper = $reader->counts($maximum);
        if ($lower !== [] && $upper !== [] && !$this->consistent($minimum, max($lower), $maximum, min($upper), $at)) {
            return [];
        }

        $bounds = [];
        if ($lower !== []) {
            $bounds[] = AttributeArgument::named('min', ArgumentValue::literal(max($lower)));
        }

        if ($upper !== []) {
            $bounds[] = AttributeArgument::named('max', ArgumentValue::literal(min($upper)));
        }

        return $bounds;
    }

    private function consistent(string $minimum, int $lower, string $maximum, int $upper, SchemaLocation $at): bool
    {
        if ($lower <= $upper) {
            return true;
        }

        $this->diagnostics->warning(sprintf('"%s" is above "%s", so no value is valid; they are not checked.', $minimum, $maximum), $at);

        return false;
    }

    /**
     * @param non-empty-string $name
     * @param bool|float|int|string $value
     */
    private function comparison(string $name, $value): ConstraintSpec
    {
        return new ConstraintSpec($name, [AttributeArgument::named('value', ArgumentValue::literal($value))]);
    }
}
