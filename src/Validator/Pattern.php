<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

/**
 * A JSON Schema `pattern` (ECMA-262) as a PCRE regular expression for Symfony's Regex constraint.
 */
final class Pattern
{
    private function __construct()
    {
    }

    /**
     * The delimited expression, or null when PCRE cannot compile it.
     */
    public static function toPcre(string $pattern): ?string
    {
        $pcre = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '/') {
                $pcre .= '\/';

                continue;
            }

            if ($char !== '\\' || $i + 1 === $length) {
                $pcre .= $char;

                continue;
            }

            $escaped = $pattern[++$i];
            if ($escaped !== 'u') {
                $pcre .= '\\' . $escaped;

                continue;
            }

            $unicode = UnicodeEscape::read($pattern, $i + 1);
            if ($unicode === null) {
                return null;
            }

            [$point, $consumed] = $unicode;
            $pcre .= sprintf('\x{%X}', $point);
            $i += $consumed;
        }

        // D: "$" ends the subject, as in ECMA-262, rather than also matching before a final newline.
        $regex = '/' . $pcre . '/uD';

        return @preg_match($regex, '') === false ? null : $regex;
    }
}
