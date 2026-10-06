# dto-generator-bridge-symfony — база знаний

- Спецификация: `docs/specs/2026-10-02-bridge-symfony-design.md`; планы этапов — `docs/plans/`.
- Пакет `msstc4symfony/dto-generator-bridge`, namespace `Msstc4Symfony\DtoGeneratorBridge`; репозиторий живёт в `~/PhpstormProjects/msstc4symfony/` рядом с остальными Symfony-бандлами.
- Соответствует `bundle-standard` (линия сброшена пользователем 2026-10-04: `checks.yml` → `@v1.0.0`) с профилем `php74`: шаблоны `templates/php74/` (PHPStan 70400, PHPUnit 9.6, Rector PHP_74, CS-Fixer без запятых после параметров), задание `minimal` CI само идёт на PHP 7.4 по профилю из `composer.json`; в `checks.yml` четыре записи `symfony-versions` (7.4 первой: ячейка prefer-lowest берёт первую, а ранние 5.4/6.4 не чисты на PHP 8.4). Проверка: `php ../bundle-standard/bin/verify-standard.php .`.
- Ядро — `msstc4php/dto-generator ^1.0`: в `composer.json`/`composer-ci.json` из VCS GitHub, локально — `COMPOSER=composer-local.json composer install` (path `../../msstc4php/dto-generator`, версия 1.0.0). Его SPI — `src/Contract`, IR — `src/Domain/Model`.

## Архитектура (B1)
- `SymfonyExtension` (`name()` = `symfony`) — точка входа; объявлен в `extra.dto-generator.extensions` нашего `composer.json`, ядро находит его само.
- `Settings::fromConfig()` — секция `extensionConfig.symfony`; ошибки — `InvalidArgumentException` со всеми проблемами, ядро показывает её как «Extension "symfony" failed to register: …» на `dto-generator.yaml#`.
- `SymfonyVersion` — `major.minor`; `resolve(settings, packages, component)`: конфиг → версия компонента из lock → старшая из `symfony/validator`/`symfony/serializer` → `LATEST` (8.1, проверено матрицей). `MINIMUM` = 5.4.
- deptrac: `Bridge` → только `CoreSpi` (Contract, Domain\{Model,Schema,Diagnostic,Target,Shared}) и `TypeAlias` (импортированный `JsonValue`).

## Validator (B2)
- `Validator\ValidatorEnricher` (регистрируется `SymfonyExtension`): решает «писать ли» один раз на запуск по первому свойству (предупреждения — на корне его документа): `validator: false`/`auto` без пакета → нет; `true` без пакета → warning; версия < 5.4 → warning, нет; `metadata: annotations` + validator ≥ 7.0 → strict: error / warning, нет; 5.4/6.4 без `doctrine/annotations` → warning.
- `ConstraintBuilder` — таблица §5.1 в её порядке, ключевые слова — только для своего вида типа (`KEYWORDS_OF`); `Keywords` — значения всех звеньев `$ref` (`chain()`) и веток `allOf`, каждое место один раз (AND: строже граница, все `pattern`, пересечение `enum`), `resolved()` — через единственную типизированную ветку `allOf`, как `TypeMapper::typed()` ядра; `KeywordReader` — числовые ключевые слова с проверкой; `Interval` — есть ли значение (целое) между границами; `ValueConstraints` — `enum`/`const` в PHP-типе свойства, case PHP-enum; `Pattern` — ECMA → PCRE (`/uD`), `UnicodeEscape` — `\u` и суррогатные пары; `ConstraintSpec` — имя + аргументы → атрибут с `ImportAlias(…Constraints, 'Assert')` или `new` внутри `All`. `NotNull` ставит `ValidatorEnricher`.
- Тесты — настоящий генератор ядра в dry-run на временном проекте с `composer.lock`; `attributesOf()` разбирает сигнатуру конструктора по запятым нулевой глубины.

## Serializer (B3)
- `Serializer\SerializerEnricher` — `ClassEnricher` + `PropertyEnricher` (один экземпляр, регистрируется дважды). `DiscriminatorMap` на классе с `discriminator()`; на свойстве: `Ignore` | `SerializedName`, `Groups`, `Context` (дата).
- `ComponentGate` (корень) — «писать ли» и версия для validator/serializer, один раз на запуск, диагностики на корне документа. `ExtensionReader` — `x-` флаги и списки групп. `Keywords` теперь в корне пакета.
- Тесты — трейт `Test\Unit\GeneratesDtos` (генератор в dry-run, `attributesOf()`, `classAttributesOf()`).

## Матрица (B4)
- `tests/Integration/Symfony/RealSymfonyTest` — сгенерированные DTO + настоящие Validator/Serializer; локально `tests/Integration/Symfony/run-matrix.sh '<версия>' [8.4-cli|8.5-cli]` (`LOWEST=1` — prefer-lowest). CI — задание `phpunit` стандарта по `symfony-versions`.
- `composer-ci.json`: Symfony-компоненты, `doctrine/annotations` ^2 (аннотации цели 7.4 на Symfony < 7; пакет заброшен — `config.audit.abandoned: report`), `phpdocumentor/reflection-docblock` ^5.6.
- PHPStan (`phpVersion 70400`) не читает Symfony 8 — тест исключён в `phpstan-baseline.neon` (`excludePaths`; `phpstan -b` перезапишет файл — вернуть блок руками), вручную: `vendor/bin/phpstan analyse -c phpstan-symfony.neon`.
- `FOREIGN_DEPRECATIONS` в `RealSymfonyTest` — список устареваний Symfony, не вызванных мостом; новое устаревание проваливает тест, его надо разобрать, а не добавлять в список не глядя.
- Infection `^0.29 || ^0.32` (console 5.4 ↔ 8); 0.32 печатает только Covered Code MSI.
- Rector с установленным `symfony/validator` превращает строки FQCN в `::class` (тестам это безопасно: `::class` не загружает класс).

