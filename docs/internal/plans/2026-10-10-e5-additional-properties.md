# E5 — `$additionalProperties` в Symfony Serializer: план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** режим `extensionConfig.symfony.additionalProperties: spread`, в котором Symfony Serializer раскладывает
`$additionalProperties` по ключам верхнего уровня объекта и собирает необъявленные ключи обратно в map.

**Architecture:** генератор (PHP 7.4, слой Bridge) пишет на свойство-map атрибут
`Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties` вместо `Ignore`. В приложении
`Runtime\AdditionalPropertiesNormalizer` оборачивает `ObjectNormalizer`: при записи переносит записи map на верхний
уровень, при чтении собирает необъявленные ключи в map и отдаёт их внутреннему нормалайзеру под именем свойства —
типы значений восстанавливает PropertyInfo, как для любого map. Бандл регистрирует нормалайзер compiler pass'ом,
когда в контейнере есть сериализатор.

**Tech Stack:** PHP 7.4 (генератор) / 8.0+ (runtime), Symfony Serializer 5.4–8, PHPUnit 9.6.

**Spec:** `../../msstc4php/dto-generator/docs/internal/specs/2026-10-09-e-features-design.md` §7 (с решениями ниже).

## Global Constraints

- Все файлы `src/` и `tests/` разбираются PHP 7.4: job `minimal` стандарта линтит их на 7.4. Никакого синтаксиса PHP 8
  кроме того, что 7.4 читает как комментарий или имя класса: однострочные `#[...]`, типы `mixed`.
- `src/Runtime` выполняется только на PHP ≥ 8.0 рядом с `symfony/serializer`; PHPStan проверяет его конфигом
  `phpstan-symfony.neon` (PHP 8.4), `phpstan.dist.neon` (phpVersion 70400) его исключает.
- Комментарии в коде на английском, только «почему». Infection MSI 100 %.

## Решения (отклонения от спецификации §7)

- Ruling: маркер на **свойстве**, а не на классе — генератор обогащает класс раньше его свойств, а решение (`spread`
  или `x-serializer-ignore`) принимается по схеме свойства. Нормалайзер ищет свойство в классе и его родителях.
- Ruling: **декоратор `AbstractObjectNormalizer`** вместо делегирования цепочке с флагом в контексте — флаг наследуют
  вложенные объекты того же класса, а путь `deserialization_path` для параметров конструктора есть не во всех
  версиях. Декоратор вызывает внутренний нормалайзер для своего объекта напрямую, вложенные идут через цепочку.
- Ruling: **Symfony 5.4+ и PHP 8.0+** вместо 6.4+/8.1+ — сигнатуры, совместимые со всеми версиями интерфейсов 5.4–8,
  пишутся без union-типов. Условие режима `spread` в генераторе — `target.metadata` с атрибутами; иначе ошибка.
- Ruling: объявленные ключи нормалайзер берёт из свойств класса (с `SerializedName`), а не из списка в атрибуте —
  так учитываются унаследованные свойства, которых генератор при обогащении свойства не видит.
- Ruling (при реализации): регистрация в `DtoGeneratorExtension::load()` без приоритета вместо compiler pass с -900 —
  литералы приоритетов дают эквивалентных мутантов, а неиспользуемый приватный сервис без сериализатора контейнер
  удаляет сам. Условие — `PHP_VERSION_ID >= 80000` (ревью CR-001: `Attribute` объявлен полифиллом и на 7.4).
- Ruling (при реализации): `getSupportedTypes()` = `['object' => false]` — чтение базы зависит от данных;
  `denormalize()` с условным `@return` интерфейса и нативным `mixed`; конструктор принимает
  `NormalizerInterface&DenormalizerInterface` (проверка в коде — пересечения типов 7.4 не разбирает), фабрику
  метаданных и name converter (ревью CR-003, CR-010); нормалайзер передаёт Serializer обёрнутому.
- Ruling (при реализации): цель с аннотациями — ошибка, а маркер всё равно пишется: файлы при ошибке не пишутся,
  ветка «вернуть Ignore» была бы эквивалентным мутантом.
- Ruling (ревью CR-005): дискриминатор на интерфейсе поддерживается (`interface_exists`, `class_implements`).
- Ограничение: `normalize()` объявляет тип `array` (union PHP 7.4 не разбирает). Объект, вывод которого пуст, при
  `preserve_empty_objects` выходит как `[]`, а не `{}`; объект, от которого остались только записи map с ключами
  `0`, `1`, … — JSON-списком (ревью CR-004, в README).

## Review Focus

