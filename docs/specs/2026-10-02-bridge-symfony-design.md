# dto-generator-bridge-symfony — дизайн

**Дата:** 2026-10-02 (UTC)
**Статус:** черновик, составлен автономно по §12 спецификации ядра (`../dto-generator/docs/specs/2026-10-01-dto-generator-design.md`); решения, принятые без пользователя, помечены «Решение».

## 1. Цель

Расширение генератора DTO (`msstc4php/dto-generator`): к сгенерированным классам и свойствам добавляет атрибуты (или аннотации на PHP 7.4) Symfony Validator и Symfony Serializer, выведенные из стандартных ключевых слов OpenAPI 3.1 / JSON Schema и из `x-`-ключей моста. Опционально — Symfony-бандл с командой и конфигом.

### Критерии успеха
- DTO, сгенерированный с мостом, проходит `Validator::validate()` ровно тогда, когда JSON-экземпляр проходит схему (для поддержанных ключевых слов).
- `Serializer::deserialize()` JSON-экземпляра в DTO и обратная `serialize()` сохраняют имена полей схемы (wire names), даты и полиморфизм `discriminator`.
- Вывод зависит от версии Symfony потребителя (5.4, 6.4, 7.x) и от целевой версии PHP (7.4–8.5) так же, как у ядра.

### Вне рамок
- Проверки, которые Validator не выражает без кастомных constraint'ов: `if/then/else`, `dependentRequired`, `contains`, `propertyNames`, `patternProperties`.
- Нормализаторы и денормализаторы, кроме атрибутов: мост не генерирует PHP-код поведения.
- Валидация во время выполнения без Symfony.

## 2. Ограничения
- Пакет `msstc4php/dto-generator-bridge-symfony`, namespace `MSSTC4PHP\DtoGeneratorBridgeSymfony`, PHP ≥ 7.4 (работает в рантайме генератора).
- Зависимость только от SPI ядра (`MSSTC4PHP\DtoGenerator\Contract\*`, IR-модели `Domain\Model\*`, `Domain\Schema\Schema`). Классы Symfony в рантайме генератора не нужны: мост выводит их имена строками (`ClassName`), поэтому `symfony/validator` и `symfony/serializer` — только в `require-dev` и `suggest`.
- Бандл — в том же пакете (`src/Bundle`), активен, только если установлен `symfony/framework-bundle`; его PHP-минимум тот же, что у Symfony выбранной версии.
- Инструменты как у ядра: PHPStan max, CS-Fixer, Rector, deptrac, infection (MSI 100 %), golden-матрица.

## 3. Подключение

- `composer.json` моста: `extra.dto-generator.extensions: ["MSSTC4PHP\\DtoGeneratorBridgeSymfony\\SymfonyExtension"]` — обнаруживается ядром (§8 ядра) без правки конфига.
- Одно расширение `SymfonyExtension`, `name()` = `symfony`, секция `extensionConfig.symfony`:

```yaml
extensionConfig:
  symfony:
    validator: auto      # auto | true | false
    serializer: auto     # auto | true | false
    version: auto        # auto | '5.4' | '6.4' | '7.0' … — версия для выбора constraint'ов
    groups: []           # группы валидации по умолчанию для всех constraint'ов
```

- `auto` для `validator`/`serializer`: включено, если пакет `symfony/validator` / `symfony/serializer` есть в `InstalledPackages` (lock потребителя). `true` без пакета — работает (мост не зависит от пакетов), но warning «not installed».
- `version: auto`: старшая из версий `symfony/validator` и `symfony/serializer` из lock; без пакетов — `7.0`. Версия задаёт доступность constraint'ов (§5.4) и аннотаций (§5.5).
- Неизвестный ключ секции — ошибка конфига на `#/extensionConfig/symfony/<key>`.
- Мост заявляет (`claimExtensionKeys`) `x-validator-*` и `x-serializer-*`.

## 4. Входные данные

