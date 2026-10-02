# dto-generator-bridge — этап B2: constraints Symfony Validator. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans.

**Goal:** свойства и классы сгенерированных DTO получают constraints Symfony Validator по таблице §5.1 спецификации.

**Spec:** `docs/specs/2026-10-02-bridge-symfony-design.md` §4 (открытый вопрос о `$ref`), §5.1–§5.6.

## Global Constraints
PHP ≥ 7.4 (профиль `php74` bundle-standard); только SPI ядра; MSI 100 %; ядро — отдельная итерация со своим ревью (`~/PhpstormProjects/msstc4php/dto-generator`, ветка `feat/contract-schema-references`).

## Решения
- **Ядро: `Contract\SchemaReferences`.** Аддитивное изменение SPI (минор):
  - `final class SchemaReferences` (обёртка над `SchemaGraph`): `resolve(Schema): Schema` следует по `$ref` (цепочкам тоже) до схемы без `$ref`; неразрешимая ссылка или цикл — исходная схема.
  - `PropertyContext::references()` и `ClassContext::references()`; конструкторы получают необязательный последний параметр (по умолчанию — без графа: `resolve()` возвращает схему как есть), `Enrich` передаёт настоящий граф.
- **Мост: `Validator\ValidatorEnricher`** (`PropertyEnricher`), регистрируется `SymfonyExtension`, если validator включён (`Settings::validator()`: `true`, или `null` и пакет `symfony/validator` установлен — проверяется при первом свойстве, т.к. `InstalledPackages` есть только в контексте).
  - Схема свойства для ограничений — `references()->resolve(schema)`; `NotNull` решается по IR (`required` и не nullable-тип).
  - Таблица §5.1 разнесена по небольшим классам: `StringRules`, `NumberRules`, `CollectionRules`, `FormatRules`, `EnumRules` — каждый `(Schema, Draft) → list<AttributeModel>`; `Valid`/`All` — в `ValidatorEnricher` (рекурсия по `items`/`additionalProperties`).
  - Группы (§5.2): `x-validator-groups` свойства → класса (через `owner()` и схему класса недоступна — решение: только свойство и `Settings::groups()`; групп класса в B2 нет) → `extensionConfig.symfony.groups`; к явным группам добавляется `Default`, если нет `x-validator-groups-exclusive: true`.
  - `x-validator-skip: true` на свойстве — без constraints.
  - Версии (§5.4): `SymfonyVersion::resolve(..., 'symfony/validator')`; ниже 5.4 — warning и ничего; constraint, которого нет в версии, — warning и пропуск (в B2 таких нет: всё из таблицы есть в 5.4 — кроме `Uuid` v7/v8, см. §5.6, решается в B4).
  - Аннотации (§5.5): `metadata: annotations` + validator ≥ 7.0 → strict: error, иначе warning, без constraints; 5.4/6.4 без `doctrine/annotations` в lock → warning.
  - PHP 8.0 и `All` (вложенный `new`): ядро само отбрасывает такой атрибут с диагностикой (§6.1 ядра) — мост не дублирует.
  - Импорт `ImportAlias('Symfony\Component\Validator\Constraints', 'Assert')`.
- **Тесты:** unit на каждую строку таблицы (через `AttributeModel` → строка), интеграция с реальным генератором ядра (golden-фрагмент для 8.2 и 7.4). Реальный Validator — в B4.

## Tasks
1. Ядро: `SchemaReferences`, контексты, `Enrich`; тесты; ревью; слияние в `main` ядра.
2. Мост: `ValidatorEnricher` и правила; регистрация; тесты; ревью.
3. Документация обоих репозиториев.

## Решения при выполнении
- Ruling: `Choice` ставится, если тип свойства — не PHP-enum (на 8.1+ значения ограничивает тип); ядро не принимает дробные enum, поэтому ветка `strict: false` убрана.
- Ruling: `Valid` решается по схеме (свойства или композиция), а не по типу IR — `date-time` тоже класс (`DateTimeImmutable`), а объект без свойств ядро делает массивом.
- Ruling: `NotNull` — по `isRequired()`: ядро делает nullable-свойство необязательным.
- Ruling: вложенный `new` внутри `All` ядро пишет полным именем (`new \Symfony\…\Length`), а не через алиас `Assert` — код верен; алиас для вложенных классов — кандидат на улучшение ядра.
- Ruling: группы класса (`x-validator-groups` на классе) не входят в B2: `PropertyContext` не даёт схему класса-владельца без дополнительного контракта.
- Ядро: `Contract\SchemaReferences` (минор, слито в `main` ядра, ревью medium: approve with comments, всё закрыто).

## Ревью high (CR-001…CR-019) — исправления
- CR-001/011: `Validator\Pattern` — экранирует только неэкранированный `/`, `\u` → `\x{}`, проверяет компиляцию; неверный `\u` отклоняется сам (PCRE2 в PHP 7.4 принимает его как литерал).
- CR-002: `Valid` элементов поднимается на свойство с любой глубины.
- CR-003: на 8.0 (атрибуты без `new`) мост сам пропускает `All` с warning.
- CR-004: целый `const` на `float` → `1.0`; плюс найдено при исправлении: `const` на PHP-enum сравнивается с case enum'а.
- CR-005/006: текст про аннотации зависит от поддержки атрибутов; без версии в lock/конфиге для аннотаций предполагается 6.4 (`SymfonyVersion::LATEST_READING_ANNOTATIONS`).
- CR-007: ключевые слова — только для своего вида значения; union/`mixed` — warning.
- CR-008: `Valid` на композиции — только если ветка описывает объект.
- CR-009: `enum` рядом с `$ref` на enum — warning.
- CR-010: warnings на неверные `x-validator-groups` и ключевые слова; повторы групп схлопываются.
- CR-012: unit-тесты `Pattern`, `Keywords`, `ConstraintSpec`; регрессии в `ValidatorEnricherTest`.
- CR-013: Ruling — ключевые слова рядом с `$ref` и цели действуют вместе (AND), а не «свои важнее»: так требует 2020-12; стоимость ошибки — лишь более строгая проверка.
- CR-014…017: типизация `FORMATS`, `KEYWORDS_OF`; длинные строки; порядок числовых ограничений (включительные, затем исключающие); `Constraint` → `ConstraintSpec`, `NotNull` ставит `ValidatorEnricher` (без флага в `build()`).
- CR-018: диагностики решения — на корне документа (`/api.yaml#`), а не на случайном свойстве. Ядро складывает одинаковые сообщения в одном месте; решение всё равно принимается один раз — иначе каждый документ получил бы свой warning.
- CR-019: deptrac — `TypeAlias` перечисляет namespace'ы.
