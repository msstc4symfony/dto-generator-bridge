<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

final class Crate extends Box
{
    private int $size;

    /**
     * @param array<string, int> $extra
     */
    public function __construct(int $size, array $extra = [])
    {
        parent::__construct($extra);
        $this->size = $size;
    }

    public function getSize(): int
    {
        return $this->size;
    }
}
