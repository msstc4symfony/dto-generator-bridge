# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [Unreleased]

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

[Unreleased]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/msstc4symfony/dto-generator-bridge/releases/tag/v1.0.0
