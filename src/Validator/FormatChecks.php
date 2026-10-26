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

    /**
     * @param bool $default whether a format the switches do not name is checked
     * @param array<string, bool> $switches format, by the name the generator reads it as → whether it is checked
     */
    public function __construct(bool $default, array $switches = [])
    {
        $this->default = $default;
        $this->switches = $switches;
    }

    public function allows(string $format): bool
    {
        return $this->switches[$format] ?? $this->default;
    }
}
