# dto-generator-bridge-symfony — дизайн

**Дата:** 2026-10-02 (UTC)
**Статус:** черновик, составлен автономно по §12 спецификации ядра (`../dto-generator/docs/specs/2026-10-01-dto-generator-design.md`); решения, принятые без пользователя, помечены «Решение». Утверждения о поведении конкретной версии Symfony, помеченные «проверить в B4», подтверждаются интеграционной матрицей до того, как на них опирается вывод.

## 1. Цель

Расширение генератора DTO (`msstc4php/dto-generator`): к сгенерированным классам и свойствам добавляет атрибуты (или аннотации на PHP 7.4) Symfony Validator и Symfony Serializer, выведенные из стандартных ключевых слов OpenAPI 3.1 / JSON Schema и из `x-`-ключей моста. Опционально — Symfony-бандл с командой и конфигом.

### Критерии успеха
- DTO, сгенерированный с мостом, проходит `Validator::validate()` ровно тогда, когда JSON-экземпляр проходит схему — для поддержанных ключевых слов и с оговорками §5.6.
- `Serializer::deserialize()` JSON-экземпляра в DTO и обратная `serialize()` сохраняют имена полей схемы (wire names), даты и полиморфизм `discriminator`.
- Вывод зависит от версии Symfony потребителя (5.4, 6.4, 7.4, 8.x) и от целевой версии PHP (7.4–8.5) так же, как у ядра.

### Вне рамок
- Проверки, которые Validator не выражает без кастомных constraint'ов: `if/then/else`, `dependentRequired`, `contains`, `propertyNames`, `patternProperties`.
- Нормализаторы и денормализаторы, кроме атрибутов: мост не генерирует PHP-код поведения.
- Валидация во время выполнения без Symfony.

## 2. Ограничения
- Пакет `msstc4php/dto-generator-bridge-symfony`, namespace `MSSTC4PHP\DtoGeneratorBridgeSymfony`, PHP ≥ 7.4 (работает в рантайме генератора).
- Зависимость только от SPI ядра: `Contract\*`, `Domain\Model\*`, `Domain\Schema\*`, `Domain\Diagnostic\*`, `Domain\Target\*`, `Domain\Shared\*` (так и записано в `deptrac.yaml`). Классы Symfony в рантайме генератора не нужны: мост выводит их имена строками (`ClassName`), поэтому `symfony/validator` и `symfony/serializer` — только в `require-dev` и `suggest`.
- Бандл — в том же пакете (`src/Bundle`), активен, только если установлен `symfony/framework-bundle`; его PHP-минимум тот же, что у Symfony выбранной версии.
- Инструменты как у ядра: PHPStan max, CS-Fixer, Rector, deptrac, infection (MSI 100 %), golden-матрица; в CI — тесты, статический анализ и infection.
- До первого релиза ядро подключается path-репозиторием `../dto-generator` (`dev-main`); в B6 — `^1.0`.

## 3. Подключение

- `composer.json` моста: `extra.dto-generator.extensions: ["MSSTC4PHP\\DtoGeneratorBridgeSymfony\\SymfonyExtension"]` — обнаруживается ядром (§8 ядра) без правки конфига.
- Одно расширение `SymfonyExtension`, `name()` = `symfony`, секция `extensionConfig.symfony`:

```yaml
extensionConfig:
  symfony:
    validator: auto      # auto | true | false
    serializer: auto     # auto | true | false
    version: auto        # auto | '5.4' | '6.4' | '7.4' … — строкой: YAML читает 6.4 как число
    groups: []           # группы валидации по умолчанию для всех constraint'ов
```

