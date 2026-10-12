# G — проверка `dependentRequired`, `propertyNames`, `contains`, `patternProperties`, `if/then/else`

**Дата:** 2026-10-12 (UTC). **Статус:** решение принято автономно; владелец выбрал пункт 2026-10-12 («начинай»: п. 6 —
ключевые слова, которые Validator не выражает без своих constraint'ов; спецификация моста §1 «Вне рамок»).

## 1. Проблема

Ядро предупреждает «"X" is not supported and has no effect on the generated type.» для `dependentRequired`,
`propertyNames`, `contains`/`minContains`/`maxContains`, `patternProperties`, `if`/`then`/`else`, и DTO принимает
больше, чем схема. Стандартных constraint'ов Symfony 5.4–8 для них нет (`When` — с 6.2 и требует expression-language).

## 2. Этапы

- **G1** (эта итерация): `dependentRequired`, `propertyNames`, `contains` + `minContains`/`maxContains`.
- **G2**: `patternProperties`, `if`/`then`/`else` — своя спецификация-дополнение после G1.

## 3. Рантайм (`msstc4symfony/dto-generator-bridge-runtime` 1.1)

Namespace `Msstc4Symfony\DtoGeneratorBridge\Runtime\Constraints\`; каждый constraint — атрибут и аннотация
(`@Annotation`, `@NamedArgumentConstructor`), конструктор с именованными параметрами, валидатор — `<Имя>Validator`.
Работают с `symfony/validator` 5.4–8 на PHP 7.4+ (`suggest`, `conflict <5.4`). Конструкторы без `payload` (Rector
стандарта дописал бы ему нативный `mixed` родителя — PHP 8.0), имена ошибок — только `ERROR_NAMES` (Symfony 6.1+;
статический `$errorNames` Rector делает `private`, что ломает 5.4).

- `DependentRequired(array $requirements)` — на классе (`getTargets()` = class). `requirements`: PHP-имя свойства →
  непустой список PHP-имён. Свойство «есть», когда не `null` (DTO не отличает отсутствующий ключ от `null` — известное
  ограничение ядра). Для каждого заданного триггера каждое зависимое `null` → нарушение на пути зависимого свойства,
  `{{ property }}` — имя триггера. Значения читаются отражением по иерархии класса (свойства `protected`/`private` на
  PHP 7.4, `readonly`). Не объект или нет свойства — `UnexpectedValueException`/`ConstraintDefinitionException`.
- `Keys(array $constraints)` — `Composite`: каждый ключ массива (`(string)`, PHP делает числовые ключи целыми)
  проверяется вложенными constraint'ами в контексте, путь `[ключ]`. Только массив (map DTO — всегда массив); иное —
  `UnexpectedValueException` → нарушение `Type`.
- `Contains(array $constraints, int $min = 1, ?int $max = null)` — `Composite`: элемент «подходит», когда отдельная
  проверка (`getValidator()->validate($item, $constraints, $group)`) не даёт нарушений; число подходящих `< min` или
  `> max` → нарушение на самом списке (`{{ limit }}`, `{{ count }}`, множественное число как у `Count`). `min` ≥ 0,
  `max` ≥ `min` — иначе `ConstraintDefinitionException`. `Valid` внутрь нельзя (запрет `Composite`).

## 4. Мост

- **Вывод:** `ConstraintSpec` получает второе пространство: `RuntimeAssert` (`ImportAlias(…\Runtime\Constraints,
  'RuntimeAssert')`).
- **Наличие рантайма:** constraint пишется, только если в проекте установлен рантайм ≥ 1.1 (`InstalledPackages`;
  нечисловая версия, например `dev-main`, считается новой). Иначе — warning на месте ключевого слова: «"X" is checked
  by msstc4symfony/dto-generator-bridge-runtime 1.1 or newer, which the project does not install; it is not checked.»
  Так мост не зависит от тега рантайма до релиза; требование `^1.1` в `composer.json` — при релизе.
- **Подсхема** (`contains`, `propertyNames`) — только «плоская»: `true`, `{}` или объект из `type`, `format`, `enum`,
  `const`, `pattern`, `minLength`, `maxLength`, `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`,
  `multipleOf` и аннотаций (`title`, `description`, `examples`, `$comment`, `default`, `deprecated`, `x-*`). Её
  constraint'ы строит тот же `ConstraintBuilder::build()` для типа элемента (для имён — `string`). `type` допустим, только
  если он не сужает тип элемента (`number` покрывает `int`). Иное (`$ref`, `properties`, `items`, `allOf`, `not`, `false`…)
  → warning «"X" is checked only with a schema of type, format, enum, const and the string and number keywords; it is not
  checked.» (с именем первого лишнего ключа).
- **`contains`** — в `ConstraintBuilder::build()` для `ListType`: `Contains(constraints, min, max)`; `minContains`
  (по умолчанию 1), `maxContains` — через `KeywordReader` (неотрицательные целые); `minContains: 0` без `maxContains` —
  ничего. `minContains`/`maxContains` без `contains` — ничего (по JSON Schema не действуют). Вложенные constraint'ы —
  `new`, поэтому гейт `allowsNew()` как у `All`. На значении другого типа — warning «"contains" is checked on arrays
  only; it is not checked here.» (только если ключ есть).
- **`propertyNames`** — для `MapType` (свойство-map и `$additionalProperties`): `Keys(constraints)`. У
  `$additionalProperties` ключевое слово — в схеме класса: `ValidatorEnricher` становится и `ClassEnricher`, запоминает
  ключевые слова класса в `enrichClass()` (ядро вызывает его до `enrichProperty()` свойств этого класса — порядок
  документируется в контракте ядра) и берёт их для свойства, у которого `owner()` — этот класс. Имена объявленных свойств
  не проверяются (их задаёт сама схема). Класс без `$additionalProperties` — ничего.
- **`dependentRequired`** — `ValidatorEnricher::enrichClass()`: пары из ключевых слов класса (`Keywords`: `$ref`-цепочка и
  ветки `allOf`), имена → PHP-имена свойств класса (`ClassModel::properties()`). Пара, где одно из имён — не свойство
  этого класса: warning «"dependentRequired" names "x", which is not a property of this class; the pair is not checked.»;
  исключение — класс с родителем (`allOf` + `extends`) и пара, где **оба** имени не свои: её проверяет родитель, если она
  в его схеме. Значение не объект `{имя: [имена]}` — warning и пропуск. Map-тип с `dependentRequired` — warning «"dependentRequired" is checked on classes only; it is not checked here.»
- Группы и `x-validator-skip` класса — как у свойств (`x-validator-groups` схемы класса, иначе настройка).
- Порядок на свойстве: после constraint'ов коллекций (§5.1 моста), перед `Choice`.

## 5. Ядро

`UnsupportedKeywords::NO_EFFECT` теряет `dependentRequired`, `propertyNames`, `contains`, `minContains`, `maxContains`
(«Keywords the Symfony bridge turns into constraints are not listed»); `docs/openapi-support.md` и CHANGELOG. Контракт
`ClassEnricher` документирует порядок: класс — до его свойств. Выпускать ядро 1.4 вместе с мостом 1.3 и рантаймом 1.1
(иначе ядро 1.4 с мостом 1.2 молча теряет предупреждения).

## 6. Совместимость

Вывод меняется только у схем с этими ключевыми словами: новые constraint'ы (CHANGELOG моста — Added; DTO начинают
отвергать данные, которые раньше проходили — Changed). Рантайм — Added.

## 7. Вне рамок

- Проверка имён объявленных свойств по `propertyNames` во время генерации.
- Подсхемы с `$ref`/объектами в `contains`/`propertyNames`; `contains` для объектов.
- `dependentRequired` между свойствами родителя и потомка при `extends`.
- `dependentSchemas`, `not`, `prefixItems`, `unevaluated*` — без изменений (warning ядра).

## 8. Тестирование

- Рантайм: юнит-тесты валидаторов (`ConstraintValidatorTestCase`) на всех версиях матрицы; аннотации на PHP 7.4 (задание
  CI); Infection 100 %.
- Мост: эмиссия (атрибуты 8.2, аннотации 7.4, PHP 8.0 без `new`), предупреждения (нет рантайма, старый рантайм, сложная
  подсхема, чужое имя в `dependentRequired`, ключ не на своём типе), группы; `RealSymfonyTest` — настоящие валидаторы
  (пропуск без классов рантайма 1.1); матрица 5.4/6.4/7.4/8; Infection 100 %.
- Ядро: `UnsupportedKeywords` и известные списки.