## Бандл (B5)
- `src/Bundle`: `DtoGeneratorBundle`, `DependencyInjection\{DtoGeneratorExtension,Configuration}`, `Command\GenerateCommand` (обёртка над `generate` ядра, свой `ArrayInput`), `CacheWarmer\GenerationCheckWarmer`. deptrac: слой `Bundle` видит только `CoreSpi` и `CoreEntry` (`DtoGenerator`, `Generate\{Input,Mode,Output,Status}`).
- Тесты — `tests/Unit/Bundle` (настоящее ядро Symfony, `TestKernel`; кэш контейнера — свой на каждый конфиг бандла, иначе ядро берёт старый контейнер). Устаревания из `vendor/` глушатся с самого начала `setUp()`: Symfony 5.4 и старые contracts на PHP 8.4 бросают их уже при загрузке классов.
- PHPStan: `phpstan-symfony.neon` (PHP 8.4) — `src/Bundle`, `tests/Unit/Bundle`, `tests/Integration/Symfony`; в основном они исключены. Ни `make check`, ни CI стандарта этот конфиг не запускают — перед слиянием вручную: `vendor/bin/phpstan analyse -c phpstan-symfony.neon`.
- `GenerateCommand::run()` обёрнут в `DtoGenerator::guard()`: `CommandTester` и `Application` вызывают `run()` команды, а ошибка привязки входа (`--chek`) бросается внутри `Command::run()` — без обёртки Symfony отвечал бы 1, что у генератора значит «устарело».
- Сбой команды (код 2) не доходит до `ConsoleErrorEvent` и логгера FrameworkBundle — `guard()` ловит его внутри `run()`, вывод только в stderr, как у CLI ядра. Код 2 на уровне бандла тестом не покрыт: ядро не даёт надёжного способа упасть не-`ExceptionInterface`; сам `guard()` покрыт в ядре.
- Прогрев строит генератор ядра в `warmUp()` под `try`, а не в конструкторе: исключение при создании сервиса уронило бы весь `cache:warmup`.
- deptrac: слои моста — по namespace (`classNameRegex`), не по каталогу: `directory` ищет шаблон в любом месте абсолютного пути, и `src/` из пути checkout (`/usr/src/app`) смешал бы слои. Псевдоклассы `Json(Value|Scalar)` исключены из `Bridge` — это слой `TypeAlias`.
- Ядро требует абсолютный путь в `Generate\Input` (`InvalidArgumentException`), поэтому команда и прогрев разрешают `config` от каталога проекта сами; `--config` команды разрешает ядро — от `console($projectDir)`, т. е. тоже от проекта.

## Известные особенности
- Песочница не пишет `.git/config`: автор коммитов задаётся переменными `GIT_AUTHOR_*`/`GIT_COMMITTER_*` (имя и почта — из `git -C ../../msstc4php/dto-generator config`).
- PHP 7.4 локально: копия `src`, `tests`, `phpunit.xml.dist` и `composer.json` с path-репозиторием на `/work/msstc4php/dto-generator` и `config.platform.php 7.4.33`; установка в `composer:2` **без** `--ignore-platform-req=php` (иначе берутся версии для PHP 8 с типизированными константами), тесты в `php:7.4-cli` с `-v ~/PhpstormProjects:/work`.
- Infection по шаблону стандарта гоняет только набор `unit`: интеграционные тесты мутантов не убивают.
- deptrac принимает импортированные `@phpstan-import-type` псевдонимы за классы нашего namespace — для них слой `TypeAlias`.
- YAML читает `version: 6.4` как число; версия принимается только строкой.
- `Schema::keyword()` не содержит `enum`, `format`, `type`, `$ref` — у них свои методы (`enum()`, `format()`…); `Extensions::get()` бросает, если ключа нет — сначала `has()`.
- Ядро: enum на 7.4/8.0 — класс с константами, свойство `string`/`int`; дробные enum — ошибка ядра; объект без свойств — массив; вложенный `new` в атрибуте — полное имя класса, без алиаса.
- Диагностики ядра складывают одинаковые сообщения в одном месте: тест «один раз» должен использовать два документа.
- Ядро типизирует `$ref` по цели и игнорирует `enum` рядом с ним — проверяет только `Choice` моста.
- `json_encode` в тестах пишет `2.0` как `2`: для дробных значений — `JSON_PRESERVE_ZERO_FRACTION`. Строки с `\u` + hex в вводе инструментов декодируются в символ — в тестах собирать `'\\' . 'u00e9'`.
- PCRE2 в PHP 7.4 принимает `\u12` как литерал, в 8.x — ошибка; `Pattern` отклоняет такой `\u` сам.
- Ядро печатает атрибуты promoted-параметра в одну строку перед `public`, длинные — с переносом аргументов.
- Ядро: `required` у `mixed` (`{}`, `type: null`) остаётся — `null` допустим, `NotNull` нельзя. Enum из `float`/`bool` ядро в PHP-enum не превращает (warning ядра), тип остаётся скалярным.
- Ядро само предупреждает о `minimum` > `maximum` в одной схеме («no range is applied»).
- `infection.json5` — точная копия шаблона bundle-standard: игнорировать мутантов нельзя, эквивалентных убирать перестройкой кода (вынести проверку в класс с прямыми тестами).
