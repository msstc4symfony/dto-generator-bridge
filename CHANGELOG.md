# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [Unreleased]

### Added

- `format: uri` and `format: time` get a `Regex`: an RFC 3986 URI with a scheme (`https://…`, `urn:…`, `mailto:…`)
  and an RFC 3339 `full-time` with its offset. Symfony's `Url` and `Time` would reject valid values, so these formats
  were not checked before.
- `dependentRequired`, `propertyNames` and `contains` (with `minContains` and `maxContains`) get the constraints
  `DependentRequired`, `Keys` and `Contains` of `msstc4symfony/dto-generator-bridge-runtime` 1.1. The bridge claims
  these keywords (`ExtensionRegistry::claimKeywords()` of the generator 1.4), so the generator no longer calls them
  unsupported, and warns itself where it cannot check one: a `contains` schema with `$ref` or `properties`, elements
  that are objects or PHP enums, a `dependentRequired` name that is not a property of the class, a trigger with a
  default, `propertyNames` on a class that keeps no undeclared properties, one of these keywords in a member of a
  `oneOf`/`anyOf` of several values, `minContains` or `maxContains` without `contains` in the same schema, a project
  without the runtime. The
  generator still warns about the occurrences the bridge does not reach (beside a `$ref` to a class, a property or
  class with `x-validator-skip`).
- `patternProperties` and `if`/`then`/`else` get the constraints `PatternProperty` and `IfThenElse` (with `Property`
  and `Present` on a class) of the runtime 1.1, and the bridge claims them too. On a map and on `$additionalProperties`
  each pattern is checked on the matching keys; a declared property whose wire name matches gets the constraints of
  the pattern's schema directly. Conditions are checked on a property, on the items of a collection and on a class,
  whose branches may hold `properties` and `required`. The bridge warns where it cannot check one: a class without
  `$additionalProperties`, a pattern PHP cannot run, an `additionalProperties` schema that also types the matching
  keys, a branch with other keywords, a name that is not a property of the class, a property with a default.
- The subschemas of `contains`, `propertyNames` and the new keywords may describe PHP enums (`type`, `const`, `enum`)
  and values of any type (a `Type` constraint for their `type`); one that allows `null` alone gets `IsNull`.

### Changed

- The bridge requires `msstc4php/dto-generator` ^1.4 and `msstc4symfony/dto-generator-bridge-runtime` ^1.1.
- Regenerated DTOs reject what `format: uri` and `format: time` never allowed but went unchecked: a relative reference
  such as `/pets/1` (use `format: uri-reference`, which stays unchecked) and a time without its offset (`08:30:06`).
- Regenerated DTOs with `dependentRequired`, `propertyNames`, `contains`, `patternProperties` or `if`/`then`/`else`
  reject what these keywords forbid.
- The warning about `propertyNames` on a class without `$additionalProperties` names the cause: the class does not
  declare its own.
- An `enum` that allows fewer cases than the PHP enum of its property (one beside a `$ref` to the enum) gets a
  `Choice` of those cases instead of the warning that the narrowing is not checked.

### Fixed

- A composite constraint inside `All` (the `All` of a list of lists) takes the groups of its property; Symfony fixes
  the groups of a nested composite when it is built, so it stayed in `Default` and validating another group skipped
  it.

## [1.2.0] - 2026-10-12

### Added

- The README describes the read and write models of the generator's `dto.readWriteModels: split`; the integration
  suite reads and writes through them with each Symfony line.
- `extensionConfig.symfony.additionalProperties: spread` carries the undeclared properties of a DTO through Symfony
  Serializer. The bridge writes `#[AdditionalProperties]` on `$additionalProperties` instead of `Ignore`, and
  `Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalPropertiesNormalizer` writes the map's entries as keys of the
  object and reads undeclared keys back into it, typed by the `additionalProperties` schema. It runs in the
  application (PHP 8.0+, Symfony 5.4 to 8) and ships in `msstc4symfony/dto-generator-bridge-runtime`, which the
  bridge requires: production needs only that package and its `DtoGeneratorRuntimeBundle`. Keys are named by the serializer's name converter; a discriminated base class or interface is read
  as the variant its data selects. A target that writes annotations cannot carry the marker: that is an error.
  `ignore`, the default, keeps the previous output.
- `dto_generator.mode: build` generates the DTOs while Symfony builds its container, into
  `%kernel.build_dir%/dto-generator/` instead of the sources, and loads them with an autoloader of its own; the config
  and the specifications become resources of the container, remote `$ref`s come from the committed cache only, and
  `dto-generator:generate` writes into the build directory too. `committed`, the default, keeps the previous
  behaviour. Requires the generator 1.3 (`Generate\Input::withOutputRoot()`).