1. Вложенный объект того же класса внутри map или списка — раскладывается тоже.
2. Запись map с ключом, совпадающим с объявленным свойством, — исключение, а не тихая потеря.
3. Абстрактная база с `DiscriminatorMap` — вариант с map при чтении через базовый тип.
4. Сериализатор выключен в приложении — бандл не ломает контейнер.
5. Цель 7.4/аннотации со `spread` — ошибка генерации, `Ignore` сохраняется.

---

### Task 1: настройка `additionalProperties`

**Files:** `src/Settings.php`, `tests/Unit/SettingsTest.php`.

**Produces:** `Settings::spreadsAdditionalProperties(): bool`.

- [ ] Тест: `additionalProperties: spread` → true; по умолчанию и `ignore` → false; `1`/`'other'` → ошибка
  «extensionConfig.symfony.additionalProperties must be ignore or spread.»
- [ ] Реализация: ключ в `KEYS`, `parseAdditionalProperties()`.
- [ ] `make test`, коммит `feat: the additionalProperties setting`.

### Task 2: runtime — атрибут и нормалайзер

**Files:** `src/Runtime/AdditionalProperties.php`, `src/Runtime/AdditionalPropertiesNormalizer.php`,
`tests/Unit/Runtime/AdditionalPropertiesNormalizerTest.php`, `tests/Unit/Runtime/Fixture/*.php`.

**Produces:**
```php
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class AdditionalProperties {}

final class AdditionalPropertiesNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function __construct(AbstractObjectNormalizer $objects);
    public function normalize(mixed $data, ?string $format = null, array $context = []): array;
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool;
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): object;
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool;
    public function getSupportedTypes(?string $format): array; // ['object' => true]
}
```

Тесты (реальный `Serializer` с `ObjectNormalizer`, PropertyInfo, загрузчиком атрибутов; пропуск без Symfony 6.4+):
- запись раскладывает map, значения нормализованы (вложенный объект, дата);
- чтение собирает необъявленные ключи, `SerializedName` считается объявленным, значения денормализованы по PHPDoc;
- ключ `additionalProperties` во входных данных — обычный необъявленный ключ;
- вложенный объект того же класса в map и в списке;
- приватное свойство-map в родителе;
- коллизия ключа map с объявленным — `UnexpectedValueException`;
- класс без атрибута, не массив, не объект — не поддерживается;
- абстрактная база с `DiscriminatorMap` — чтение варианта;
- группы, исключившие map, — нечего раскладывать.

Коммит `feat: a normalizer that spreads $additionalProperties over the object`.

### Task 3: генератор пишет атрибут

**Files:** `src/Serializer/SerializerEnricher.php`, `tests/Unit/Serializer/SerializerEnricherTest.php`.

- [ ] Тесты: `spread` на 8.2 → `#[AdditionalProperties]` (импорт `Msstc4Symfony\DtoGeneratorBridge\Runtime\AdditionalProperties`),
  без `Ignore` и без warning; `x-serializer-ignore` → `Ignore`; `x-serializer-groups` → `Groups` рядом; цель 7.4 →
  одна ошибка на прогон + `Ignore`.
- [ ] Реализация, коммит `feat: write AdditionalProperties when the setting spreads the map`.

### Task 4: бандл регистрирует нормалайзер

**Files:** `src/Bundle/DtoGeneratorBundle.php`,
`src/Bundle/DependencyInjection/CompilerPass/AdditionalPropertiesNormalizerPass.php`, `tests/Unit/Bundle/*`.

- [ ] Тесты: сериализатор включён → `serializer` раскладывает map DTO; выключен → контейнер собирается.
- [ ] Pass (до `SerializerPass`): при `serializer.normalizer.object` и PHP ≥ 8.0 — сервис с тегом
  `serializer.normalizer` (priority -900). Коммит `feat: the bundle registers the normalizer`.

### Task 5: инструменты

`deptrac.yaml` (слой Runtime: только Symfony; Bridge и Bundle могут ссылаться на него), `phpstan-baseline.neon`
(excludePaths), `phpstan-symfony.neon` (paths), `phpstan-php74.neon` (`Attribute` allowIn). `make check`.

### Task 6: интеграция

`RealSymfonyTest`: схема с map из `Owner`, `spread`, цели 8.2 и 8.0, round-trip. Матрица 5.4/6.4/7.4/8 через
`run-matrix.sh`, job PHP 7.4.

### Task 7: документация

README моста (раздел про `$additionalProperties`, настройка, ручная регистрация, `require`), CHANGELOG, спецификация
моста, §7 спецификации E, `.claude/docs`.
