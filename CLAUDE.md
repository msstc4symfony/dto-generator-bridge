# CLAUDE.md

Guidance for Claude Code working in this repository. Deep references live
under `.claude/docs/`; this file stays lean.

## What this is

An extension of `msstc4php/dto-generator`: it adds Symfony Validator constraints
and Serializer attributes to the generated DTOs. Spec:
`docs/specs/2026-10-02-bridge-symfony-design.md`; stage plans: `docs/plans/`.

The bridge runs **inside the generator**, so its sources must run on **PHP 7.4**
(bundle-standard runtime profile `php74`): no PHP 8 syntax or functions — no
constructor promotion, `match`, union types, attributes, named arguments,
`str_contains()` and the like. Only the core's SPI may be used (`deptrac.yaml`).

## Common commands

All in `Makefile` (shared bundle-standard template):

- `COMPOSER=composer-local.json composer install` — local install: tools plus the
  sibling core `../../msstc4php/dto-generator` as a path repository.
- `make check`, `make fix`, `make test`, `make infection`.
- PHP 7.4 is proven in CI: the `minimal` job reads the `php74` profile from `composer.json`. Locally, run the
  suite in `php:7.4-cli` against an install of `composer.json` only.

## Conventions

- Code comments in English, only the non-obvious "why".
- Every change: tests first, `make check`, `make test`, `make infection`.
