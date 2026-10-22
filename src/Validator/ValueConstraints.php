<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The constraints that pin a value to listed ones: `enum` and `const`. Symfony compares with ===, so a JSON number is
 * written as the PHP type of the property (1 on a float is 1.0, 2.0 on an int is 2).
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @internal
 */
final class ValueConstraints
{
    private TargetProfile $target;

    private Diagnostics $diagnostics;

    public function __construct(TargetProfile $target, Diagnostics $diagnostics)
    {
        $this->target = $target;
        $this->diagnostics = $diagnostics;
    }

    /**
     * A PHP enum limits the values itself; where the target has none, an enum is written as a plain string or int, and
     * only the constraint does.
     *
     * @return list<ConstraintSpec>
     */
    public function choices(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        $enum = $keywords->enum();
        // Validator constraints accept null anyway: an enum of null alone needs nothing.
        if ($enum === null || ($enum !== [] && $this->onlyNull($enum)) || $this->heldByClass($value, 'enum', $at)) {
            return [];
        }

        if ($enum === []) {
            $this->diagnostics->warning('The "enum" lists have no value in common, so no value is valid; they are not checked.', $at);

            return [];
        }

        if ($value instanceof EnumType && $this->hasEnums()) {
            return $this->enumCases($value, $enum, $at);
        }

        $choices = [];
        foreach ($enum as $choice) {
            if (is_array($choice)) {
                $this->diagnostics->warning('"enum" lists an array or object, which a Choice attribute cannot hold; it is not checked.', $at);

                return [];
            }

            if ($choice !== null) {
                $choices[] = ArgumentValue::literal($this->asTypeOf($choice, $value));
            }
        }

        return [new ConstraintSpec('Choice', [AttributeArgument::named('choices', ArgumentValue::listOf(...$choices))])];
    }

    /**
     * @return list<ConstraintSpec>
     */
    public function constants(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        $constants = $keywords->values('const');
        if ($constants === []) {
            return [];
        }

        if (count($constants) > 1) {
            $this->diagnostics->warning('The "const" values differ, so no value is valid; they are not checked.', $at);

            return [];
        }

        // IsNull checks an object as well as a scalar.
        if ($constants[0] !== null && $this->heldByClass($value, 'const', $at)) {
            return [];
        }

        $constraint = $this->constant($constants[0], $value, $at);

        return $constraint instanceof ConstraintSpec ? [$constraint] : [];
    }

    /**
     * The property holds a case of the generated enum, which allows every value of it: a narrower `enum` (one beside a
     * `$ref`, or a branch's) needs Choice of the cases it allows.
     *
     * @param non-empty-list<JsonValue> $enum
     *
     * @return list<ConstraintSpec>
     */
    private function enumCases(EnumType $type, array $enum, SchemaLocation $at): array
    {
        $cases = [];
        foreach ($enum as $choice) {
            $case = $type->caseFor($choice);
            if ($case !== null && !in_array($case, $cases, true)) {
                $cases[] = $case;
            }
        }

        if ($cases === []) {
            $this->diagnostics->warning('"enum" lists none of the values of the enum type, so no value is valid; it is not checked.', $at);

            return [];
        }

        if (count($cases) === count($type->cases())) {
            return [];
        }

        $constants = array_map(static fn (string $case): ArgumentValue => ArgumentValue::constant($case, $type->className()), $cases);

        return [new ConstraintSpec('Choice', [AttributeArgument::named('choices', ArgumentValue::listOf(...$constants))])];
    }

    /**
     * @param list<JsonValue> $enum
     */
    private function onlyNull(array $enum): bool
    {
        foreach ($enum as $choice) {
            if ($choice !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param JsonValue $constant
     */
    private function constant($constant, TypeModel $value, SchemaLocation $at): ?ConstraintSpec
    {
        if ($constant === null) {
            return new ConstraintSpec('IsNull');
        }

        if ($value instanceof EnumType && $this->hasEnums()) {
            return $this->enumCase($value, $constant, $at);
        }

        if (is_scalar($constant)) {
            return $this->identicalTo(ArgumentValue::literal($this->asTypeOf($constant, $value)));
        }

        $this->diagnostics->warning('"const" with an array or object has no Symfony constraint; it is not checked.', $at);

        return null;
    }

    /**
     * A class holds the value (`x-php-type`, a `formats` class, a date, a generated class): Choice and IdenticalTo
     * would compare the object with the JSON value and reject every one.
     */
    private function heldByClass(TypeModel $value, string $keyword, SchemaLocation $at): bool
    {
        if (!$value instanceof ClassType) {
            return false;
        }

        $this->diagnostics->warning(sprintf('"%s" on a value of class %s has no constraint to check it; it is not checked.', $keyword, $value->className()->fqcn()), $at);

        return true;
    }

    /**
     * The property holds the enum case, not its value.
     *
     * @param JsonValue $constant
     */
    private function enumCase(EnumType $enum, $constant, SchemaLocation $at): ?ConstraintSpec
    {
        $case = $enum->caseFor($constant);
        if ($case === null) {
            $this->diagnostics->warning('"const" is none of the enum values; it is not checked.', $at);

            return null;
        }

        return $this->identicalTo(ArgumentValue::constant($case, $enum->className()));
    }

    /**
     * @param bool|float|int|string $scalar
     *
     * @return bool|float|int|string
     */
    private function asTypeOf($scalar, TypeModel $value)
    {
        $kind = $value instanceof ScalarType ? $value->kind() : null;
        if (is_int($scalar) && $kind === 'float') {
            return (float) $scalar;
        }

        if (is_float($scalar) && $kind === 'int' && (float) (int) $scalar === $scalar) {
            return (int) $scalar;
        }

        return $scalar;
    }

    private function identicalTo(ArgumentValue $value): ConstraintSpec
    {
        return new ConstraintSpec('IdenticalTo', [AttributeArgument::named('value', $value)]);
    }

    private function hasEnums(): bool
    {
        return $this->target->supports(Capability::from(Capability::ENUMS));
    }
}
