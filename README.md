# DTO Generator Bridge for Symfony

![Build Status](https://github.com/msstc4symfony/dto-generator-bridge/actions/workflows/checks.yml/badge.svg?branch=main)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-787CB5?logo=php&logoColor=white)](https://php.net)
[![Symfony Versions](https://img.shields.io/badge/Symfony-5.4%20%7C%206.4%20%7C%207.x%20%7C%208.x-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-2a5ea7)](https://phpstan.org)
[![Last commit](https://img.shields.io/github/last-commit/msstc4symfony/dto-generator-bridge/main)](https://github.com/msstc4symfony/dto-generator-bridge/commits/main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Symfony Validator constraints and Symfony Serializer attributes for the DTOs that
[`msstc4php/dto-generator`](https://github.com/msstc4php/dto-generator) generates from OpenAPI 3.1.

The generator turns schemas into typed PHP classes; this extension reads the same schemas and adds what Symfony needs
to validate and (de)serialize them: `#[Assert\Length(max: 254)]` from `maxLength`, `#[SerializedName('first_name')]`
for a renamed property, `#[DiscriminatorMap]` for a `oneOf` with a discriminator, and so on. The attributes match the
Symfony versions in your `composer.lock`.

## Features

- **Validator constraints** from `required`, string, number and array keywords, `enum`, `const`, formats, nested
  objects and collection items — [the full table](#validator-constraints).
- **Serializer attributes** for wire names, discriminator maps, date formats, groups and ignored properties —
  [the full table](#serializer-attributes).
- **Version-aware output** for Symfony 5.4, 6.4, 7.x and 8.x: the right namespaces, arguments and fallbacks for each,
  and annotations when the DTOs target PHP 7.4 (Symfony 6.4 and older).
- **No configuration:** the generator discovers the bridge when it is installed; `x-validator-*` and `x-serializer-*`
  keys fine-tune single properties.
- **Undeclared properties over the wire:** an opt-in normalizer spreads `$additionalProperties` over the keys of its
  object and reads undeclared keys back into it — [details](#undeclared-properties-additionalproperties).
- **An optional Symfony bundle** with a `dto-generator:generate` console command, a check on cache warmup and the
  normalizer wired into the application's serializer.

## Requirements

- PHP >= 7.4 (the bridge runs inside the generator, which supports PHP 7.4).
- `msstc4php/dto-generator` ^1.2.
- In the application that uses the DTOs: `symfony/validator` and/or `symfony/serializer` 5.4, 6.4, 7.x or 8.x. By
  default the bridge writes constraints and attributes only for the components it finds in `composer.lock`.
- For the bundle: `symfony/framework-bundle` 5.4, 6.4, 7.x or 8.x.

## Installation

The packages are not on Packagist yet, so register both GitHub repositories first:

```bash
composer config repositories.msstc4php-dto-generator vcs https://github.com/msstc4php/dto-generator
composer config repositories.msstc4symfony-dto-generator-bridge vcs https://github.com/msstc4symfony/dto-generator-bridge
composer require --dev msstc4symfony/dto-generator-bridge
```

This installs the generator as well. The generator finds the bridge through `extra.dto-generator.extensions`, so no
configuration is needed: the next `vendor/bin/dto-generator generate` writes the attributes. See the
[generator's README](https://github.com/msstc4php/dto-generator#quick-start) for `dto-generator.yaml`.

## Configuration

`dto-generator.yaml`:

```yaml
extensionConfig:
  symfony:
    validator: auto      # auto | true | false — auto: when composer.lock has symfony/validator
    serializer: auto     # auto | true | false — auto: when composer.lock has symfony/serializer
    version: auto        # auto | '6.4' … — quote it: YAML reads 6.4 as a number
    groups: []           # validation groups for every constraint, plus Default
    additionalProperties: ignore   # ignore | spread — see "Undeclared properties"
```

The Symfony version is read per component from the project's `composer.lock`.

## Validator constraints

From the schema of each property, of the schema its `$ref` points to, of its `allOf` branches and of the one member of a
nullable `oneOf`/`anyOf` (all apply, as in JSON Schema 2020-12):

| Schema | Constraint |
|---|---|
| required | `NotNull` |
| `minLength` / `maxLength` | `Length` |
| `pattern` | `Regex` (`/…/u`) |
| `minimum` / `maximum` / `exclusive*` | `Range`, `GreaterThan(OrEqual)`, `LessThan(OrEqual)` |
| `multipleOf` | `DivisibleBy` |
| `minItems` / `maxItems`, `minProperties` / `maxProperties` | `Count` |
| `uniqueItems` | `Unique` |
| `enum`, unless the property is a PHP enum | `Choice` |
| `const` | `IdenticalTo`, `IsNull` |
| `format`: `email`, `ipv4`, `ipv6`, `hostname`, `uuid` | `Email(mode: 'html5')`, `Ip`, `Hostname(requireTld: false)`, `Uuid` |
| `format: int32` | `Range(min: -2147483648, max: 2147483647)`, merged with `minimum` / `maximum` / `exclusive*` |
| `format: byte` | `Regex` for base64: the standard alphabet of RFC 4648, padding only at the end |
| an object or a collection of objects | `Valid` |
| constraints of `items` / `additionalProperties` | `All` (not on PHP 8.0, whose attributes allow no `new`) |

`x-validator-groups: [api]` sets the groups of a property instead of `groups`; `Default` is added to either, unless
`x-validator-groups-exclusive: true`. `x-validator-skip: true` leaves a property alone. On PHP 7.4 the constraints are
annotations, which Symfony Validator 7 no longer reads: the bridge writes none and reports it, as an error under the
default `target.strict: true` (a warning otherwise). Annotations also need `doctrine/annotations` in the project; the
bridge warns when it is missing. With `target.metadata: none` the bridge writes nothing.

Keywords of one kind of value (`minLength`, `minimum`, `minItems`…) are written only for a property of that type; for a
value of several types, a pattern PHP cannot compile or a malformed keyword the bridge warns and writes nothing. The
same holds for `format: int32` (integers) and `format: byte` (strings): a value of several types warns about them too,
next to the generator's own warning about the format. The other formats (`email`, `uuid`…) are skipped on such a value
without a warning. A mixed `enum` such as `[low, 1]` gives a
`Choice` with both values; its property may hold a string or an int, so `minLength` or `minimum` beside it get that
warning.

The `int32` bounds compete with the schema's: the strictest bound on each side wins, so `minimum: 0` gives
`Range(min: 0, max: 2147483647)`, and an exclusive bound gives a pair such as `GreaterThanOrEqual(value: -2147483648)`
and `LessThan(value: 10)`. `int64` needs no constraint (it is PHP's int on 64-bit platforms). `uri` and `time` get none
on purpose: Symfony's `Url` rejects valid URIs such as `urn:` and `mailto:` ones or relative references, and its `Time`
does not take RFC 3339's `full-time` with a time zone.

`format: byte` takes the standard alphabet only: base64url (`-`, `_`), MIME base64 with line breaks, a trailing newline
and padding inside the string are rejected. The pattern checks the characters and that up to two `=` only end the
string, not that the length is a multiple of four: `QUJ`, unpadded base64 and even a lone `=` or `==` pass. A pattern
that counts groups of four exhausts PCRE's JIT stack on payloads of about a hundred kilobytes, and Symfony reports the
failed match as a violation; the pattern used is linear and possessive, so it holds payloads of any size.

## Serializer attributes

| Schema | Attribute |
|---|---|
| a wire name the PHP property does not have (`first_name` → `$firstName`) | `SerializedName('first_name')` |
| a discriminated base | `DiscriminatorMap(typeProperty: …, mapping: […])` on the class |
| `format: date` (also of list or map items, or behind a nullable union) | `Context` with the `Y-m-d` date format |
| `x-serializer-groups: [api]` | `Groups(['api'])` |
| `x-serializer-ignore: true` | `Ignore` |
| `properties` beside an `additionalProperties` schema | `Ignore` on `$additionalProperties`, with a warning; with `additionalProperties: spread`, `AdditionalProperties` instead |

`x-serializer-skip: true` leaves a property alone. Before Symfony 6.4 the attributes come from
`Symfony\Component\Serializer\Annotation`, from 6.4 from `Symfony\Component\Serializer\Attribute`.

Notes:

- `SerializedName` is written only where the wire name differs from the PHP name. An application with a global name
  converter (such as `camel_case_to_snake_case`) renames the other properties too.
- `x-serializer-ignore` on a required property leaves the constructor without its argument, so denormalizing fails;
  the bridge warns about it.
- The constructor of a discriminated variant checks its discriminator (generator 1.2). Denormalizing through the base
  and its `DiscriminatorMap` builds the class the value selects, and a variant selected by one value takes it as the
  default when the key is missing. `deserialize()` or `denormalize()` straight into a variant with another class's
  value throws `\InvalidArgumentException` from the constructor, which Symfony does not turn into a validation error;
  from Symfony 6.3, `#[MapRequestPayload] Cat $cat` with `"petType": "dog"` answers 500. Map the payload to the base
  (`Pet $pet`) instead.
- Symfony Serializer cannot spread a map over the keys of its object: it would carry `$additionalProperties`, the
  properties a schema does not declare, as one key `"additionalProperties"`. By default the bridge ignores that
  property, with a warning, so undeclared keys are dropped when reading and not written;
  [`additionalProperties: spread`](#undeclared-properties-additionalproperties) carries them instead. The validator
  checks each value with `All`/`Valid`, which covers the values a DTO is built with in code. The bridge's
  `x-validator-*` and `x-serializer-*` keys for `$additionalProperties` go on the `additionalProperties` schema:
  `x-serializer-ignore: true` there confirms the choice without the warning (and keeps `Ignore` under `spread`),
  while `x-serializer-skip: true` brings the single key back.
- `date-time` gets no format: Symfony's RFC 3339 default fits it. From Serializer 8.1, which deprecates reading other
  forms such as fractions of a second, the bridge asks for the loose parser instead. A property attribute wins over the
  context of the call, so a `datetime_format` passed to `deserialize()` does not apply to those properties;
  `x-serializer-skip` turns this off. `readOnly` and `writeOnly` have no single attribute.

## Undeclared properties (`additionalProperties`)

A schema with `properties` and an `additionalProperties` schema gets a property `$additionalProperties`: a map of the
keys the schema does not declare. To carry them through Symfony Serializer, set

```yaml
extensionConfig:
  symfony:
    additionalProperties: spread
```

The bridge then writes `#[\Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties]` on that property instead of
`Ignore`, and `AdditionalPropertiesNormalizer` does the rest at runtime:

```json
{"name": "Rex", "colour": "brown", "age": 3}
```

reads into `name: "Rex"` and `additionalProperties: {"colour": "brown", "age": 3}` (each value typed by the
`additionalProperties` schema, nested DTOs included), and writes back the same JSON.

- **The normalizer runs in your application,** in every environment: install the bridge with `composer require`, not
  `--dev`, and register the bundle with `['all' => true]` (or register the normalizer yourself, below). Without it the
  DTOs carry neither `Ignore` nor a normalizer, and Symfony writes and reads one literal key `"additionalProperties"`.
  It needs PHP 8.0+ and `symfony/serializer` 5.4, 6.4, 7.x or 8.x; the generated DTOs must use attributes
  (`target.metadata`), and a target that writes annotations (PHP 7.4) is an error.
- **With the bundle** the normalizer is in the application's default serializer as soon as `framework.serializer` is
  enabled, with its object normalizer and name converter. It is registered at the default priority 0, ahead of
  Symfony's object, date and other built-in normalizers (all of a negative priority); a normalizer of yours for the
  same DTOs needs a higher priority to win. A named serializer (`framework.serializer.named_serializers`, Symfony 7.2)
  does not get it: register one by hand with that serializer's object normalizer.
- **Without the bundle,** wrap the object normalizer and pass it the same metadata factory and name converter:

  ```php
  $names = new MetadataAwareNameConverter($classMetadataFactory);
  $objects = new ObjectNormalizer($classMetadataFactory, $names, null, $propertyTypeExtractor, new ClassDiscriminatorFromClassMetadata($classMetadataFactory));
  $serializer = new Serializer([
      new DateTimeNormalizer(),
      new ArrayDenormalizer(),
      new AdditionalPropertiesNormalizer($objects, $classMetadataFactory, $names),
      $objects,
  ], [new JsonEncoder()]);
  ```

  The wrapped normalizer must denormalize too; the Serializer is handed on to it, so it works without being in the
  chain, but the other DTOs need it there.
- **Declared keys** are the class's serialized properties, named by the name converter (`SerializedName`, a global
  converter such as `camel_case_to_snake_case`, inherited properties too), and the type property of a discriminated
  base class or interface; everything else goes into the map. A map entry named like a declared key throws
  `Symfony\Component\Serializer\Exception\UnexpectedValueException` on normalizing.
- On Symfony 5.4, PropertyInfo takes the key type `array-key` of the generated PHPDoc for a class, so a map of
  objects (spread or a declared property) cannot be read; maps of scalars work. Symfony 6.4 and newer read both.
- Serialization groups apply as usual: when the groups leave `$additionalProperties` out, nothing is spread.
- `normalize()` returns a PHP array, so two shapes come out as JSON arrays rather than objects: with
  `preserve_empty_objects`, an object whose every property is left out (`[]`, not `{}`), and an object of which only
  map entries with the keys `0`, `1`, … remain (`["a","b"]`, not `{"0":"a","1":"b"}`).

## Symfony bundle

The generator needs no bundle. In a Symfony application, `DtoGeneratorBundle` adds a console command, an optional
check on cache warmup and, on PHP 8.0+, [`AdditionalPropertiesNormalizer`](#undeclared-properties-additionalproperties)
in the serializer. With the bridge installed as a dev dependency (`composer require --dev`), register the bundle
for the environments that have it (`additionalProperties: spread` needs it in all of them, see above):

```php
// config/bundles.php
return [
    // ...
    Msstc4Symfony\DtoGeneratorBridge\Bundle\DtoGeneratorBundle::class => ['dev' => true, 'test' => true],
];
```

The warmup check in production needs the bridge in `require` and the bundle registered with `['all' => true]`.

```yaml
# config/packages/dto_generator.yaml
dto_generator:
  config: '%kernel.project_dir%/dto-generator.yaml'   # the default
  check_on_warmup: false   # true: warn in the log on cache warmup when the DTOs are out of date
```

`bin/console dto-generator:generate [--config=...] [--check] [--dry-run] [--format=text|json]` takes the same options
and gives the same output and exit codes as the generator's own `generate` command, with the same process settings: it
raises a `memory_limit` below 1G to 1G (or takes `DTO_GENERATOR_MEMORY_LIMIT`), moves PHP errors to stderr when
`display_errors` shows them (`On` or `stdout`), and ends a PHP fatal error with exit code `2` (on PHP 7.4, an error
raised inside a function keeps PHP's own `255`). A relative `config` or `--config` is
resolved against the project directory, not the current one; an empty `--config` keeps the bundle's. The warmup check
never writes files, never changes the process and never fails the warmup: whatever goes wrong becomes a warning in the
log.

The command is meant for a `bin/console` process of its own. Run inside a long-lived process (`CommandTester` or
`ApplicationTester` in a test suite, Messenger's `RunCommandMessage`, `Application::run()` from a controller), it leaves
that process with `display_errors=stderr` (when it showed errors), a `memory_limit` of at least 1G (or
`DTO_GENERATOR_MEMORY_LIMIT`, which may also lower it) and a shutdown handler that ends a later fatal error with exit
code `2` (`255` on PHP 7.4 for an error inside a function).

`DTO_GENERATOR_MEMORY_LIMIT` must be a real environment variable of the process (`DTO_GENERATOR_MEMORY_LIMIT=2G
bin/console dto-generator:generate`): Symfony's Dotenv does not call `putenv()` (since 5.0), so a value in `.env` does
not reach the generator.

## `x-` keywords

| Key | On | Effect |
|---|---|---|
| `x-validator-groups: [api]` | property | Validation groups of its constraints instead of `groups` from the config |
| `x-validator-groups-exclusive: true` | property | Leaves out the `Default` group otherwise added |
| `x-validator-skip: true` | property | No constraints |
| `x-serializer-groups: [api]` | property | `Groups(['api'])` |
| `x-serializer-ignore: true` | property | `Ignore` |
| `x-serializer-skip: true` | property | No serializer attributes |

For `$additionalProperties` the keys go on the `additionalProperties` schema. The generator's own keys
(`x-php-name`, `x-php-type`, `x-php-attributes`…) are described in its
[documentation](https://github.com/msstc4php/dto-generator/blob/main/docs/x-extensions.md).

## Development

```bash
make install-ci   # the CI profile: Symfony components, tools, the generator from GitHub
make check
make test
make infection
tests/Integration/Symfony/run-matrix.sh '5.4.*'   # one Symfony line in Docker, against a generator checkout (CORE=…)
```

CI runs the suite on Symfony 5.4, 6.4, 7.4 and 8 (and once with the lowest dependencies), PHPStan on the
Symfony-bound code, and a PHP 7.4 job with only the generator installed.

## Changelog and security

See [CHANGELOG.md](CHANGELOG.md) and [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE).
