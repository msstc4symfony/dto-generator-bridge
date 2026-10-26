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

    private InstalledPackages $packages;

    private Diagnostics $diagnostics;

    public function __construct(InstalledPackages $packages, Diagnostics $diagnostics)
    {
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Whether a string of the format gets a constraint, which would reject a value of another type.
     */
    public static function checks(string $format): bool
    {
        return isset(self::CONSTRAINTS[$format]) || isset(self::PATTERNS[$format]) || isset(self::INTL[$format]) || $format === 'timezone';
    }

    /**
     * Only a string is checked; a format the config maps to a class gives the property that type, and the class
     * validates itself.
     *
     * @return list<ConstraintSpec>
     */
    public function of(Keywords $keywords, TypeModel $value, SchemaLocation $at): array
    {
        if (!$value instanceof ScalarType || $value->kind() !== 'string') {
            return [];
        }

        $constraints = [];
        foreach ($keywords->formats() as $format) {
            if (isset(self::CONSTRAINTS[$format])) {
                [$name, $option, $setting] = self::CONSTRAINTS[$format];
                $constraints[] = new ConstraintSpec($name, $option === null ? [] : [AttributeArgument::named($option, ArgumentValue::literal($setting))]);
            } elseif (isset(self::PATTERNS[$format])) {
                $constraints[] = new ConstraintSpec('Regex', [AttributeArgument::named('pattern', ArgumentValue::literal(self::PATTERNS[$format]))]);
            } elseif (isset(self::INTL[$format])) {
                array_push($constraints, ...$this->intl($format, $at));
            } elseif ($format === 'timezone') {
                // The zones PHP keeps for compatibility (`Asia/Calcutta`, `US/Pacific`, `GMT`) are what APIs send too.
                $constraints[] = new ConstraintSpec('Timezone', [AttributeArgument::named('zone', ArgumentValue::constant('ALL_WITH_BC', ClassName::fromFqcn('DateTimeZone')))]);
            }
        }

        return $constraints;
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
