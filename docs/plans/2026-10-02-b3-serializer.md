# dto-generator-bridge — этап B3: атрибуты Symfony Serializer. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans.

**Goal:** свойства и классы сгенерированных DTO получают атрибуты Symfony Serializer по §6 спецификации.

**Spec:** `docs/specs/2026-10-02-bridge-symfony-design.md` §6 (и §5.4–§5.5 — версии и аннотации).

## Global Constraints
PHP ≥ 7.4; только SPI ядра; MSI 100 %; ядро не меняется.

## Решения
- **Общий шлюз `ComponentGate`** (корень пакета): решение «писать ли» для компонента (`symfony/validator` или `symfony/serializer`) — один раз на запуск, диагностики — на первом месте вызова. Вход — `InstalledPackages`, `TargetProfile`, `Diagnostics`, `SchemaLocation` (у `ClassContext` и `PropertyContext` нет общего интерфейса). Сообщения параметризуются компонентом и тем, что он пишет («constraints» / «attributes»). `ValidatorEnricher` переводится на шлюз без изменения сообщений.
- **`Keywords`** переезжает из `Validator` в корень пакета: сериализатору нужна схема, по которой ядро типизирует значение (`resolved()`), и её `format` — тот, что даёт тип `date`/`date-time`.
- **`Serializer\SerializerEnricher`** реализует `PropertyEnricher` и `ClassEnricher`; один экземпляр регистрируется дважды, шлюз общий — ядро обогащает класс раньше его свойств, поэтому диагностики выводятся один раз.
  - Свойство, по порядку: `Ignore` (`x-serializer-ignore: true`; остальные атрибуты свойства тогда не пишутся), `SerializedName(wireName)` если `wireName ≠ name`, `Groups([...])` из `x-serializer-groups` (непустые строки; пусто — нет атрибута), `Context` для `format: date` (свойство или его `items`): `normalizationContext: ['datetime_format' => 'Y-m-d']`, `denormalizationContext: ['datetime_format' => '!Y-m-d']`. Ключ — литерал `'datetime_format'` (значение `DateTimeNormalizer::FORMAT_KEY` во всех версиях 5.4–8.x), а не константа: ядро не умеет константы ключами map.
  - Класс: `DiscriminatorMap(typeProperty:, mapping: [значение => Подкласс::class])` для `ClassKind::ABSTRACT` с `discriminator()`.
  - Namespace: `Symfony\Component\Serializer\Attribute` с 6.4, иначе `…\Annotation`; импорт `ImportAlias(<ns>, 'Serializer')`.
  - `x-serializer-skip: true` на свойстве — без атрибутов (симметрично `x-validator-skip`).
- **Тесты:** через настоящий генератор в dry-run (как `ValidatorEnricherTest`): каждое правило, namespace по версии, аннотации 7.4 + serializer 7.x, общий шлюз (одно предупреждение на запуск при классе с дискриминатором).

## Tasks
1. Рефакторинг: `ComponentGate`, `Keywords` в корень; тесты B2 зелёные без изменений сообщений.
2. `SerializerEnricher` + регистрация; тесты.
3. Документация, ревью, слияние.

## Решения при выполнении
- Ruling: `ComponentGate::version()` возвращает версию (или null), а не bool — сериализатору версия нужна для namespace; сообщения валидатора не изменились.
- Ruling: общий `ExtensionReader` (флаги, списки групп) для `x-validator-*` и `x-serializer-*` — одинаковые правила и сообщения.
- Ruling: `SerializedName` и `Groups` — позиционно (одинаково для атрибута и аннотации 5.4); `Context`/`DiscriminatorMap` — именованно.
- Ruling: `Context` ставится, только если тип значения — класс дат цели (`TargetProfile::dateTimeClass()`), а формат схемы, по которой типизирует ядро, — `date`: класс из `formats` для `date` получает свою нормализацию.
- Ядро: `DiscriminatorModel::mapping()` (минор, слит в `main` ядра) — иначе ветка «значение без класса» в мосте недостижима и даёт эквивалентного мутанта.
- Ruling: проверка `ClassKind::isAbstract()` не нужна — ядро даёт дискриминатор только абстрактной базе.

## Ревью high (CR-001…CR-010) — исправления
- CR-001: `Keywords` проходит в единственного не-null члена `oneOf`/`anyOf` (и для `resolved()`, и для ключевых слов) — nullable-даты получают `Context`, валидатор — ограничения члена.
- CR-002: warning на `x-serializer-ignore` у required-свойства без default.
- CR-003: Ruling — `SerializedName` только при различии имён, глобальные name converter'ы описаны в README; опция «всегда» — YAGNI до запроса.
- CR-004: тесты — граница 6.4.0, числовой дискриминатор, аннотации 7.4 для `DiscriminatorMap`/`Groups`/`Context`, `list<list<date>>`, nullable-union.
- CR-005…010: комментарий о gate; `ComponentGate` получает настройку в конструкторе; харнесс — префикс `dto-bridge-`, `classAttributesOf()` разбирает многострочные атрибуты; сообщение о группах; README — раздел выше License и ограничения; `ExtensionReader` после проверки версии.
- Повторное ревью medium: CR-101 — в члена union заходим, только если он типизирован (иначе тип даёт сама схема, как в ядре); CR-102/103 — docblock `resolved()` и README.
