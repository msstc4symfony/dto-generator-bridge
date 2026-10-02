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
            // PCRE has no \uXXXX; \x{XXXX} is the same code point under the /u modifier. Older PCRE2 builds take a
            // malformed \u as a literal "u", so it is rejected here rather than by the compiler.
            if ($escaped === 'u') {
                if (preg_match('~\G(?|([0-9A-Fa-f]{4})|\{([0-9A-Fa-f]{1,6})\})~', $pattern, $code, 0, $i + 1) !== 1) {
                    return null;
                }

                $pcre .= '\x{' . $code[1] . '}';
                $i += strlen($code[0]);

                continue;
            }

            $pcre .= '\\' . $escaped;
        }

        $regex = '/' . $pcre . '/u';

        return @preg_match($regex, '') === false ? null : $regex;
    }
}