- Свойство: `PropertyContext` — `PropertyModel` (тип IR, `wireName`, required/nullable, default), исходная `Schema` свойства (ключевые слова, `x-*`), `TargetProfile`, `InstalledPackages`, `Diagnostics`.
- Класс: `ClassContext` — `ClassModel` (`discriminator`, kind, parent), `Schema`, `isInline()`.
- **Открытый вопрос к ядру (этап B2):** для свойства с `$ref` на скалярный псевдоним (`Email: {type: string, format: email}`) ограничения лежат в целевой схеме, а `PropertyContext::schema()` отдаёт схему свойства. Если ядро её не разрешает, нужен аддитивный метод контракта `PropertyContext::resolvedSchema()` (минорный релиз ядра). Решение: проверить на этапе B2 и при необходимости добавить в ядро отдельным изменением.

## 5. Validator

### 5.1 Ключевые слова → constraints

| Схема | Constraint | Примечание |
|---|---|---|
| свойство в `required`, тип не nullable | `NotNull` | Конструктор и так требует значение; constraint нужен для денормализации в существующий объект и для `mutable`. |
| `minLength`/`maxLength` | `Length(min, max)` | Один constraint на оба. |
| `pattern` | `Regex(pattern: '/…/u')` | Разделитель `/` экранируется; ECMA-конструкции, которых нет в PCRE, — warning и пропуск. |
| `minimum`/`maximum` (оба включительно) | `Range(min, max)` | |
| одна граница или `exclusiveMinimum`/`exclusiveMaximum` | `GreaterThanOrEqual` / `GreaterThan` / `LessThanOrEqual` / `LessThan` | |
| `multipleOf` | `DivisibleBy(value)` | |
| `minItems`/`maxItems`, `minProperties`/`maxProperties` (map) | `Count(min, max)` | |
| `uniqueItems: true` | `Unique` | |
| `enum` у строки/числа, которое ядро не вынесло в PHP-enum | `Choice(choices)` | Вынесенный enum типизирован — constraint не нужен. |
| `const` | `IdenticalTo(value)` | |
| `format: email` / `uri` / `uuid` / `ipv4` / `ipv6` / `hostname` | `Email` / `Url` / `Uuid` / `Ip(version: 4\|6)` / `Hostname` | Если формат замаплен на класс (`formats`), constraint не ставится. |
| свойство-объект или список/map объектов | `Valid` | Каскад в вложенные DTO. |
| ограничения `items` (список) | `All([...])` | Внутри — constraints элемента по этой же таблице. |
| `additionalProperties` со схемой (map) | `All([...])` | |

- Nullable-свойства: constraint'ы не меняются — Validator пропускает `null` в большинстве constraint'ов; `NotNull` не ставится.
- `x-validator-groups: [..]` на свойстве или классе — группы этих constraint'ов (иначе `extensionConfig.symfony.groups`, иначе по умолчанию Symfony).
- `x-validator-skip: true` — свойство (или класс) без constraint'ов моста; `x-php-attributes` ядра работают как прежде.
- Произвольные constraint'ы — через `x-php-attributes`/`attributeAliases` ядра; отдельного синтаксиса мост не вводит. Решение: не дублировать грамматику ядра.

### 5.2 Импорт
`ImportAlias('Symfony\Component\Validator\Constraints', 'Assert')` — в коде `#[Assert\Length(...)]` / `@Assert\Length(...)`.

### 5.3 Порядок
Внутри свойства — порядок строк таблицы §5.1; так golden-вывод стабилен.

### 5.4 Версии Symfony
- Атрибуты Validator — с 5.2 (минимум моста 5.4 — всегда доступны).
- Именованные аргументы constraint'ов (`Length(min: 1)`): конструкторы constraint'ов получили явные параметры вместе с поддержкой атрибутов (5.2). Решение: всегда именованные аргументы; интеграционные тесты B4 на 5.4/6.4/7.x это подтверждают, иначе для старых версий — массив `options`.
- Constraint, которого нет в версии (например `Unique` < 5.2 — неактуально; в будущем — новые), — warning и пропуск.

