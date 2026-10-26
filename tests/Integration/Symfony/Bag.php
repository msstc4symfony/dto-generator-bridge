<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Integration\Symfony;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * The collection class of the "collections" variant: built and read as dto-collection's lists are, countable and
 * iterable as Count, Unique, All and Valid need.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Bag implements Countable, IteratorAggregate
{
    /** @var list<T> */
    private array $elements;

    /**
     * @param list<T> $elements
     */
    private function __construct(array $elements)
    {
        $this->elements = $elements;
    }

    /**
     * @template E
     *
     * @param list<E> $elements
     *
     * @return self<E>
     */
    public static function fromArray(array $elements): self
    {
        return new self($elements);
    }

    /**
     * @return list<T>
     */
    public function toArray(): array
    {
        return $this->elements;
    }

    public function count(): int
    {
        return count($this->elements);
    }

    /**
     * @return ArrayIterator<int, T>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->elements);
    }
}
