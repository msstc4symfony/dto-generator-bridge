<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

/**
 * An ECMA-262 `\u` escape: four hex digits or `{…}`, a UTF-16 surrogate pair joined into one code point.
 */
final class UnicodeEscape
{
    private function __construct()
    {
    }

    /**
     * The code point of the \u escape whose digits start at $offset, joining a UTF-16 surrogate pair as JavaScript does.
     * PCRE has no \u, and older PCRE2 builds take a malformed one as a literal "u", so it is rejected here.
     *
     * @return array{int, positive-int}|null the code point and the characters read after the "u"
     */
    public static function read(string $pattern, int $offset): ?array
    {
        if (preg_match('~\G(?|([0-9A-Fa-f]{4})|\{([0-9A-Fa-f]{1,6})\})~', $pattern, $code, 0, $offset) !== 1) {
            return null;
        }

        $point = intval($code[1], 16);
        $consumed = strlen($code[0]);
        $low = '~\G\\\\u([dD][c-fC-F][0-9A-Fa-f]{2})~';
        $isHighSurrogate = ($point & 0xFC00) === 0xD800;
        if ($isHighSurrogate && preg_match($low, $pattern, $pair, 0, $offset + $consumed) === 1) {
            $point = 0x10000 + (($point - 0xD800) << 10) + (intval($pair[1], 16) - 0xDC00);
            $consumed += strlen($pair[0]);
        }

        // A surrogate left alone is no character; PCRE2 in PHP 7.4 still compiles it, later versions do not.
        if (($point & 0xF800) === 0xD800) {
            return null;
        }

        return [$point, $consumed];
    }
}
