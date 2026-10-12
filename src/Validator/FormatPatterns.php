<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

/**
 * The Regex patterns of the formats Symfony's own constraints would check wrongly. Only character classes repeat, and
 * a group only once per percent-encoded octet: a repeated group exhausts PCRE's JIT stack on a large value, and
 * Symfony's RegexValidator reports a failed match as a violation. D, as on the regexes of `pattern`, so that "$" takes
 * no trailing newline.
 *
 * @internal
 */
final class FormatPatterns
{
    /**
     * RFC 4648 base64: the standard alphabet, padding only at the end; the length is not checked.
     */
    public const BASE64 = '/^[A-Za-z0-9+\/]*+={0,2}$/D';

    /**
     * RFC 3986 `URI`: a scheme, then an authority and a path that is empty or absolute, an absolute path, a rootless
     * path or nothing, a query and a fragment. Symfony's Url takes only the schemes it lists, each with an authority,
     * so `urn:` and `mailto:` would fail. IP literals are checked for their alphabet only. Past about 500 000
     * percent-encoded octets with the JIT (a million without) the match fails on `pcre.backtrack_limit`.
     */
    public const URI = '/^[A-Za-z][A-Za-z0-9+\-.]*+:(?:'
        . '\/\/(?:(?=[^@\/?#\[\]]*+@)' . self::USERINFO . '@)?(?:' . self::IP_LITERAL . '|' . self::REG_NAME . ')(?::[0-9]*+)?(?:\/' . self::PATH . ')?'
        . '|\/(?!\/)' . self::PATH
        . '|(?:[' . self::CHARS . ':@]|' . self::OCTET . ')' . self::PATH
        . '|)(?:\?' . self::QUERY . ')?(?:#' . self::QUERY . ')?$/D';

    /**
     * RFC 3339 `full-time`. Symfony's Time takes no fraction and no offset, which `time` requires. A leap second is
     * 23:59:60 in UTC; with another offset it is taken at any hour, since that check needs arithmetic.
     */
    public const TIME = '/^(?:' . self::HOUR_MINUTE . ':[0-5][0-9]' . self::FRACTION . '(?:[Zz]|' . self::OFFSET . ')'
        . '|23:59:60' . self::FRACTION . '(?:[Zz]|[+\-]00:00)'
        . '|' . self::HOUR_MINUTE . ':60' . self::FRACTION . '(?![+\-]00:00)' . self::OFFSET . ')$/D';

    /** RFC 3986 unreserved characters and sub-delims. */
    private const CHARS = "A-Za-z0-9\\-._~!$&'()*+,;=";

    private const OCTET = '%[0-9A-Fa-f]{2}';

    private const USERINFO = '[' . self::CHARS . ':]*+(?:' . self::OCTET . '[' . self::CHARS . ':]*+)*+';

    /** ABNF literals ignore case, so IPvFuture's "v" may be "V". */
    private const IP_LITERAL = '\[(?:[0-9A-Fa-f:.]++|[vV][0-9A-Fa-f]++\.[' . self::CHARS . ':]++)\]';

    private const REG_NAME = '[' . self::CHARS . ']*+(?:' . self::OCTET . '[' . self::CHARS . ']*+)*+';

    private const PATH = '[' . self::CHARS . ':@\/]*+(?:' . self::OCTET . '[' . self::CHARS . ':@\/]*+)*+';

    private const QUERY = '[' . self::CHARS . ':@\/?]*+(?:' . self::OCTET . '[' . self::CHARS . ':@\/?]*+)*+';

    private const HOUR_MINUTE = '(?:[01][0-9]|2[0-3]):[0-5][0-9]';

    private const FRACTION = '(?:\.[0-9]++)?';

    private const OFFSET = '[+\-](?:[01][0-9]|2[0-3]):[0-5][0-9]';

    private function __construct()
    {
    }
}
