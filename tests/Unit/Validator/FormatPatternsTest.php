<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Validator\FormatPatterns;
use PHPUnit\Framework\TestCase;

/**
 * Each pattern with the JIT and without: PHP caches a compiled pattern with its JIT setting, and the ignored S
 * modifier makes another cache entry. The backtracking limit is PHP's default, which php.ini may raise.
 */
final class FormatPatternsTest extends TestCase
{
    /** @var array{string|false, string|false} */
    private array $settings;

    protected function setUp(): void
    {
        $this->settings = [ini_get('pcre.jit'), ini_get('pcre.backtrack_limit')];
        ini_set('pcre.backtrack_limit', '1000000');
    }

    protected function tearDown(): void
    {
        ini_set('pcre.jit', (string) $this->settings[0]);
        ini_set('pcre.backtrack_limit', (string) $this->settings[1]);
    }

    /**
     * A repeated group would exhaust PCRE's JIT stack or backtracking limit on a large payload.
     */
    public function testChecksBase64OfAnySizeAndOnlyItsAlphabetAndPadding(): void
    {
        foreach ($this->withAndWithoutJit(FormatPatterns::BASE64) as $setting => $regex) {
            $large = [];
            foreach ([1, 8] as $megabytes) {
                $large[$megabytes] = base64_encode(str_repeat("\xFB\xEF\xBE", $megabytes * 349526));
                self::assertSame(1, $this->match($regex, $large[$megabytes]), sprintf('%d MB, pcre.jit=%s', $megabytes, $setting));
            }

            foreach (['', 'QUJD', 'QUI=', 'QQ==', 'a+/9', 'QUJ', '=', '=='] as $valid) {
                self::assertSame(1, $this->match($regex, $valid), $valid);
            }

            foreach (['QU*D', 'QQ==QQ==', 'QQ===', "QUJD\n", 'QU JD', 'QU-_'] as $invalid) {
                self::assertSame(0, $this->match($regex, $invalid), $invalid);
            }

            self::assertSame(0, $this->match($regex, $large[8] . '*'), '8 MB and "*", pcre.jit=' . $setting);
            self::assertSame(0, $this->match($regex, substr($large[8], 0, 4) . '=' . $large[8]), '"=" inside 8 MB, pcre.jit=' . $setting);
        }
    }

    /**
     * RFC 3986 `URI` (JSON Schema `uri`).
     */
    public function testMatchesAbsoluteUris(): void
    {
        $valid = [
            'http://example.com', 'https://user:pw@example.com:8080/a/b?c=d&e#f', 'urn:isbn:0451450523', 'mailto:a@b.example',
            'file:///etc/hosts', 'tel:+1-816-555-1212', 'news:comp.lang.php', 'http://[::1]:80/', 'http://[v1.fe80::a+en1]/',
            'http://[V1.x]/', 'ldap://[2001:db8::7]/c=GB?objectClass?one', 'http://a/%20b', 'http://a/b?c/d?e#f/g?h', 'foo:',
            'x://', 'x:///', 'a+b-c.d:x', "http://a/!$&'()*+,;=:@~", 'HTTP://EXAMPLE.COM', 'http://a:/', 'a://h:', 'a:?', 'a:#',
            'a://%41@h%2e/?%41#%41', 'a:%41/b',
        ];
        $invalid = [
            '', '//example.com', '/path', 'path', 'example.com', '1http://a', ':foo', 'http://a b', 'http://a/%zz', 'http://a/%2',
            'http://a#b#c', "http://a/\u{e9}", "http://a\n", 'http://a:b:c', 'http://[::1', 'http://a"b', 'http://a<b>', 'http://a\b',
            'http://a/{b}', 'http://a|b', 'h_t://a', 'http://a^b', 'http://a`b', 'a:?%2', 'a:#%2', 'a://%2@h', 'a://h%4', 'a://h/[',
            'a://[]/', 'a://[v.x]/', 'a://[::1]x', 'a://h@h@h', "a:\x00",
        ];

        foreach ($this->withAndWithoutJit(FormatPatterns::URI) as $setting => $regex) {
            foreach ($valid as $uri) {
                self::assertSame(1, $this->match($regex, $uri), $uri . ', pcre.jit=' . $setting);
            }

            foreach ($invalid as $uri) {
                self::assertSame(0, $this->match($regex, $uri), $uri . ', pcre.jit=' . $setting);
            }

            $large = 'http://a/' . str_repeat('b/', 2097152) . '?' . str_repeat('c', 4194304);
            self::assertSame(1, $this->match($regex, $large), '8 MB, pcre.jit=' . $setting);
            self::assertSame(0, $this->match($regex, $large . ' '), '8 MB and " ", pcre.jit=' . $setting);
        }
    }

