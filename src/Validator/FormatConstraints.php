<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Formats;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use Msstc4Symfony\DtoGeneratorBridge\Keywords;

/**
 * The constraints of the formats of a string, by the name the generator reads a format as. A Symfony constraint where
 * one checks the format as specs use it, a Regex otherwise; one that rejects what APIs send (Url, which takes no
 * `urn:`; Currency, which takes no `usd`; CssColor for `hex-color` without `#`) is not used.
 *
 * @internal
 */
final class FormatConstraints
{
    public const PHONE = 'phone';

    /** @var list<non-empty-string> the formats `strict` checks with a library; of() writes PhoneNumber for each */
    public const STRICT_FORMATS = [self::PHONE];

    public const PHONE_PACKAGE = 'odolbeau/phone-number-bundle';

    /** The first release whose PhoneNumber is an attribute. */
    public const PHONE_PACKAGE_MINIMUM = '3.5';

    /**
     * Format → the constraint that checks it, and the option it needs.
     *
     * @var array<non-empty-string, array{non-empty-string, ?non-empty-string, string|bool|null}>
     */
    private const CONSTRAINTS = [
        'email' => ['Email', 'mode', 'html5'],
        'ip' => ['Ip', 'version', 'all'],
        'ipv4' => ['Ip', 'version', '4'],
        'ipv6' => ['Ip', 'version', '6'],
        'cidr' => ['Cidr', null, null],
        'ipv4-cidr' => ['Cidr', 'version', '4'],
        'ipv6-cidr' => ['Cidr', 'version', '6'],
        'hostname' => ['Hostname', 'requireTld', false],
        'uuid' => ['Uuid', null, null],
        'ulid' => ['Ulid', null, null],
        'json' => ['Json', null, null],
        'color' => ['CssColor', null, null],
        'iban' => ['Iban', null, null],
        'isbn' => ['Isbn', null, null],
        'issn' => ['Issn', null, null],
        'isin' => ['Isin', null, null],
    ];

    /**
     * Format → the Regex that checks it.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const PATTERNS = [
        'byte' => FormatPatterns::BASE64,
        'uri' => FormatPatterns::URI,
        'time' => FormatPatterns::TIME,
        'duration' => FormatPatterns::DURATION,
        'ip-or-cidr' => FormatPatterns::IP_OR_CIDR,
        'mac-address' => FormatPatterns::MAC_ADDRESS,
        'hex-color' => FormatPatterns::HEX_COLOR,
        'phone' => FormatPatterns::PHONE,
        'currency' => FormatPatterns::CURRENCY,
        'decimal' => FormatPatterns::DECIMAL,
        'int8' => FormatPatterns::INTEGER,
        'int16' => FormatPatterns::INTEGER,
        'int32' => FormatPatterns::INTEGER,
        'int64' => FormatPatterns::INTEGER,
        'integer' => FormatPatterns::INTEGER,
        'uint8' => FormatPatterns::DIGITS,
        'uint16' => FormatPatterns::DIGITS,
        'uint32' => FormatPatterns::DIGITS,
        'uint64' => FormatPatterns::DIGITS,
        'digits' => FormatPatterns::DIGITS,
        'http-method' => FormatPatterns::HTTP_METHOD,
        'mm/yyyy' => FormatPatterns::MONTH_YEAR,
    ];

    /**
     * Format → the constraint that needs symfony/intl: Bic fails when it is built without it, the others when they
     * validate.
     *
     * @var array{country: 'Country', language: 'Language', locale: 'Locale', bic: 'Bic'}
     */
    private const INTL = ['country' => 'Country', 'language' => 'Language', 'locale' => 'Locale', 'bic' => 'Bic'];

    private const INTL_PACKAGE = 'symfony/intl';

    /**
     * Format → the constraint, its option and the class constant the option takes.
     *
     * @var array{timezone: array{'Timezone', 'zone', 'DateTimeZone', 'ALL_WITH_BC'}}
     */
    private const CONSTANT_OPTIONS = [
        // The zones PHP keeps for compatibility (`Asia/Calcutta`, `US/Pacific`, `GMT`) are what APIs send too.
        'timezone' => ['Timezone', 'zone', 'DateTimeZone', 'ALL_WITH_BC'],
    ];

    private InstalledPackages $packages;

    private Diagnostics $diagnostics;

    private FormatChecks $formatChecks;

    private bool $subschema;