- `auto` для `validator`/`serializer`: включено, если пакет `symfony/validator` / `symfony/serializer` есть в `InstalledPackages` (lock потребителя). `true` без пакета — работает (мост не зависит от пакетов), но warning «not installed».
- **Версия — по компоненту.** Validator-атрибуты пишутся для версии `symfony/validator`, Serializer-атрибуты — для версии `symfony/serializer`: компоненты версионируются независимо. Явная `version` применяется к обоим. Без своего компонента — старшая из основных пакетов Symfony в lock (`validator`, `serializer`, `framework-bundle`, `http-kernel`, `dependency-injection`, `console`, `property-access`), без них — `LATEST` (7.4, последняя версия, правила которой мост знает).
- `version` ниже 5.4 — ошибка конфига; версия ниже 5.4 из lock — warning, и компонент не обслуживается.
- Неверная секция — ошибка конфига. Ядро сообщает ошибки регистрации расширения на корне конфига (`dto-generator.yaml#`), поэтому сообщение моста само называет ключ: `extensionConfig.symfony.<key> …`; все проблемы секции — в одном сообщении. `null` у ключа — ошибка, а не `auto`.
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
| `minLength`/`maxLength` | `Length(min:, max:)` | Один constraint на оба. |
| `pattern` | `Regex(pattern: '/…/u')` | Разделитель `/` экранируется; ECMA-конструкции, которых нет в PCRE, — warning и пропуск. |
| `minimum`/`maximum` (оба включительно) | `Range(min:, max:)` | |
| одна граница или `exclusiveMinimum`/`exclusiveMaximum` | `GreaterThanOrEqual` / `GreaterThan` / `LessThanOrEqual` / `LessThan` (`value:`) | |
| `multipleOf` | `DivisibleBy(value:)` | |
| `minItems`/`maxItems`, `minProperties`/`maxProperties` (map) | `Count(min:, max:)` | |
| `uniqueItems: true` | `Unique` | См. §5.6: объекты сравниваются по идентичности. |
| `enum` у строки/числа, которое ядро не вынесло в PHP-enum | `Choice(choices:)` | См. §5.6 про `strict`. |
| `const`: скаляр | `IdenticalTo(value:)` | |
| `const: null` | `IsNull` | |
| `const`: массив или объект | — | Warning и пропуск: `AbstractComparison` принимает массив за опции. |
| `format: email` | `Email(mode:)` | Режим явно (`html5`): режим по умолчанию зависит от версии. |
| `format: ipv4` / `ipv6` | `Ip(version: '4'\|'6')` | Значения — строки. |
| `format: hostname` | `Hostname(requireTld: false)` | По умолчанию `localhost` отклоняется. |
| `format: uuid` | `Uuid` | См. §5.6 (версии UUID, nil). |
| `format: uri` | — | Решение: не маппить. `Url` принимает только http(s) с хостом, а `uri` из JSON Schema — любой URI (`urn:`, `mailto:`). |
| свойство-объект или список/map объектов | `Valid` | Каскад во вложенные DTO. |
| ограничения `items` (список), схема `additionalProperties` (map) | `All(constraints: [...])` | Вложенные `new` в атрибуте — PHP ≥ 8.1; на цели 8.0 — warning и пропуск `All` (ядро отбрасывает такие атрибуты и само, §6.1 ядра); на 7.4 — аннотация `@Assert\All({@Assert\Length(...)})`. |

- `format` формата, замапленного на класс (`formats` ядра), constraint не получает.
- В 2020-12 `format` — аннотация, а не утверждение. Решение: мост проверяет форматы из таблицы, как это делает большинство валидаторов OpenAPI; `x-validator-skip` отключает.
- Nullable-свойства: constraint'ы не меняются — Validator пропускает `null` в большинстве constraint'ов; `NotNull` не ставится.

### 5.2 Группы
- `x-validator-groups: [..]` на свойстве или классе — группы constraint'ов (иначе `extensionConfig.symfony.groups`, иначе без групп).
- Constraint с явными группами выпадает из группы `Default`: `validate($dto)` без групп его не проверит. Решение: мост добавляет `Default` к явно заданным группам, если её там нет; `x-validator-groups-exclusive: true` это отключает.
- `x-validator-skip: true` — свойство (или класс) без constraint'ов моста; `x-php-attributes` ядра работают как прежде.
- Произвольные constraint'ы — через `x-php-attributes`/`attributeAliases` ядра; отдельного синтаксиса мост не вводит. Решение: не дублировать грамматику ядра.

### 5.3 Импорт и порядок
- `ImportAlias('Symfony\Component\Validator\Constraints', 'Assert')` — в коде `#[Assert\Length(...)]` / `@Assert\Length(...)`.
- Внутри свойства — порядок строк таблицы §5.1; так golden-вывод стабилен.

### 5.4 Версии Symfony
- Атрибуты Validator и именованные параметры конструкторов constraint'ов — с 5.2 (проверить в B4 на каждом constraint таблицы). Решение: всегда именованные аргументы; массив `options` не используется — в 7.3 он объявлен устаревшим, в 8.0 удалён (проверить в B4).
- Значения по умолчанию, которые меняются между версиями (`Email::mode`, `Url::requireTld`, `Hostname::requireTld`), мост пишет явно.
- Constraint, которого нет в версии, — warning и пропуск.

