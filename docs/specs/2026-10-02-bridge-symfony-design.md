# dto-generator-bridge-symfony — дизайн

**Дата:** 2026-10-02 (UTC)
**Статус:** черновик, составлен автономно по §12 спецификации ядра (`../../msstc4php/dto-generator/docs/specs/2026-10-01-dto-generator-design.md`); решения, принятые без пользователя, помечены «Решение». Утверждения о поведении конкретной версии Symfony, помеченные «проверить в B4», подтверждаются интеграционной матрицей до того, как на них опирается вывод.

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
- Пакет `msstc4symfony/dto-generator-bridge`, namespace `Msstc4Symfony\DtoGeneratorBridge`, PHP ≥ 7.4 (работает в рантайме генератора). Репозиторий — `~/PhpstormProjects/msstc4symfony/dto-generator-bridge-symfony`, рядом с остальными Symfony-бандлами.
- Пакет соответствует `msstc4symfony/bundle-standard` (≥ v1.9.0) с профилем среды выполнения `php74` (`extra.bundle-standard.runtime`): шаблоны инструментов `templates/php74/`, `require.php >=7.4`, CI — общий workflow; его задание `minimal` по профилю идёт на PHP 7.4.
- Зависимость только от SPI ядра: `Contract\*`, `Domain\Model\*`, `Domain\Schema\*`, `Domain\Diagnostic\*`, `Domain\Target\*`, `Domain\Shared\*` (так и записано в `deptrac.yaml`). Классы Symfony в рантайме генератора не нужны: мост выводит их имена строками (`ClassName`), поэтому `symfony/validator` и `symfony/serializer` — только в `require-dev` и `suggest`.
- Бандл — в том же пакете (`src/Bundle`), активен, только если установлен `symfony/framework-bundle`; его PHP-минимум тот же, что у Symfony выбранной версии.
- Инструменты — шаблоны стандарта (PHPStan max, CS-Fixer, Rector, deptrac, infection с порогом 100 %, Roave BC check); `composer.json` содержит только то, что ставится на 7.4 (PHPUnit 9.6), инструменты — в `composer-ci.json`.
- Ядро — `msstc4php/dto-generator: ^1.0` из VCS `https://github.com/msstc4php/dto-generator`; локально — `composer-local.json` с path-репозиторием `../../msstc4php/dto-generator` (версия 1.0.0). Пока ядро не опубликовано, CI моста не может его установить (B6).

## 3. Подключение

