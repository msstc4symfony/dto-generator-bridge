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
  objects and collection items, `prefixItems` and `unevaluatedItems`, and `dependentRequired`, `propertyNames`,
  `contains`, `patternProperties`, `if`/`then`/`else`, `not` and `dependentSchemas` through the runtime's own
  constraints — [the full table](#validator-constraints).
- **Serializer attributes** for wire names, discriminator maps, date formats, groups and ignored properties —
  [the full table](#serializer-attributes).
- **Version-aware output** for Symfony 5.4, 6.4, 7.x and 8.x: the right namespaces, arguments and fallbacks for each,
  and annotations when the DTOs target PHP 7.4 (Symfony 6.4 and older).
- **No configuration:** the generator discovers the bridge when it is installed; `x-validator-*` and `x-serializer-*`
  keys fine-tune single properties.
- **Undeclared properties over the wire:** an opt-in normalizer spreads `$additionalProperties` over the keys of its
  object and reads undeclared keys back into it — [details](#undeclared-properties-additionalproperties). It ships in
  the small [runtime package](https://github.com/msstc4symfony/dto-generator-bridge-runtime), the only one production needs.
- **An optional Symfony bundle** with a `dto-generator:generate` console command, a check on cache warmup and the
  normalizer wired into the application's serializer.

## Requirements

- PHP >= 7.4 (the bridge runs inside the generator, which supports PHP 7.4).
- `msstc4php/dto-generator` ^1.6 and `msstc4symfony/dto-generator-bridge-runtime` ^1.2 (both installed with the
  bridge).
- In the application that uses the DTOs: `symfony/validator` and/or `symfony/serializer` 5.4, 6.4, 7.x or 8.x. By
  default the bridge writes constraints and attributes only for the components it finds in `composer.lock`.
- For the bundle: `symfony/framework-bundle` 5.4, 6.4, 7.x or 8.x.

## Installation

The packages are not on Packagist yet, so register the GitHub repositories first:

```bash
composer config repositories.msstc4php-dto-generator vcs https://github.com/msstc4php/dto-generator
composer config repositories.msstc4symfony-dto-generator-bridge vcs https://github.com/msstc4symfony/dto-generator-bridge
composer config repositories.msstc4symfony-dto-generator-bridge-runtime vcs https://github.com/msstc4symfony/dto-generator-bridge-runtime
composer require --dev msstc4symfony/dto-generator-bridge
```

This installs the generator and the runtime as well. With `additionalProperties: spread`, require the runtime for
production too: `composer require msstc4symfony/dto-generator-bridge-runtime`.

The generator finds the bridge through `extra.dto-generator.extensions`, so no
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
| `enum` | `Choice`; on a PHP enum only when it allows fewer cases (one beside a `$ref`), with the cases |
| `const` | `IdenticalTo`, `IsNull` |
| `format`: `email`, `ipv4`, `ipv6`, `hostname`, `uuid` | `Email(mode: 'html5')`, `Ip`, `Hostname(requireTld: false)`, `Uuid` |
| `format: int32` | `Range(min: -2147483648, max: 2147483647)`, merged with `minimum` / `maximum` / `exclusive*` |
| `format: byte` | `Regex` for base64: the standard alphabet of RFC 4648, padding only at the end |
| `format: uri` | `Regex` for an RFC 3986 URI: a scheme and any of its forms (`https://…`, `urn:…`, `mailto:…`) |
| `format: time` | `Regex` for an RFC 3339 `full-time`: `08:30:06Z`, `23:59:60.5-08:00` |
| an object or a collection of objects | `Valid` |
| constraints of `items` / `additionalProperties` | `All` (not on PHP 8.0, whose attributes allow no `new`) |
| `contains`, `minContains` / `maxContains` on a list | `RuntimeAssert\Contains` (its nested constraints not on PHP 8.0) |
| `propertyNames` on a map, or on a class with `$additionalProperties` | `RuntimeAssert\Keys` (not on PHP 8.0, as it needs nested ones) |
| `dependentRequired` on a class | `RuntimeAssert\DependentRequired`, on the class |
| `patternProperties` on a map, or on a class with `$additionalProperties` | `RuntimeAssert\PatternProperty` for each pattern (its nested constraints not on PHP 8.0) |
| `patternProperties` on a class, for the declared properties whose names match | the constraints of the pattern's schema, on each of them |
| `if` / `then` / `else` on a property or the items of a collection | `RuntimeAssert\IfThenElse` (not on PHP 8.0) |
| `if` / `then` / `else` on a class | `RuntimeAssert\IfThenElse` of `Property` and `Present`, on the class (not on PHP 8.0) |
| `not` on a property or the items of a collection | `RuntimeAssert\Not` (its nested constraints not on PHP 8.0) |
| `not` on a class | `RuntimeAssert\Not` of `Property` and `Present`, on the class (its nested constraints not on PHP 8.0) |
| `dependentSchemas` on a class | `RuntimeAssert\IfThenElse` with `Present` of the property in `if`, or `Not` of it for `false`, on the class (not on PHP 8.0) |
| `prefixItems` on a list | `Collection` of the elements by index, extra and missing ones allowed (not on PHP 8.0) |
| `unevaluatedItems: false` on a list | `Count(max: …)`, the length of the longest `prefixItems` of its schema |
| an `unevaluatedItems` schema on a list without `prefixItems` | `All`, as `items` (not on PHP 8.0) |

`x-validator-groups: [api]` sets the groups of a property instead of `groups`; `Default` is added to either, unless
`x-validator-groups-exclusive: true`. `x-validator-skip: true` leaves a property alone. On PHP 7.4 the constraints are
annotations, which Symfony Validator 7 no longer reads: the bridge writes none and reports it, as an error under the
default `target.strict: true` (a warning otherwise). Annotations also need `doctrine/annotations` in the project; the
bridge warns when it is missing. With `target.metadata: none` the bridge writes nothing.

Keywords of one kind of value (`minLength`, `minimum`, `minItems`…) are written only for a property of that type; for a
value of several types, a pattern PHP cannot compile or a malformed keyword the bridge warns and writes nothing. The
same holds for `format: int32` (integers) and `format: byte`, `uri`, `time` (strings): a value of several types warns
about them too, next to the generator's own warning about the format. The other formats (`email`, `uuid`…) are
skipped on such a value without a warning. A mixed `enum` such as `[low, 1]` gives a `Choice` with both values; its
property may hold a string or an int, so `minLength` or `minimum` beside it get that warning.

The `int32` bounds compete with the schema's: the strictest bound on each side wins, so `minimum: 0` gives
`Range(min: 0, max: 2147483647)`, and an exclusive bound gives a pair such as `GreaterThanOrEqual(value: -2147483648)`
and `LessThan(value: 10)`. `int64` needs no constraint (it is PHP's int on 64-bit platforms).

`uri` and `time` get a `Regex` rather than Symfony's `Url` and `Time`: `Url` takes only the schemes it lists, each with
an authority, so `urn:` and `mailto:` URIs fail, and `Time` takes neither fractions of a second nor the offset that
`full-time` requires. A `uri` must be absolute: a relative reference such as `/pets/1` is a violation
(`uri-reference`, `iri` and `iri-reference` get no constraint). Percent-encoding must be complete (`%2` fails), and
non-ASCII characters are rejected; IP literals in brackets are checked for their alphabet only. The pattern repeats a
group only per percent-encoded octet, so a value of any size passes unless it holds more than about 500 000 of them
(a million without PCRE's JIT; `pcre.backtrack_limit` at its default), which reads as a violation. A `time` takes a
leap second only as `23:59:60` in UTC (`Z`, `+00:00`, `-00:00`) and at minute 59 with an offset of whole hours; the
hour, and the minute beside an offset such as `+05:30`, are not checked, since that needs arithmetic, so such values
pass where JSON Schema's test suite expects a failure.

`RuntimeAssert` is `Msstc4Symfony\DtoGeneratorBridge\Runtime\Constraints` of
[`msstc4symfony/dto-generator-bridge-runtime`](https://github.com/msstc4symfony/dto-generator-bridge-runtime#constraints),
which the application needs in production then; without it in `composer.lock` the bridge warns and writes none of
them. The bridge claims these keywords (unless `validator: false`) and marks each occurrence it checks or warns
about, such as one in a member of a `oneOf`/`anyOf` with several members, which is not checked. The generator still
calls the others unsupported: those written beside a `$ref` to a class (write them in the class's schema), those of
a property or a class with `x-validator-skip`, and all of them when the bridge writes no Validator constraints (no
`symfony/validator`, `target.metadata: none`). In a schema with `x-php-type` they are not checked, without a
warning, as in the generator. A `minContains` or `maxContains` without `contains` in the same schema gives a warning:
JSON Schema ignores it there.
They follow JSON Schema with these limits:

- **Subschemas** (of `contains`, `propertyNames`, `patternProperties`, `if`, `then`, `else`, `not`, `prefixItems`,
  `unevaluatedItems`, and of the properties in a class's branches, its `not` and its `dependentSchemas`) must be flat: `type`, `format`, `enum`, `const`, the string and number keywords,
  annotations and `x-` keys. A `$ref` is followed (with the generator 1.6): the keywords of every schema on the way are
  checked together, their annotations and metadata (`example`, `readOnly`, `xml`…) aside, as long as each schema is
  flat and no two of them hold the same keyword; otherwise, or when it does not resolve (the generator warns about
  that too), the `$ref` counts as a keyword the bridge cannot check. A branch of a class's condition, its `not` or a
  schema of its `dependentSchemas` written as a `$ref` itself is not followed: only the property schemas inside it are. `properties`, `items`, `allOf` and the
  like, a `type` narrower than the value's
  PHP type (`type: integer` in a list of strings), a keyword the bridge cannot turn into a constraint (a `pattern`
  PHP cannot run) or a value that is no string, number, boolean or PHP enum and not of any type (an object, a list)
  give a warning, and the keyword is not checked, rather than checked in part. A schema of annotations only accepts
  every value; one whose `type` or `enum` allows `null` alone gets `IsNull`. On a PHP enum only `type`, `const` and
  `enum` apply, compared with its cases (on a target without PHP enums the value is its plain string or int, which
  the other keywords check too). On a value of any type (an untyped property, the values of a map without an
  `additionalProperties` schema) `type` gives a `Type` constraint, and the string and number keywords need exactly
  one `type` among `string`, `integer`, `number` and `boolean`, as Symfony would check them on other kinds of values
  too; a `const` or `enum` with a whole number needs a `type` that admits no float (`integer`, not `number`), as PHP
  tells `0` from `0.0`. Where `null` is a
  value (an element, a map value), a schema that rejects it (a `type`, `const` or `enum` without it) gets `NotNull`
  beside its constraints, which let `null` pass; `if`, `then` and `else` are the exception, below.
- **`contains`** counts the elements that pass the constraints of its schema, each checked on its own;
  `minContains` (1 by default) and `maxContains` of the same schema bound the count, and `minContains: 0` alone
  checks nothing. Each `contains` (in `allOf` branches, or behind a `$ref`) must hold with its own bounds.
- **`propertyNames`** checks each key of a map, or of `$additionalProperties`. The names of declared properties are
  checked while generating, by `type`, `pattern`, `minLength`, `maxLength`, `const` and `enum` (`false` rejects
  every name), whatever the bridge writes for the Validator (also with `x-validator-skip`, `validator: false` or no
  `symfony/validator`), as it is a contradiction in the schema: a name it rejects gives a warning, as that property
  can never be set. A variant its base lists without a `$ref` back to it is checked against the base's
  `propertyNames` too. On a class without `$additionalProperties` it gives a warning (with
  `additionalProperties: false` nothing is left to check). A violation is reported at `labels[key]` with the key as
  its value, the same path as the violations of the value under that key.
- **A property several members of an `allOf` declare** gets the constraints of every declaration, as a value must
  satisfy them all (the generator types it by one); a declaration with `x-validator-skip` or groups of its own is left
  out.
- **A property of the parent declared again** in a child's schema, narrowing only its values, stays the parent's (the
  generator lets no child narrow its type); the child checks the constraints of its declaration that the parent's
  lack with `Property` (a missing value passes them), and with `Present` that it is set when the child's schema
  requires what the parent's leaves optional and no declaration admits `null`. A parent's declaration in groups of its
  own does not count.
- **`dependentRequired`** reads a property as present when it is not `null`: like the rest of the DTO, it cannot tell
  a missing key from `null`, so a pair whose first property has a default is not checked (it is always present),
  with a warning. A name that is not a property of the class gives a warning and leaves its pair out. A class that
  extends another (`allOf` with a `$ref`) checks pairs with inherited properties too; a pair a parent's schema lists
  is left to the parent, which Symfony checks on the child as well. Only when the parent is not generated in the same
  run are its properties unknown: a pair with one of them gives a warning. Each missing property is one violation at
  its own path.
- **`patternProperties`** checks the value under each key a pattern matches: with `PatternProperty` on a map and on
  `$additionalProperties`, reported at `labels[key]`; on a declared property whose wire name matches, the bridge
  matches it while generating and writes the constraints of the pattern's schema on the property itself, where a
  missing value (`null`) passes them. A pattern of a child's schema that matches a property the class inherits is
  checked on the child with `Property`: the property belongs to the parent, which checks only the patterns of the
  schemas that declare it. A class
  without `$additionalProperties` of its own gives a warning: the undeclared properties the patterns would check are
  not kept. Beside an `additionalProperties` schema it warns too: the
  generated type gives that schema's type to every undeclared property, including those the patterns match, which
  JSON Schema exempts from it. A pattern PHP cannot run gives a warning and is left out; `additionalProperties:
  false` still leaves the keys outside the patterns unchecked.
- **`if`**, **`then`** and **`else`** become one `IfThenElse` per schema that holds `if` with `then` or `else` (an
  `if` alone, or a `then` without `if`, has no effect and is not written). The value matches `if` when its
  constraints find nothing. `null` is not checked, also where it is a value (a nullable element passes whatever the
  branches say). On a class, the branches may hold `type: object` (always true there), `properties` (each checked by
  `Property` with the constraints of its flat schema; a missing property passes, as in JSON Schema) and `required`
  (`Present`), by wire names the bridge turns into PHP names. Names that are no property of the class, a property
  with a default under `properties` or in the `required` of `if` (the DTO cannot tell it from a missing one), any
  other keyword in a branch, or a branch that is no schema (`if: false` included) give a warning, and the condition
  is not checked. As with `dependentRequired`, a condition of a parent's schema is left to the parent, and one in the
  child's schema may name inherited properties. A class constraint an ancestor's class already writes, the same in
  the same groups, is left to the ancestor, so a violation is reported once.
- **`not`** becomes `Not` of the constraints of its schema: a value they find nothing wrong with is a violation. A
  schema that checks nothing (`{}`, `true`, annotations only) leaves no value but a missing one, and `not: false`
  forbids nothing. As with `if`, `null` is not checked: it stands for a missing value, so `not` of a schema that allows
  `null` alone gives a warning, and `not: {type: [string, 'null']}` does not forbid `null`. On a class, `not` holds
  `properties` and `required` as the branches of a condition do, with the same warnings about names, defaults and
  inherited properties; one of nothing but the object in a parent's schema is left to the parent.
- **`dependentSchemas`** is checked on classes only: for each property it names, the schema (`properties` and
  `required`, as in a condition) applies when the property is set; a `false` schema forbids the property. A name or a
  property of its schema that is no property of the class, a named property with a default (always set), and a value
  that is not a class give a warning; a dependency of a parent's schema is left to the parent.
- **`prefixItems`** checks each element of the prefix by its index with Symfony's `Collection`, which lets shorter and
  longer lists through; under `x-validator-groups` each element's constraints go into an `Optional` of those groups, as
  `Collection` would put them in `Default` only. The generated type gives `items` to every element, the prefix's too, so
  `prefixItems` beside any `items` gives a warning and is not checked. An element's schema is that of a value of any
  type: its string and number keywords need one `type`, and a `type` without `null` gets `NotNull`.
- **`unevaluatedItems`** sees what its own schema and the subschemas it reaches (`allOf`, `$ref`, the one member of a
  nullable `oneOf`/`anyOf`) evaluate, not a sibling in `allOf`, as in JSON Schema 2020-12. An `items` there, or an
  `unevaluatedItems` there that is not `false`, evaluates every element, so it has no effect; `true` or `{}` never has.
  Beside `contains`, `if` or a union of several values, which evaluate elements too, it gives a warning. `false` allows
  no element beyond the longest `prefixItems` there (`Count`); a schema checks every element (`All`) when there is no
  `prefixItems`, and gives a warning beside one. `unevaluatedProperties` is not checked: a DTO drops the undeclared
  properties it would judge, so the generator's warning stays.
- `x-validator-skip` and `x-validator-groups` on a class's schema apply to `DependentRequired`, to the conditions of the
  class, its `not` and `dependentSchemas`, to the `Keys` and `PatternProperty` of `$additionalProperties` and to the
  constraints the patterns give declared properties; those on the schema of a property or of `additionalProperties`
  apply to its own constraints only.

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

- **The normalizer runs in your application,** in every environment. It lives in
  [`msstc4symfony/dto-generator-bridge-runtime`](https://github.com/msstc4symfony/dto-generator-bridge-runtime), which the bridge requires: install it with
  `composer require` and register its `DtoGeneratorRuntimeBundle` for all environments, so the bridge and the
  generator can stay development dependencies. Without the normalizer the DTOs carry neither `Ignore` nor a
  normalizer, and Symfony writes and reads one literal key `"additionalProperties"`.
- It needs PHP 8.0+ and `symfony/serializer` 5.4, 6.4, 7.x or 8.x; the generated DTOs must use attributes
  (`target.metadata`), and a target that writes annotations (PHP 7.4) is an error.
- How keys are declared and spread, its priority in the serializer, the setup without a bundle and the limits of
  some Serializer releases: see [the runtime's README](https://github.com/msstc4symfony/dto-generator-bridge-runtime#undeclared-properties).

## Read and write models

With the generator's `dto.readWriteModels: split` (generator 1.3), a schema with `readOnly` or `writeOnly` properties
gives two classes, such as `AccountRead` (responses, no `writeOnly` properties) and `AccountWrite` (requests, no
`readOnly` ones). The bridge treats them as any other class: each gets the constraints and attributes of its own
properties, so a `writeOnly` password is validated in `AccountWrite` only, and a discriminated base maps the variants
of its own model. Deserialize a request body into the write model (`#[MapRequestPayload] AccountWrite $account`) and
serialize the read model in the response.

## Symfony bundle

The generator needs no bundle. In a Symfony application, `DtoGeneratorBundle` adds a console command, an optional
check on cache warmup and, on PHP 8.0+, [`AdditionalPropertiesNormalizer`](#undeclared-properties-additionalproperties)
in the serializer. With the bridge installed as a dev dependency (`composer require --dev`), register the bundle
for the environments that have it, and the runtime's bundle for all of them when the DTOs use
`additionalProperties: spread` (both register the same normalizer, so they may run together):

```php
// config/bundles.php
return [
    // ...
    Msstc4Symfony\DtoGeneratorBridge\Runtime\Bundle\DtoGeneratorRuntimeBundle::class => ['all' => true],
    Msstc4Symfony\DtoGeneratorBridge\Bundle\DtoGeneratorBundle::class => ['dev' => true, 'test' => true],
];
```

The warmup check in production needs the bridge in `require` and the bundle registered with `['all' => true]`.

```yaml
# config/packages/dto_generator.yaml
dto_generator:
  config: '%kernel.project_dir%/dto-generator.yaml'   # the default
  mode: committed          # committed: the DTOs live in your sources; build: see "Building the DTOs with the container"
  check_on_warmup: false   # true: warn in the log on cache warmup when the DTOs are out of date
```

`bin/console dto-generator:generate [--config=...] [--check] [--dry-run] [--format=text|json] [--refresh-remote]` takes
the same options and gives the same output and exit codes as the generator's own `generate` command, with the same
process settings: it raises a `memory_limit` below 1G to 1G (or takes `DTO_GENERATOR_MEMORY_LIMIT`), moves PHP errors to
stderr when `display_errors` shows them (`On` or `stdout`), and ends a PHP fatal error with exit code `2` (on PHP 7.4,
an error raised inside a function keeps PHP's own `255`). A relative `config` or `--config` is resolved against the
project directory, not the current one; an empty `--config` keeps the bundle's. The warmup check never writes files,
never changes the process and never fails the warmup: whatever goes wrong becomes a warning in the log.

The command is meant for a `bin/console` process of its own. Run inside a long-lived process (`CommandTester` or
`ApplicationTester` in a test suite, Messenger's `RunCommandMessage`, `Application::run()` from a controller), it leaves
that process with `display_errors=stderr` (when it showed errors), a `memory_limit` of at least 1G (or
`DTO_GENERATOR_MEMORY_LIMIT`, which may also lower it) and a shutdown handler that ends a later fatal error with exit
code `2` (`255` on PHP 7.4 for an error inside a function).

`DTO_GENERATOR_MEMORY_LIMIT` must be a real environment variable of the process (`DTO_GENERATOR_MEMORY_LIMIT=2G
bin/console dto-generator:generate`): Symfony's Dotenv does not call `putenv()` (since 5.0), so a value in `.env` does
not reach the generator.

### Building the DTOs with the container

The recommended setup commits the generated DTOs: reviews show their changes, IDEs and PHPStan see them, and production
needs neither the generator nor the bridge. When the DTOs should not live in the repository, `mode: build` generates
them whenever Symfony builds its container (`cache:clear`, `cache:warmup`, the first request in `dev`):

```yaml
# config/packages/dto_generator.yaml
dto_generator:
  mode: build
```

- The bridge, and with it the generator, must then be in `require` and its bundle registered for all environments.
- Each source's `outputDir` moves to `%kernel.build_dir%/dto-generator/`, at its path relative to the config's
  directory (`src/Dto` becomes `var/cache/prod/dto-generator/src/Dto`); the project's sources are never written.
  An `outputDir` outside the config's directory is an error.
- The bundle loads the classes from there with an autoloader of its own, so Composer's `--classmap-authoritative`
  does not hide them. The generation runs before the container reads controller signatures, so
  `#[MapRequestPayload] Pet $pet` works.
- The config and the specifications are resources of the container: in `dev`, an edit rebuilds the container and the
  DTOs on the next request.
- No network: remote `$ref`s come from the committed `remoteRefs.cacheDir`. A container that cannot build cannot run
  `bin/console` either, so fill the cache with the generator's own CLI, from the project directory (add `--config=`
  for a config other than `dto-generator.yaml`): `vendor/bin/dto-generator generate --output-root=var/cache/dev/dto-generator`.
  It writes the DTOs there too, which the `dev` container rebuilds anyway; the point is the cache. In `dev`,
  `bin/console dto-generator:generate` writes into the build directory as well; in `prod` run only `cache:clear`.
- A generation error stops the container build with the generator's diagnostics. Warnings go to the container's
  compiler log (`<ContainerClass>Compiler.log` in the build directory), which Symfony writes only with `debug: true`
  and not under PHPUnit: a `prod` build records none.
- The generation runs on every container build, in the process that builds it: in `dev` that may be a web request
  with its `memory_limit` and time limit, which the generator's CLI would raise. The specifications' resources only
  decide when the container is rebuilt.
- Symfony loads `src/` as services before the generation runs, so a class of your own may not extend or implement a
  generated type (such as a discriminated base); refer to the DTOs, as controllers and services usually do.
- `check_on_warmup` has nothing to compare and is an error together with `mode: build`.
- IDEs and static analysis see the classes only after a local build; point them at the build directory
  (PHPStan: `scanDirectories: [var/cache/dev/dto-generator]`). Unit tests that do not boot the kernel do not load
  them either.
- The paths of the DTOs stay the same from one deploy to the next: with `opcache.validate_timestamps=0`, reload PHP-FPM
  after a deploy, as for any other code. A long-running worker (FrankenPHP, RoadRunner) keeps the DTOs it loaded until
  it restarts.

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
