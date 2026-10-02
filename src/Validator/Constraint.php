<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;

/**
 * One Symfony Validator constraint, written as an attribute of a property or as `new` inside `All`.
 */
final class Constraint
{
    private const NAMESPACE = 'Symfony\Component\Validator\Constraints';

    /** @var non-empty-string */
    private string $name;

    /** @var list<AttributeArgument> */
    private array $arguments;

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
     * @param list<non-empty-string> $groups
     */
    public function withGroups(array $groups): self
    {
        if ($groups === []) {
            return $this;
        }

        $values = array_map(static fn (string $group): ArgumentValue => ArgumentValue::literal($group), $groups);

        return new self($this->name, array_merge($this->arguments, [AttributeArgument::named('groups', ArgumentValue::listOf(...$values))]));
    }

    public function toAttribute(): AttributeModel
    {
        return new AttributeModel($this->className(), $this->arguments, new ImportAlias(self::NAMESPACE, 'Assert'));
    }

    public function toNewInstance(): ArgumentValue
    {
        return ArgumentValue::newInstance($this->className(), ...$this->arguments);
    }

    private function className(): ClassName
    {
        return ClassName::fromFqcn(self::NAMESPACE . '\\' . $this->name);
    }
}
