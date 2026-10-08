# Security Policy

## Supported Versions

The package follows semantic versioning. Security fixes are released for the latest
minor on each supported major.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

Runtime requirements are tracked in `composer.json`:

- PHP >= 7.4 — the bridge runs inside the DTO generator, which supports PHP 7.4
  (bundle-standard runtime profile `php74`)
- `msstc4php/dto-generator` ^1.1
- The generated attributes target Symfony 5.4, 6.4, 7.x and 8.x

## Reporting a Vulnerability

Please report vulnerabilities privately to maxim.shamaev@gmail.com rather than
opening a public issue. You will get an answer within a week.
