# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [Unreleased]

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

[Unreleased]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/msstc4symfony/dto-generator-bridge/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/msstc4symfony/dto-generator-bridge/releases/tag/v1.0.0
