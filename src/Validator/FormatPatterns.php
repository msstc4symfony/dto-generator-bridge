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
     * percent-encoded octets with the JIT (a million without) the match fails on `pcre.backtrack_limit`. Userinfo is
     * tried only before an "@": otherwise an authority without one is read twice, and a host reaches that limit at half
     * the octets.
     */
    public const URI = '/^[A-Za-z][A-Za-z0-9+\-.]*+:(?:'
        . '\/\/(?:(?=[^@\/?#\[\]]*+@)' . self::USERINFO . '@)?(?:' . self::IP_LITERAL . '|' . self::REG_NAME . ')(?::[0-9]*+)?(?:\/' . self::PATH . ')?'
        . '|\/(?!\/)' . self::PATH
        . '|(?:[' . self::CHARS . ':@]|' . self::OCTET . ')' . self::PATH
        . '|)(?:\?' . self::QUERY . ')?(?:#' . self::QUERY . ')?$/D';

    /**
     * RFC 3339 `full-time`. Symfony's Time takes no fraction and no offset, which `time` requires. A leap second is
     * 23:59:60 in UTC, so at minute 59 with an offset of whole hours; the hour, and the minute beside an offset such as
     * +05:30, are not checked, since that needs arithmetic.
     */
    public const TIME = '/^(?:' . self::HOUR_MINUTE . ':[0-5][0-9]' . self::FRACTION . '(?:[Zz]|' . self::OFFSET . ')'
        . '|23:59:60' . self::FRACTION . '(?:[Zz]|[+\-]00:00)'
        . '|' . self::HOUR . ':59:60' . self::FRACTION . '[+\-](?!00:00)' . self::HOUR . ':00'
        . '|' . self::HOUR_MINUTE . ':60' . self::FRACTION . '[+\-]' . self::HOUR . ':(?!00)[0-5][0-9])$/D';

    /** ISO 4217 letters, in either case: Stripe writes `usd`. */
    public const CURRENCY = '/^[A-Za-z]{3}$/D';

    /**
     * A phone number as people write it (Twilio's `(800) 555-1234`, Linode's `555-555-5555`, E.164 `+15017122661`,
     * `+7 (495) 123–45–67` pasted with no-break spaces and en dashes), with an extension (`x12`, `ext. 12`, `;ext=12`):
     * digits among spaces, dots, slashes, hyphens and parentheses. Whether the number exists in its country needs a
     * library such as libphonenumber.
     */
    public const PHONE = '/^(?=[^0-9]*+[0-9])\+?+[0-9 ().\/\-\x{00A0}\x{2013}]++(?:(?:x|ext\.?+|#|;ext=)[ ]*+[0-9]++)?+$/Diu';

    /** A decimal number in a string (Stripe's `decimal`), leniently: a sign, leading zeros, a trailing dot, an exponent. */
    public const DECIMAL = '/^[+\-]?+(?:[0-9]++(?:\.[0-9]*+)?+|\.[0-9]++)(?:[eE][+\-]?+[0-9]++)?+$/D';

    /** An integer in a string (Youtube's `uint64`), whose range is not checked. */
    public const INTEGER = '/^[+\-]?+[0-9]++$/D';

    public const DIGITS = '/^[0-9]++$/D';

    /**
     * RFC 3339 `duration` (ISO 8601): weeks alone, or a date part and a time part of whole numbers; a fraction only of
     * seconds, as RFC 3339 has none and ISO 8601 allows one in the last part. No sign.
     */
    public const DURATION = '/^P(?=[0-9T])(?:[0-9]++W|(?:[0-9]++Y)?+(?:[0-9]++M)?+(?:[0-9]++D)?+(?:T(?=[0-9])(?:[0-9]++H)?+(?:[0-9]++M)?+(?:[0-9]++(?:[.,][0-9]++)?+S)?+)?+)$/D';

    /**
     * An RFC 9110 method: a token, as methods are extensible (`PROPFIND`, `QUERY`) and specs list them in either case
     * (Twilio's enum has `get` and `GET`).
     */
    public const HTTP_METHOD = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]++$/D';

    /** Linode's `MM/YYYY`. */
    public const MONTH_YEAR = '/^(?:0[1-9]|1[0-2])\/[0-9]{4}$/D';

    /**
     * Six pairs of hexadecimal digits joined by colons or by hyphens (one of them throughout), three groups of four
     * joined by dots, or twelve digits.
     */
    public const MAC_ADDRESS = '/^(?:[0-9A-Fa-f]{2}([:\-])[0-9A-Fa-f]{2}(?:\1[0-9A-Fa-f]{2}){4}|[0-9A-Fa-f]{4}(?:\.[0-9A-Fa-f]{4}){2}|[0-9A-Fa-f]{12})$/D';

    /** Three, four, six or eight hexadecimal digits, with or without `#`, which CssColor requires. */
    public const HEX_COLOR = '/^#?+(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/D';

    /**
     * Linode's `ipv4|ipv6/prefix_length`: an address or a range, of either version, as its examples have no prefix.
     * Symfony's Ip and Cidr take one of the two each. The groups of an IPv6 address ending in an IPv4 one and holding
     * "::" are not counted exactly.
     */
    public const IP_OR_CIDR = '/^(?:' . self::IPV4 . '(?:\/(?:3[0-2]|[12]?[0-9]))?|' . self::IPV6 . '(?:\/(?:12[0-8]|1[01][0-9]|[1-9]?[0-9]))?)$/D';

    /** RFC 3986 unreserved characters and sub-delims. */
    private const CHARS = "A-Za-z0-9\\-._~!$&'()*+,;=";

    private const OCTET = '%[0-9A-Fa-f]{2}';

    private const USERINFO = '[' . self::CHARS . ':]*+(?:' . self::OCTET . '[' . self::CHARS . ':]*+)*+';

    /** ABNF literals ignore case, so IPvFuture's "v" may be "V". */
    private const IP_LITERAL = '\[(?:[0-9A-Fa-f:.]++|[vV][0-9A-Fa-f]++\.[' . self::CHARS . ':]++)\]';

    private const REG_NAME = '[' . self::CHARS . ']*+(?:' . self::OCTET . '[' . self::CHARS . ']*+)*+';

    private const PATH = '[' . self::CHARS . ':@\/]*+(?:' . self::OCTET . '[' . self::CHARS . ':@\/]*+)*+';

    private const QUERY = '[' . self::CHARS . ':@\/?]*+(?:' . self::OCTET . '[' . self::CHARS . ':@\/?]*+)*+';

    private const IPV4_OCTET = '(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])';

    private const IPV4 = self::IPV4_OCTET . '(?:\\.' . self::IPV4_OCTET . '){3}';

    private const HEXTET = '[0-9A-Fa-f]{1,4}';

    /** RFC 4291: eight groups, fewer around one "::", or six and an IPv4 address. */
    private const IPV6 = '(?:(?:' . self::HEXTET . ':){7}' . self::HEXTET
        . '|(?:' . self::HEXTET . ':){1,7}:'
        . '|(?:' . self::HEXTET . ':){1,6}:' . self::HEXTET
        . '|(?:' . self::HEXTET . ':){1,5}(?::' . self::HEXTET . '){1,2}'
        . '|(?:' . self::HEXTET . ':){1,4}(?::' . self::HEXTET . '){1,3}'
        . '|(?:' . self::HEXTET . ':){1,3}(?::' . self::HEXTET . '){1,4}'
        . '|(?:' . self::HEXTET . ':){1,2}(?::' . self::HEXTET . '){1,5}'
        . '|' . self::HEXTET . ':(?::' . self::HEXTET . '){1,6}'
        . '|:(?:(?::' . self::HEXTET . '){1,7}|:)'
        . '|(?:' . self::HEXTET . ':){6}' . self::IPV4
        . '|(?:' . self::HEXTET . ':){1,5}:(?:' . self::HEXTET . ':){0,4}' . self::IPV4
        . '|::(?:' . self::HEXTET . ':){0,5}' . self::IPV4 . ')';

    private const HOUR = '(?:[01][0-9]|2[0-3])';

    private const HOUR_MINUTE = self::HOUR . ':[0-5][0-9]';

    private const FRACTION = '(?:\.[0-9]++)?';

    private const OFFSET = '[+\-]' . self::HOUR . ':[0-5][0-9]';

    private function __construct()
    {
    }
}