    /**
     * @param bool $subschema whether the format is of a subschema of `not`, `if` or `oneOf`, where JSON Schema reads an
     *                        unchecked format as matching any value: `not` would reject every one
     */
    public function __construct(InstalledPackages $packages, Diagnostics $diagnostics, FormatChecks $formatChecks, bool $subschema)
    {
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
        $this->formatChecks = $formatChecks;
        $this->subschema = $subschema;
    }

    /**
     * Whether the bridge has a constraint for the format, of a string or of an integer.
     */
    public static function known(string $format): bool
    {
        return self::checks($format) || KindConstraints::rangesIntegersOf($format);
    }

    /**
     * Whether a string of the format gets a constraint, which would reject a value of another type.
     */
    public static function checks(string $format): bool
    {
        return isset(self::CONSTRAINTS[$format]) || isset(self::PATTERNS[$format]) || isset(self::INTL[$format]) || isset(self::CONSTANT_OPTIONS[$format]);
    }

    /**
     * Whether the config may set the format to `strict`.
     */
    public static function hasStrictCheck(string $format): bool
    {
        return in_array($format, self::STRICT_FORMATS, true);
    }

    /**
     * Only a string is checked; a format the config maps to a class gives the property that type, and the class
     * validates itself.
     *
     * @return list<ConstraintSpec>
     */
    public function of(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if (!$value instanceof ScalarType) {
            return [];
        }

        if ($value->kind() !== 'string') {
            $this->warnAboutUncheckedFormatsOf($keywords, $value->kind(), $at);

            return [];
        }

        $constraints = [];
        foreach ($keywords->formats() as $format) {
            if (!$this->formatChecks->allows($format)) {
                $this->warnInSubschema($format, $at);
            } elseif ($this->formatChecks->isStrict($format)) {
                $constraints[] = ConstraintSpec::phoneNumber();
            } elseif (isset(self::CONSTRAINTS[$format])) {
                [$name, $option, $setting] = self::CONSTRAINTS[$format];
                $constraints[] = new ConstraintSpec($name, $option === null ? [] : [AttributeArgument::named($option, ArgumentValue::literal($setting))]);
            } elseif (isset(self::PATTERNS[$format])) {
                $constraints[] = new ConstraintSpec('Regex', [AttributeArgument::named('pattern', ArgumentValue::literal(self::PATTERNS[$format]))]);
            } elseif (isset(self::INTL[$format])) {
                array_push($constraints, ...$this->intl($format, $at));
            } elseif (isset(self::CONSTANT_OPTIONS[$format])) {
                [$name, $option, $class, $constant] = self::CONSTANT_OPTIONS[$format];
                $constraints[] = new ConstraintSpec($name, [AttributeArgument::named($option, ArgumentValue::constant($constant, ClassName::fromFqcn($class)))]);
            } else {
                $this->warnInSubschema($format, $at);
            }
        }

        return $constraints;
    }

    /**
     * The subschema reports itself unchecked rather than match what it should not.
     */
    private function warnInSubschema(string $format, SchemaLocation $at): void
    {
        if ($this->subschema) {
            $this->diagnostics->warning(sprintf('"format: %s" is not checked.', $format), $at);
        }
    }

    /**
     * A format of strings matches every number or boolean, as JSON Schema has it; another the bridge does not check
     * for the value (`not: {format: timestamp}` of an integer) leaves the subschema unchecked.
     *
     * @param 'int'|'float'|'bool' $kind
     */
    private function warnAboutUncheckedFormatsOf(Keywords $keywords, string $kind, SchemaLocation $at): void
    {
        foreach ($this->subschema ? $keywords->formats() : [] as $format) {
            $ofStrings = Formats::isKnown($format, 'string') && !Formats::isKnown($format, 'integer') && !Formats::isKnown($format, 'number');
            $ranged = $kind === 'int' && KindConstraints::rangesIntegersOf($format) && $this->formatChecks->allows($format);
            if (!$ofStrings && !$ranged) {
                $this->warnInSubschema($format, $at);
            }
        }
    }

    /**
     * @param key-of<self::INTL> $format
     *
     * @return list<ConstraintSpec>
     */
    private function intl(string $format, SchemaLocation $at): array
    {
        if ($this->packages->has(self::INTL_PACKAGE)) {
            return [new ConstraintSpec(self::INTL[$format])];
        }

        $this->diagnostics->warning(sprintf('"format: %s" is checked with the data of %s, which the project does not install; it is not checked.', $format, self::INTL_PACKAGE), $at);

        return [];
    }
}
