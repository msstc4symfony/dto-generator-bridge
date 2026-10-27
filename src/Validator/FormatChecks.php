<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

/**
 * Which formats get their constraints (`extensionConfig.symfony.formats`); one turned off is left as an annotation, as
 * JSON Schema has it by default.
 *
 * @internal
 */
final class FormatChecks
{
    private bool $default;

    /** @var array<string, bool> */
    private array $switches;

    /** @var list<string> */
    private array $strict;

    /**
     * @param bool $default whether a format the switches do not name is checked
     * @param array<string, bool> $switches format, by the name the generator reads it as → whether it is checked
     * @param list<string> $strict the checked formats a library checks instead of a Regex
     */
    public function __construct(bool $default, array $switches = [], array $strict = [])
    {
        $this->default = $default;
        $this->switches = $switches;
        $this->strict = $strict;
    }

    public function allows(string $format): bool
    {
        return $this->switches[$format] ?? $this->default;
    }

    /**
     * The checks of a property whose schema turns every format on or off (`x-validator-format`), each as strict as the
     * config sets it.
     */
    public function allSwitched(bool $checked): self
    {
        return new self($checked, [], $this->strict);
    }

    /**
     * The checks without a library, which the project does not install: each format by its Regex.
     */
    public function withoutStrict(): self
    {
        return new self($this->default, $this->switches);
    }

    public function isStrict(string $format): bool
    {
        return in_array($format, $this->strict, true);
    }
}