- The bundle registers the normalizer in the application's default serializer when it runs on PHP 8.0+, through the
  runtime's `Normalizers`: the same definition as the runtime's bundle, so the two may be enabled together.

### Changed

- Requires `msstc4php/dto-generator` ^1.3 and `msstc4symfony/dto-generator-bridge-runtime` ^1.0. The normalizer of
  `additionalProperties: spread`, unreleased so far, ships in the runtime package under the same class names.

## [1.1.0] - 2026-10-09

### Added

- `format: int32` gives `Range(min: -2147483648, max: 2147483647)`, merged with `minimum`, `maximum` and the
  exclusive keywords: the strictest bound on each side wins, and two inclusive bounds stay one `Range`. `int64` gives
  none.
- `format: byte` gives a `Regex` for base64: the standard alphabet of RFC 4648, `=` padding only at the end. The
  length is not checked to be a multiple of four (`QUJ`, a lone `=` or `==` pass): a pattern that counts groups of
  four fails on large payloads, which Symfony reports as a violation. The pattern is linear and possessive, so it
  holds payloads of any size, valid or not.
- A value of several types with `format: int32` or `format: byte` gets a warning ("…it is not checked") instead of the
  constraint.

### Changed

- Requires `msstc4php/dto-generator` ^1.2.
- `dto-generator:generate` of the bundle prepares the process as the generator's CLI does
  (`DtoGenerator::prepareProcess()`): `memory_limit` of at least 1G or `DTO_GENERATOR_MEMORY_LIMIT`, PHP errors moved
  to stderr when `display_errors` shows them, exit code `2` on a later fatal error (`255` on PHP 7.4 for an error
  inside a function). The warmup check leaves the process alone.
- Existing DTOs with `format: int32` get the `Range` above when they are generated again.
- Existing DTOs with `format: byte` get the `Regex` above: base64url (`-`, `_`), MIME base64 with line breaks,
  padding inside the value and a trailing newline are now violations (unpadded base64 still passes).
- With generator 1.2, an `enum` mixing strings and integers is no error any more: the property gets a `Choice` with
  both kinds of values, and `minLength`, `minimum` and the other keywords of one kind beside it give the warning for a
  value of several types ("…it is not checked").
- With generator 1.2, a property typed as a union of inline `oneOf`/`anyOf` members, or a list of them, gets `Valid`:
  the generator turns those members into classes.

### Documentation

- README: denormalizing straight into a discriminated variant with another class's discriminator value throws
  `\InvalidArgumentException` from its constructor (generator 1.2), so `#[MapRequestPayload]` (Symfony 6.3+) with a
  variant type answers 500; map the payload to the base.
- README: the bundle command belongs in a process of its own (it changes `display_errors`, `memory_limit` and the
  fatal-error exit code), and `DTO_GENERATOR_MEMORY_LIMIT` must be a real environment variable, not a `.env` entry.

## [1.0.2] - 2026-10-09

### Documentation

- README: features, requirements per component, installation from the GitHub repositories, a table of the
  `x-validator-*` and `x-serializer-*` keys.
- This changelog.

### Internal

- CI checks the Symfony-bound code and the PHP 8-only calls in a separate job.

## [1.0.1] - 2026-10-08

### Fixed

- The `$additionalProperties` property of a class with `properties` beside an `additionalProperties` schema gets the
  constraints of that schema on each value, inside `All`, instead of on the map itself.
- The serializer ignores `$additionalProperties` (`Ignore`), with a warning: Symfony Serializer would read and write it
  as a single key named `additionalProperties`. `x-serializer-ignore: true` on the `additionalProperties` schema
  confirms the choice without the warning.

### Changed

- Requires `msstc4php/dto-generator` ^1.1.

## [1.0.0] - 2026-10-06

First release.

- Symfony Validator constraints from the property schemas: `NotNull`, `Length`, `Regex`, ranges, `DivisibleBy`,
  `Count`, `Unique`, `Choice`, `IdenticalTo`/`IsNull`, format constraints, `Valid` and `All`.
- Symfony Serializer attributes: `SerializedName`, `DiscriminatorMap`, date `Context`, `Groups`, `Ignore`.
- `x-validator-*` and `x-serializer-*` keys; settings in `extensionConfig.symfony`.
- Attributes and namespaces per Symfony version (5.4, 6.4, 7.x, 8.x), read per component from `composer.lock`;
  annotations for PHP 7.4 targets.
- `DtoGeneratorBundle`: the `dto-generator:generate` console command and an optional check on cache warmup.

[Unreleased]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/msstc4symfony/dto-generator-bridge/releases/tag/v1.0.0