- `composer.json` моста: `extra.dto-generator.extensions: ["Msstc4Symfony\\DtoGeneratorBridge\\SymfonyExtension"]` — обнаруживается ядром (§8 ядра) без правки конфига.
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
| свойство в `required`, тип не nullable и не `mixed` (`{}`, `type: null`) | `NotNull` | Конструктор и так требует значение; constraint нужен для денормализации в существующий объект и для `mutable`. |
| `minLength`/`maxLength` | `Length(min:, max:)` | Один constraint на оба. |
| `pattern` | `Regex(pattern: '/…/u')` | Модификаторы `uD` (`$` — конец строки, как в ECMA). Неэкранированный `/` экранируется; `\uXXXX`/`\u{…}` → `\x{…}`, суррогатная пара — одна кодовая точка, одиночный суррогат отклоняется; паттерн, который PCRE не компилирует (или с неверным `\u`), — warning и пропуск. |
| `minimum`/`maximum` (оба включительно) | `Range(min:, max:)` | |
| иначе — одна нижняя, затем одна верхняя граница | `GreaterThanOrEqual`/`GreaterThan`, затем `LessThanOrEqual`/`LessThan` (`value:`) | Из включительной и исключающей границы берётся строгая (при равенстве — исключающая); `Range` — если обе итоговые включительные. Границы без допустимого значения (для `int` — без целого между ними) — warning и пропуск. |
| `multipleOf` | `DivisibleBy(value:)` | Не больше нуля — warning и пропуск. |
| `minItems`/`maxItems`, `minProperties`/`maxProperties` (map) | `Count(min:, max:)` | |
| `uniqueItems: true` (только список) | `Unique` | См. §5.6: объекты сравниваются по идентичности. |
| `enum`, если тип свойства — не PHP-enum (цель 7.4/8.0; `enum` рядом с `$ref` на не-enum; enum из `float`/`bool`, для которых ядро не делает PHP-enum) | `Choice(choices:)` | Значения — в PHP-типе свойства (`1` на `float` → `1.0`). На 8.1+ значения PHP-enum ограничивает сам тип; второй `enum` в цепочке — warning (сужение не проверяется). Пустое пересечение `enum` — warning, без `Choice`; `null` в `choices` не входит, `enum: [null]` — ничего. |
| `const`: скаляр | `IdenticalTo(value:)` | Целое на `float`-свойстве — `1.0` (`===`); на свойстве-enum (8.1+) — case enum'а (`Pet::VALUE_2`), значение вне enum — warning. |
| `const: null` | `IsNull` | |
| `const`: массив или объект | — | Warning и пропуск: `AbstractComparison` принимает массив за опции. |
| `format: email` | `Email(mode:)` | Режим явно (`html5`): режим по умолчанию зависит от версии. |
| `format: ipv4` / `ipv6` | `Ip(version: '4'\|'6')` | Значения — строки. |
| `format: hostname` | `Hostname(requireTld: false)` | По умолчанию `localhost` отклоняется. |
| `format: uuid` | `Uuid` | См. §5.6 (версии UUID, nil). |
| `format: uri` | — | Решение: не маппить. `Url` принимает только http(s) с хостом, а `uri` из JSON Schema — любой URI (`urn:`, `mailto:`). |
| значение или элементы — объект со свойствами или композиция (`allOf`/`oneOf`/`anyOf`) | `Valid` | Каскад во вложенные DTO; решается по схеме, а не по типу: дата — тоже класс, но строка в схеме; объект без свойств ядро делает массивом. |
| ограничения `items` (список), схема `additionalProperties` (map) | `All(constraints: [...])` | Вложенные `new` в атрибуте — PHP ≥ 8.1; на цели 8.0 мост сам пропускает `All` с warning (ядро в strict сочло бы атрибут ошибкой); на 7.4 — аннотация `@Assert\All({@Assert\Length(...)})`. `Valid` элементов любой глубины поднимается на свойство: Symfony запрещает `Valid` внутри `All`. |

- Ключевые слова строки, числа и коллекции ставятся, только если тип свойства — именно такой (`string`; `int`/`float`; список/map). Для значения нескольких типов (union, `mixed`) — warning и пропуск: Symfony проверил бы их и на другом типе (`Length` считает цифры числа). Для класса (дата, класс формата) — молча пропуск.
- Неверные значения (`minimum: '5'`, `maxLength: -1`, дробный счётчик) — warning и пропуск; `2.0` — целое. Противоречивые границы (`min*` > `max*`, в том числе через `$ref`) — warning и пропуск пары; ядро о противоречии внутри одной схемы для `minimum`/`maximum` сообщает и само.
- `x-validator-skip`, `x-validator-groups-exclusive` не `true`/`false` — warning, флаг не действует.
- `enum`/`const` на значении-классе (`x-php-type`, класс из `formats`, дата, сгенерированный класс) — warning, без constraint'а: `Choice`/`IdenticalTo` сравнили бы объект со значением JSON. `const: null` остаётся `IsNull`, `enum` из одного `null` — без constraint'а и без warning.

- `format` формата, замапленного на класс (`formats` ядра), constraint не получает.
- В 2020-12 `format` — аннотация, а не утверждение. Решение: мост проверяет форматы из таблицы, как это делает большинство валидаторов OpenAPI; `x-validator-skip` отключает.
- Nullable-свойства: constraint'ы не меняются — Validator пропускает `null` в большинстве constraint'ов; `NotNull` не ставится.

