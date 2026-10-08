# dto-generator-bridge

Symfony Validator constraints and Symfony Serializer attributes for the DTOs that
[`msstc4php/dto-generator`](https://github.com/msstc4php/dto-generator) generates from OpenAPI 3.1.

> **Status:** in development. Done: discovery and settings (B1), Symfony Validator constraints (B2), Serializer
> attributes (B3), the version matrix (B4: Symfony 5.4, 6.4, 7.4 and 8 in CI), the Symfony bundle (B5) and the
> first release with the generator's Docker image shipping the bridge (B6).

## Installation

```bash
composer require --dev msstc4symfony/dto-generator-bridge
```

The generator finds the bridge through `extra.dto-generator.extensions` — no configuration is needed.

## Configuration

`dto-generator.yaml`:

```yaml
extensionConfig:
  symfony:
    validator: auto      # auto | true | false — auto: when symfony/validator is installed
    serializer: auto     # auto | true | false — auto: when symfony/serializer is installed
    version: auto        # auto | '6.4' … — quote it: YAML reads 6.4 as a number
    groups: []           # validation groups for every constraint
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
| an object or a collection of objects | `Valid` |
| constraints of `items` / `additionalProperties` | `All` (not on PHP 8.0, whose attributes allow no `new`) |

`x-validator-groups: [api]` sets the groups (plus `Default`, unless `x-validator-groups-exclusive: true`);
`x-validator-skip: true` leaves a property alone. On PHP 7.4 the constraints are annotations, which
Symfony Validator 7 no longer reads — the bridge reports that instead of writing them.

Keywords of one kind of value (`minLength`, `minimum`, `minItems`…) are written only for a property of that type; for a
value of several types, a pattern PHP cannot compile or a malformed keyword the bridge warns and writes nothing.

## Serializer attributes

| Schema | Attribute |
|---|---|
| a wire name the PHP property does not have (`first_name` → `$firstName`) | `SerializedName('first_name')` |
| a discriminated base | `DiscriminatorMap(typeProperty: …, mapping: […])` on the class |
| `format: date` (also of list or map items, or behind a nullable union) | `Context` with the `Y-m-d` date format |
| `x-serializer-groups: [api]` | `Groups(['api'])` |
| `x-serializer-ignore: true` | `Ignore` |
| `properties` beside an `additionalProperties` schema | `Ignore` on `$additionalProperties`, with a warning |

`x-serializer-skip: true` leaves a property alone. Before Symfony 6.4 the attributes come from
`Symfony\Component\Serializer\Annotation`, from 6.4 from `Symfony\Component\Serializer\Attribute`.

Notes:

- `SerializedName` is written only where the wire name differs from the PHP name. An application with a global name
  converter (such as `camel_case_to_snake_case`) renames the other properties too.
- `x-serializer-ignore` on a required property leaves the constructor without its argument, so denormalizing fails;
  the bridge warns about it.
- Symfony Serializer cannot spread a map over the keys of its object: it would carry `$additionalProperties`, the
  properties a schema does not declare, as one key `"additionalProperties"`. The bridge ignores that property, with a
  warning, so undeclared keys are dropped when reading and not written. The validator checks each value with
  `All`/`Valid`, which covers the values a DTO is built with in code; keys of a JSON input never reach it. The bridge's
  `x-validator-*` and `x-serializer-*` keys for `$additionalProperties` go on the `additionalProperties` schema:
  `x-serializer-ignore: true` there confirms the choice without the warning, while `x-serializer-skip: true` brings the
  single key back.
- `date-time` gets no format: Symfony's RFC 3339 default fits it. From Serializer 8.1, which deprecates reading other
  forms such as fractions of a second, the bridge asks for the loose parser instead. A property attribute wins over the
  context of the call, so a `datetime_format` passed to `deserialize()` does not apply to those properties;
  `x-serializer-skip` turns this off. `readOnly` and `writeOnly` have no single attribute.

## Symfony bundle

The generator needs no bundle. In a Symfony application, `DtoGeneratorBundle` adds a console command and an optional
check on cache warmup. With the bridge installed as a dev dependency (`composer require --dev`), register the bundle
for the environments that have it:

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
and gives the same output and exit codes as the generator's own `generate` command. A relative `config` or `--config`
is resolved against the project directory, not the current one; an empty `--config` keeps the bundle's. The warmup
check never writes files and never fails the warmup: whatever goes wrong becomes a warning in the log.

## Requirements

- PHP >= 7.4 (the bridge runs inside the generator)
- `msstc4php/dto-generator` ^1.1

## Development

```bash
COMPOSER=composer-local.json composer install   # tools plus the sibling ../../msstc4php/dto-generator
make check
make test
tests/Integration/Symfony/run-matrix.sh '5.4.*'   # the integration suite on one Symfony line, in Docker
```

The design lives in `docs/specs/2026-10-02-bridge-symfony-design.md`.

## License

MIT.