### 5.5 PHP 7.4 и аннотации
- Цель `metadata: annotations` + Symfony ≥ 7.0: Symfony 7 не читает Doctrine-аннотации. Решение: ошибка при `target.strict`, иначе warning и мост ничего не выводит.
- Symfony 5.4/6.4 + аннотации: потребителю нужен `doctrine/annotations` — мост проверяет `InstalledPackages` и выдаёт warning, если пакета нет.

## 6. Serializer

| Источник | Атрибут | Примечание |
|---|---|---|
| `wireName` ≠ имя свойства PHP | `SerializedName(wireName)` | |
| `discriminator` у базы (abstract) | `DiscriminatorMap(typeProperty, mapping)` на классе | Mapping — wire-значение → FQCN подкласса из IR. |
| тип `date` (`format: date`) | `Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])` | |
| тип `date-time` | `Context([DateTimeNormalizer::FORMAT_KEY => DateTimeInterface::RFC3339])` | Только если формат по умолчанию Symfony отличается (решение: ставить всегда — явнее). |
| `x-serializer-groups: [..]` | `Groups([...])` | |
| `x-serializer-ignore: true` | `Ignore` | |
| `readOnly: true` / `writeOnly: true` | — | Решение: не маппить в этой версии (нет однозначного атрибута); warning не нужен. |

- Импорт `ImportAlias('Symfony\Component\Serializer\Annotation', 'Serializer')` для 5.4/6.x и `Symfony\Component\Serializer\Attribute` для ≥ 7.0 (в 7.0 `Annotation` объявлен устаревшим).
- `Context` — с 5.3; `SerializedName`, `Groups`, `Ignore`, `DiscriminatorMap` — атрибуты с 5.x.
- Аннотации на 7.4 + Symfony ≥ 7.0 — как §5.5.

## 7. Бандл (`src/Bundle`)

- `DtoGeneratorBundle`, конфиг `config/packages/dto_generator.yaml`:
  ```yaml
  dto_generator:
    config: '%kernel.project_dir%/dto-generator.yaml'
    check_on_warmup: false
  ```
- Команда `dto-generator:generate [--check] [--dry-run] [--format=text|json]` — те же коды выхода и вывод, что у CLI ядра (переиспользует `Presentation\Cli` ядра или вызывает `Generate` и форматирует так же).
- `check_on_warmup: true` — `CacheWarmerInterface` (опциональный) запускает генерацию в режиме `--check` и пишет warning в лог при расхождении; никогда не пишет файлы (на деплое `src/` может быть только для чтения). Решение: запись в warmup не делается.
- Бандл не нужен для генерации: плагин Composer и CLI ядра работают без него.

## 8. Тестирование

- Unit: каждый ключ таблиц §5.1 и §6 → ожидаемые `AttributeModel` (через `AttributeFixture` ядра — или собственный аналог).
- Golden: сгенерированный golden-проект ядра с мостом для целей 7.4 / 8.2 / 8.5 × Symfony 5.4 / 6.4 / 7.x.
- Интеграция (CI-матрица по версиям Symfony): сгенерированные DTO + реальные Validator и Serializer; набор JSON-экземпляров «валиден/невалиден по схеме» → совпадение вердикта Validator; round-trip Serializer.
- Бандл: тестовое ядро Symfony, команда и warmer.

## 9. Этапы

- **B1.** Каркас репозитория (инструменты как у ядра, deptrac, CI), `SymfonyExtension`, разбор `extensionConfig.symfony`, определение версии, `extra.dto-generator.extensions`.
- **B2.** Validator: §5.1–§5.5, при необходимости `PropertyContext::resolvedSchema()` в ядре.
- **B3.** Serializer: §6.
- **B4.** Интеграционная матрица Symfony 5.4/6.4/7.x.
- **B5.** Бандл (§7).
- **B6.** Включение моста в Docker-образ ядра, README, релиз.

Каждый этап — с тестами и `make verify`.
