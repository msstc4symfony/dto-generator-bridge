<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Runtime\Fixture;

use DateTimeImmutable;
use Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties;
use Symfony\Component\Serializer\Attribute as Serializer;

/**
 * Shaped like a DTO the generator writes for PHP 7.4 to 8.0: private properties, getters, a constructor.
 */
final class Bag
{
    private string $label;

    #[Serializer\SerializedName('first_name')]
    private ?string $firstName;

    private ?DateTimeImmutable $seen;

    /**
     * @var array<string, Owner>
     */
    #[AdditionalProperties]
    #[Serializer\Groups(['extra'])]
    private array $additionalProperties;

    /**
     * @param array<string, Owner> $additionalProperties
     */
    public function __construct(string $label, ?string $firstName = null, ?DateTimeImmutable $seen = null, array $additionalProperties = [])
    {
        $this->label = $label;
        $this->firstName = $firstName;
        $this->seen = $seen;
        $this->additionalProperties = $additionalProperties;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getSeen(): ?DateTimeImmutable
    {
        return $this->seen;
    }

    /**
     * @return array<string, Owner>
     */
    public function getAdditionalProperties(): array
    {
        return $this->additionalProperties;
    }
}