    /**
     * The group of a percent-encoded octet repeats once per octet: past about 500 000 of them with the JIT and a
     * million without, preg_match() fails, which Symfony's RegexValidator reports as a violation. The authority's host
     * is read once, like the other components.
     *
     * @dataProvider octetLimits
     */
    public function testFailsPastTheOctetsTheBacktrackingLimitAllows(string $setting, int $passes, int $fails): void
    {
        ini_set('pcre.jit', $setting);
        $regex = $setting === '1' ? FormatPatterns::URI : FormatPatterns::URI . 'S';

        foreach (['http://%s', 'http://%s@h', 'http://h/%s', 'a:%s', 'a:?%s'] as $form) {
            self::assertSame(1, $this->match($regex, sprintf($form, str_repeat('%20', $passes))), $form);
            self::assertFalse($this->match($regex, sprintf($form, str_repeat('%20', $fails))), $form);
        }
    }

    /**
     * @return iterable<string, array{'0'|'1', positive-int, positive-int}>
     */
    public static function octetLimits(): iterable
    {
        yield 'with the JIT' => ['1', 450000, 550000];
        yield 'without the JIT' => ['0', 950000, 1050000];
    }

    /**
     * RFC 3339 `full-time` (JSON Schema `time`): the offset is required.
     */
    public function testMatchesFullTimes(): void
    {
        $valid = [
            '08:30:06Z', '08:30:06z', '23:59:60Z', '23:59:60.5z', '23:59:60+00:00', '23:59:60-00:00', '08:30:06.283185Z',
            '08:30:06+01:00', '08:30:06-08:00', '08:30:06-00:00', '00:00:00+23:59', '15:59:60-08:00', '00:59:60+01:00', '12:29:60+05:30', '03:17:60-05:45',
        ];
        $invalid = [
            '', '8:30:06Z', '08:30:06', '24:00:00Z', '08:60:06Z', '08:30:61Z', '08:30:06+24:00', '08:30:06+01:60', '08:30:06.Z',
            '08:30Z', '08:30:06 Z', "08:30:06Z\n", '08:30:06+0100', 'T08:30:06Z', "\u{660}8:30:06Z", '08:30:06+01:00Z',
            '22:59:60Z', '23:58:60Z', '12:00:60+00:00', '12:00:60-00:00', '12:17:60+01:00', '23:59:60+01:60',
        ];

        foreach ($this->withAndWithoutJit(FormatPatterns::TIME) as $setting => $regex) {
            foreach ($valid as $time) {
                self::assertSame(1, $this->match($regex, $time), $time . ', pcre.jit=' . $setting);
            }

            foreach ($invalid as $time) {
                self::assertSame(0, $this->match($regex, $time), $time . ', pcre.jit=' . $setting);
            }
        }
    }

