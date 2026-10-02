# dto-generator-bridge-symfony — база знаний

- Спецификация: `docs/specs/2026-10-02-bridge-symfony-design.md`; планы этапов — `docs/plans/`.
- Пакет `msstc4symfony/dto-generator-bridge`, namespace `Msstc4Symfony\DtoGeneratorBridge`; репозиторий живёт в `~/PhpstormProjects/msstc4symfony/` рядом с остальными Symfony-бандлами.
- Соответствует `bundle-standard` ≥ v1.9.0 с профилем `php74`: шаблоны `templates/php74/` (PHPStan 70400, PHPUnit 9.6, Rector PHP_74, CS-Fixer без запятых после параметров), задание `minimal` CI само идёт на PHP 7.4 по профилю из `composer.json`; в `checks.yml` одна запись `symfony-versions`. Проверка: `php ../bundle-standard/bin/verify-standard.php .`.
- Ядро — `msstc4php/dto-generator ^1.0`: в `composer.json`/`composer-ci.json` из VCS GitHub, локально — `COMPOSER=composer-local.json composer install` (path `../../msstc4php/dto-generator`, версия 1.0.0). Его SPI — `src/Contract`, IR — `src/Domain/Model`.

## Архитектура (B1)
- `SymfonyExtension` (`name()` = `symfony`) — точка входа; объявлен в `extra.dto-generator.extensions` нашего `composer.json`, ядро находит его само.
- `Settings::fromConfig()` — секция `extensionConfig.symfony`; ошибки — `InvalidArgumentException` со всеми проблемами, ядро показывает её как «Extension "symfony" failed to register: …» на `dto-generator.yaml#`.
- `SymfonyVersion` — `major.minor`; `resolve(settings, packages, component)`: конфиг → версия компонента из lock → старшая из `symfony/validator`/`symfony/serializer` → `LATEST` (7.4). `MINIMUM` = 5.4.
- deptrac: `Bridge` → только `CoreSpi` (Contract, Domain\{Model,Schema,Diagnostic,Target,Shared}) и `TypeAlias` (импортированный `JsonValue`).

## Известные особенности
- Песочница не пишет `.git/config`: автор коммитов задаётся переменными `GIT_AUTHOR_*`/`GIT_COMMITTER_*` (имя и почта — из `git -C ../../msstc4php/dto-generator config`).
- PHP 7.4 локально: копия `src`, `tests`, `phpunit.xml.dist` и `composer.json` с path-репозиторием на `/work/msstc4php/dto-generator` и `config.platform.php 7.4.33`; установка в `composer:2` **без** `--ignore-platform-req=php` (иначе берутся версии для PHP 8 с типизированными константами), тесты в `php:7.4-cli` с `-v ~/PhpstormProjects:/work`.
- Infection по шаблону стандарта гоняет только набор `unit`: интеграционные тесты мутантов не убивают.
- deptrac принимает импортированные `@phpstan-import-type` псевдонимы за классы нашего namespace — для них слой `TypeAlias`.
- YAML читает `version: 6.4` как число; версия принимается только строкой.
