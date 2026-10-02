# dto-generator-bridge-symfony — база знаний

- Спецификация: `docs/specs/2026-10-02-bridge-symfony-design.md`; планы этапов — `docs/plans/`.
- Ядро — соседний репозиторий `../dto-generator` (path-репозиторий, симлинк `vendor/msstc4php/dto-generator`); его SPI — `src/Contract`, IR — `src/Domain/Model`.

## Архитектура (B1)
- `SymfonyExtension` (`name()` = `symfony`) — точка входа; объявлен в `extra.dto-generator.extensions` нашего `composer.json`, ядро находит его само.
- `Settings::fromConfig()` — секция `extensionConfig.symfony`; ошибки — `InvalidArgumentException` со всеми проблемами, ядро показывает её как «Extension "symfony" failed to register: …» на `dto-generator.yaml#`.
- `SymfonyVersion` — `major.minor`; `resolve(settings, packages, component)`: конфиг → версия компонента из lock → старшая из `symfony/validator`/`symfony/serializer` → `LATEST` (7.4). `MINIMUM` = 5.4.
- deptrac: `Bridge` → только `CoreSpi` (Contract, Domain\{Model,Schema,Diagnostic,Target,Shared}) и `TypeAlias` (импортированный `JsonValue`).

## Известные особенности
- Песочница не пишет `.git/config`: автор коммитов задаётся переменными `GIT_AUTHOR_*`/`GIT_COMMITTER_*` (имя и почта — из `git -C ../dto-generator config`).
- `make test-74`/`lint-74` монтируют родительский каталог: симлинк ядра указывает на `../dto-generator`.
- deptrac принимает импортированные `@phpstan-import-type` псевдонимы за классы нашего namespace — для них слой `TypeAlias`.
- YAML читает `version: 6.4` как число; версия принимается только строкой.