    /**
     * Values real APIs send, which must pass, and values the format excludes.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function values(): iterable
    {
        yield 'phone' => [FormatPatterns::PHONE, ['(800) 555-1234', '555-555-5555', '+15017122661', '+1 555 1234 ext. 12', '555-1234 x5', '555 1234;ext=5', "+7\u{00A0}(495)\u{00A0}123\u{2013}45\u{2013}67", '123.456.7890', '0049/30/123456'], ['call me', '+', '()', '', "555\n", '555-1234 x']];
        yield 'currency' => [FormatPatterns::CURRENCY, ['usd', 'USD', 'Eur'], ['US', 'usdd', 'U$D', "usd\n"]];
        yield 'decimal' => [FormatPatterns::DECIMAL, ['1', '-1.50', '+2', '1e3', '1.5E-7', '.5', '1.'], ['1,5', 'abc', '', '.', 'e3', '1e', '--1', ' 1']];
        yield 'integer' => [FormatPatterns::INTEGER, ['0', '-12', '+7', '18446744073709551615'], ['1.0', '', '-', '1e3', ' 1']];
        yield 'digits' => [FormatPatterns::DIGITS, ['0', '4111111111111111', '007'], ['-1', '', '1 2', 'x1']];
        yield 'duration' => [FormatPatterns::DURATION, ['P1Y', 'PT1H', 'P1W', 'P1Y2M3DT4H5M6.5S', 'P3D', 'PT0,5S', 'PT36H'], ['P', 'PT', 'P1YT', '1Y', 'P1H', 'P1W2D', '-P1D', 'PT0.5H', 'p1d']];
        yield 'MAC address' => [FormatPatterns::MAC_ADDRESS, ['00:1a:2B:3c:4d:5e', '00-1a-2b-3c-4d-5e', '001a.2b3c.4d5e', '001a2b3c4d5e'], ['00:1a:2b', 'zz:1a:2b:3c:4d:5e', '001a2b3c4d5', '00:1a:2b:3c:4d:5e:6f']];
        yield 'hex colour' => [FormatPatterns::HEX_COLOR, ['#fff', 'fff', '#FF0000', 'ff0000', '#ff000080', 'ff00'], ['#ff', 'ff000', '#ggg', 'red', '##fff']];
        yield 'HTTP method' => [FormatPatterns::HTTP_METHOD, ['GET', 'get', 'POST', 'PROPFIND', 'M-SEARCH'], ['', 'GET POST', 'GET/', "GET\n", 'GÉT']];
        yield 'month and year' => [FormatPatterns::MONTH_YEAR, ['06/2022', '12/1999'], ['13/2022', '6/2022', '00/2022', '06/22', '06-2022']];
    }

    /**
     * @dataProvider values
     *
     * @param list<string> $valid
     * @param list<string> $invalid
     */
    public function testMatchesTheValuesOfItsFormat(string $pattern, array $valid, array $invalid): void
    {
        foreach ($this->withAndWithoutJit($pattern) as $setting => $regex) {
            foreach ($valid as $value) {
                self::assertSame(1, $this->match($regex, $value), $value . ', pcre.jit=' . $setting);
            }

            foreach ($invalid as $value) {
                self::assertSame(0, $this->match($regex, $value), $value . ', pcre.jit=' . $setting);
            }
        }
    }

    /**
     * Read as UTF-8 for its no-break space and en dash: a byte that is no UTF-8 fails the match, which Symfony's Regex
     * reports as a violation.
     */
    public function testFailsAPhoneNumberThatIsNoUtf8(): void
    {
        self::assertFalse($this->match(FormatPatterns::PHONE, "\xFF555"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unbounded(): iterable
    {
        yield 'phone' => [FormatPatterns::PHONE, '5 '];
        yield 'decimal' => [FormatPatterns::DECIMAL, '9'];
        yield 'integer' => [FormatPatterns::INTEGER, '9'];
        yield 'digits' => [FormatPatterns::DIGITS, '9'];
        yield 'duration' => [FormatPatterns::DURATION, '9'];
        yield 'HTTP method' => [FormatPatterns::HTTP_METHOD, 'A'];
    }

    /**
     * Only character classes repeat, so a value of megabytes neither exhausts the JIT stack nor the backtracking
     * limit, whether it matches or fails at its very end.
     *
     * @dataProvider unbounded
     */
    public function testMatchesAValueOfAnySize(string $pattern, string $unit): void
    {
        $prefix = $pattern === FormatPatterns::DURATION ? 'PT' : '';
        $suffix = $pattern === FormatPatterns::DURATION ? 'S' : '';
        foreach ($this->withAndWithoutJit($pattern) as $setting => $regex) {
            foreach ([1, 8] as $megabytes) {
                $large = $prefix . str_repeat($unit, intdiv($megabytes * 1048576, strlen($unit))) . '1' . $suffix;
                self::assertSame(1, $this->match($regex, $large), sprintf('%d MB, pcre.jit=%s', $megabytes, $setting));
                self::assertSame(0, $this->match($regex, $large . "\x01"), sprintf('%d MB and a control character, pcre.jit=%s', $megabytes, $setting));
            }
        }
    }

    /**
     * @return iterable<'0'|'1', string> the JIT setting, set while its pattern is in use
     */
    private function withAndWithoutJit(string $pattern): iterable
    {
        foreach (['1' => $pattern, '0' => $pattern . 'S'] as $setting => $regex) {
            ini_set('pcre.jit', (string) $setting);

            yield (string) $setting => $regex;
        }
    }

    /**
     * preg_match() itself rather than PHPUnit's regex assertions, which do not tell a failed match (false) from none.
     *
     * @return 0|1|false
     */
    private function match(string $regex, string $subject)
    {
        return preg_match($regex, $subject);
    }
}
