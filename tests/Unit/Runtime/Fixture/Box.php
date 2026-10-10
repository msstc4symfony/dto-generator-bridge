<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;

class Box
{
    /**
     * @var array<string, int>
     */
    #[AdditionalProperties]
    private array $extra;

    /**
     * @param array<string, int> $extra
     */
    public function __construct(array $extra = [])
    {
        $this->extra = $extra;
    }

    /**
     * @return array<string, int>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
