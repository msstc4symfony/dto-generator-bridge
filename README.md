# dto-generator-bridge

Symfony Validator constraints and Symfony Serializer attributes for the DTOs that
[`msstc4php/dto-generator`](https://github.com/msstc4php/dto-generator) generates from OpenAPI 3.1.

> **Status:** in development. Done: discovery and settings (B1) and Symfony Validator constraints (B2).
> Serializer attributes (B3), the version matrix (B4) and the bundle (B5) are ahead.

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

From the schema of each property and of the schema its `$ref` points to (both apply, as in JSON Schema 2020-12):

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

## Requirements

- PHP >= 7.4 (the bridge runs inside the generator)
- `msstc4php/dto-generator` ^1.0

## Development

```bash
COMPOSER=composer-local.json composer install   # tools plus the sibling ../../msstc4php/dto-generator
make check
make test
```

The design lives in `docs/specs/2026-10-02-bridge-symfony-design.md`.

## License

MIT.
