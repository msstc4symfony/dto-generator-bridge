<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;

/**
 * One Validator constraint to write: as an attribute, or as `new` inside a composite constraint. Symfony's, or one of
 * msstc4symfony/dto-generator-bridge-runtime for the keywords Symfony has none for.
 *
 * @internal
 */
final class ConstraintSpec
{
    /**
     * Import alias => namespace.
     */
    private const NAMESPACES = [
        'Assert' => 'Symfony\Component\Validator\Constraints',
        'RuntimeAssert' => 'Msstc4Symfony\DtoGeneratorBridge\Runtime\Constraints',
    ];

    /** @var non-empty-string */
    private string $name;

    /** @var list<AttributeArgument> */
    private array $arguments;

    /** @var key-of<self::NAMESPACES> */
    private string $alias = 'Assert';

    /**
     * @param non-empty-string $name the class name inside Symfony\Component\Validator\Constraints
     * @param list<AttributeArgument> $arguments
     */
    public function __construct(string $name, array $arguments = [])
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }

    /**
     * @param non-empty-string $name the class name inside Msstc4Symfony\DtoGeneratorBridge\Runtime\Constraints
     * @param list<AttributeArgument> $arguments
     */
    public static function runtime(string $name, array $arguments = []): self
    {
        $spec = new self($name, $arguments);
        $spec->alias = 'RuntimeAssert';

        return $spec;
    }

    /**
     * @param non-empty-string $name
     */
    public function is(string $name): bool
    {
        return $this->name === $name;
    }

    /**
     * Whether it holds constraints of its own, which take its groups only when it is built.
     */
    public function isComposite(): bool
    {
        return in_array($this->name, ['All', 'Contains', 'Keys', 'PatternProperty', 'IfThenElse', 'Property'], true);
    }

    /**
     * @param list<non-empty-string> $groups
     */
    public function withGroups(array $groups): self
    {
        if ($groups === []) {
            return $this;
        }

        $values = array_map(static fn (string $group): ArgumentValue => ArgumentValue::literal($group), $groups);

        $spec = clone $this;
        $spec->arguments[] = AttributeArgument::named('groups', ArgumentValue::listOf(...$values));

        return $spec;
    }

    public function toAttribute(): AttributeModel
    {
        return new AttributeModel($this->className(), $this->arguments, new ImportAlias(self::NAMESPACES[$this->alias], $this->alias));
    }

    public function toNewInstance(): ArgumentValue
    {
        return ArgumentValue::newInstance($this->className(), ...$this->arguments);
    }

    private function className(): ClassName
    {
        return ClassName::fromFqcn(self::NAMESPACES[$this->alias] . '\\' . $this->name);
    }
}
