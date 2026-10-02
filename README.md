# dto-generator-bridge

Symfony Validator constraints and Symfony Serializer attributes for the DTOs that
[`msstc4php/dto-generator`](https://github.com/msstc4php/dto-generator) generates from OpenAPI 3.1.

> **Status:** in development. Stage B1 is done: the generator discovers the bridge, reads its
> `extensionConfig.symfony` section and detects the project's Symfony version. Constraints (B2),
> serializer attributes (B3), the version matrix (B4) and the bundle (B5) are ahead.

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