### 5.5 PHP 7.4 и аннотации
- Цель `metadata: annotations` + Symfony Validator ≥ 7.0: Symfony 7 не читает Doctrine-аннотации. Решение: ошибка при `target.strict`, иначе warning и мост ничего не выводит.
- Symfony 5.4/6.4 + аннотации: потребителю нужен `doctrine/annotations` — мост проверяет `InstalledPackages` и выдаёт warning, если пакета нет.

### 5.6 Известные расхождения с JSON Schema
- `Choice` по умолчанию строгий: `1.0` и `1` разные, хотя для JSON равны. Решение: для числовых `enum` с дробными значениями — `strict: false`.
- `Unique` сравнивает объекты по идентичности: `uniqueItems` для списка DTO фактически не проверяется. Документируется.
- `Uuid`: поддержка версий 7/8 появилась позже 5.4 (проверить в B4, вероятно 6.2); nil-UUID отклоняется всеми версиями. Документируется; при `version < поддерживающей` — `Uuid(versions: [...])` без 7/8.

## 6. Serializer

| Источник | Атрибут | Примечание |
|---|---|---|
| `wireName` ≠ имя свойства PHP | `SerializedName(wireName)` | |
| `discriminator` у базы (abstract) | `DiscriminatorMap(typeProperty:, mapping:)` на классе | Mapping — wire-значение → FQCN подкласса из IR. |
| тип `date` (`format: date`) | `Context(normalizationContext: [FORMAT_KEY => 'Y-m-d'], denormalizationContext: [FORMAT_KEY => '!Y-m-d'])` | `!` обнуляет время, иначе `createFromFormat` подставит текущее. |
| тип `date-time` | — | Решение: не ставить. Формат по умолчанию Symfony — RFC3339 при выводе, а строгий `FORMAT_KEY` при чтении отверг бы валидные значения с долями секунд. |
| `x-serializer-groups: [..]` | `Groups([...])` | |
| `x-serializer-ignore: true` | `Ignore` | |
| `readOnly: true` / `writeOnly: true` | — | Решение: не маппить в этой версии (нет однозначного атрибута). |

- Namespace атрибутов: `Symfony\Component\Serializer\Attribute` с 6.4, `Symfony\Component\Serializer\Annotation` для 5.4–6.3 (в 6.4 объявлен устаревшим, в 8.0 удалён — проверить в B4). Импорт `ImportAlias(<namespace>, 'Serializer')`.
- `Context` — с 5.3; `SerializedName`, `Groups`, `Ignore`, `DiscriminatorMap` — атрибуты с 5.x.
- Аннотации на 7.4 + Symfony Serializer ≥ 7.0 — как §5.5.

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

- Unit: каждый ключ таблиц §5.1 и §6 → ожидаемые `AttributeModel`.
- Golden: сгенерированный golden-проект ядра с мостом для целей 7.4 / 8.0 / 8.2 / 8.5 × Symfony 5.4 / 6.4 / 7.4 / 8.x.
- Интеграция (CI-матрица по версиям Symfony): сгенерированные DTO + реальные Validator и Serializer; набор JSON-экземпляров «валиден/невалиден по схеме» → совпадение вердикта Validator (с оговорками §5.6); round-trip Serializer, включая `date-time` с долями секунд.
- Бандл: тестовое ядро Symfony, команда и warmer.
- `composer.json` моста объявляет только существующие классы `Extension` (тест).

## 9. Этапы

- **B1.** Каркас репозитория (инструменты как у ядра, deptrac, CI), `SymfonyExtension`, разбор `extensionConfig.symfony`, определение версии по компоненту, `extra.dto-generator.extensions`.
- **B2.** Validator: §5.1–§5.6, при необходимости `PropertyContext::resolvedSchema()` в ядре.
- **B3.** Serializer: §6.
- **B4.** Интеграционная матрица Symfony 5.4/6.4/7.4/8.x; снимает пометки «проверить в B4».
- **B5.** Бандл (§7).
- **B6.** Ядро — `^1.0` вместо path-репозитория; включение моста в Docker-образ ядра; README; релиз.

Каждый этап — с тестами и `make verify`.