### 5.2 Группы
- `x-validator-groups: [..]` на свойстве — группы его constraint'ов (иначе `extensionConfig.symfony.groups`, иначе без групп); нестроковые и пустые имена отбрасываются с warning, повторы схлопываются; не список — warning и без групп. Группы на уровне класса — после B2.
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
- Ключевые слова всех звеньев цепочки `$ref` (`SchemaReferences::chain()` ядра) и веток `allOf` действуют вместе (2020-12): из границ берётся более строгая, `pattern`/`multipleOf`/`const` — все, `enum` — пересечение; `format` — все (каждый проверяемый — свой constraint). `const` с разными значениями — warning и пропуск. `enum` с массивом или объектом — warning, без `Choice`. Схема значения для `items`/`additionalProperties` — через единственную типизированную ветку `allOf` (`$ref`, тип, `enum`, композиция, свойства, `x-php-type`), как `TypeMapper` ядра; нетипизированные ветки, нетипизированный `anyOf`/`oneOf` и `type: null` обёртки этому не мешают. Значения сравниваются как в JSON: `1` и `1.0` — одно число.
- Ограничения веток `anyOf`/`oneOf` не переносятся; `Valid` ставится, если хотя бы одна ветка — объект со свойствами.
- ECMA-262 и PCRE: `\d`, `\w`, `\b` в PCRE без `(*UCP)` — только ASCII, как и в ECMA без флага `u`; именованные группы и lookbehind совместимы; остальные расхождения проявятся ошибкой компиляции (warning).
- `Unique` сравнивает объекты по идентичности: `uniqueItems` для списка DTO фактически не проверяется. Документируется.
- `Uuid`: поддержка версий 7/8 появилась позже 5.4 (проверить в B4, вероятно 6.2); nil-UUID отклоняется всеми версиями. Документируется; при `version < поддерживающей` — `Uuid(versions: [...])` без 7/8.

## 6. Serializer

| Источник | Атрибут | Примечание |
|---|---|---|
| `wireName` ≠ имя свойства PHP | `SerializedName('wire')` | Позиционно: так и атрибут, и аннотация 5.4 (`value`) читаются одинаково. |
| `discriminator` у базы (abstract) | `DiscriminatorMap(typeProperty:, mapping:)` на классе | Mapping — wire-значение → `Подкласс::class` (`DiscriminatorModel::mapping()` ядра). |
| `format: date` у значения, списка или map, тип — класс дат цели | `Context(normalizationContext: ['datetime_format' => 'Y-m-d'], denormalizationContext: ['datetime_format' => '!Y-m-d'])` | `!` обнуляет время, иначе `createFromFormat` подставит текущее. Ключ — литерал `'datetime_format'` (`DateTimeNormalizer::FORMAT_KEY` во всех версиях): ядро не пишет константы ключами map. Формат — схемы, по которой ядро типизирует (`Keywords::resolved()`). |
| тип `date-time` | — | Решение: не ставить. Формат по умолчанию Symfony — RFC3339 при выводе, а строгий `FORMAT_KEY` при чтении отверг бы валидные значения с долями секунд. |
| `x-serializer-groups: [..]` | `Groups([...])` | Позиционно; повторы схлопываются, неверные значения — warning. |
| `x-serializer-ignore: true` | `Ignore` | Остальные атрибуты свойства тогда не пишутся. |
| `x-serializer-skip: true` | — | Свойство без атрибутов моста. |
| `readOnly: true` / `writeOnly: true` | — | Решение: не маппить в этой версии (нет однозначного атрибута). |

- Namespace атрибутов: `Symfony\Component\Serializer\Attribute` с 6.4, `Symfony\Component\Serializer\Annotation` для 5.4–6.3 (в 6.4 объявлен устаревшим, в 8.0 удалён — проверить в B4). Импорт `ImportAlias(<namespace>, 'Serializer')`.
- Писать ли и для какой версии — `ComponentGate` (общий с Validator): `extensionConfig.symfony.serializer` auto/true/false, версия `symfony/serializer`, аннотации на 7.4 + Serializer ≥ 7.0 — как §5.5. Один экземпляр `SerializerEnricher` — и `ClassEnricher`, и `PropertyEnricher`: ядро обогащает класс раньше свойств, решение принимается один раз.
- `Context` — с 5.3; `SerializedName`, `Groups`, `Ignore`, `DiscriminatorMap` — атрибуты с 5.x.

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
- **B6.** Публикация ядра (`v1.0.0`) и моста; включение моста в Docker-образ ядра; README; релиз.

Каждый этап — с тестами и `make verify`.
