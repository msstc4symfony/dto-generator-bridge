# G3 — проверка `not`, `dependentSchemas`, `prefixItems` и `unevaluatedItems`

**Дата:** 2026-10-13 (UTC). **Статус:** владелец выбрал G3 («давай 2 + 3 + 4»); решения приняты автономно. Дополняет G1
(`2026-10-12-g-validation-keywords-design.md`) и G2 (`2026-10-12-g2-pattern-properties-conditionals-design.md`): заявки
ядра, гейт рантайма, `Subschema`, `ClassConditions` и правило «не проверить целиком — не пишем и предупреждаем»
остаются прежними.

## 1. Рантайм 1.2

- `Not(array $constraints = [], ?string $message = null, ?array $groups = null)` — на свойстве и на классе. Значение,
  прошедшее все вложенные (отдельная проверка, как `if`), — нарушение «This value is not allowed.»
  (`Not::NOT_ALLOWED_ERROR`); без вложенных нарушение у любого значения. `null` не проверяется. Группы — как у
  `IfThenElse`.
- `dependentSchemas` новых constraint'ов не требует: `IfThenElse` с `Present` в `if`.

## 2. Мост

- `ClaimedConstraints::KEYWORDS` += `not`, `dependentSchemas`, `prefixItems`, `unevaluatedItems`.
- **`not` у значения** (скаляр, enum, `mixed`): подсхема (`nullIsAbsent`: `null` — отсутствие) → `Not`. Подсхема без
  проверок (`{}`, `true`, только аннотации) даёт `Not` без вложенных: значение должно отсутствовать. `not: false` ничего
  не запрещает — место отмечается молча. `not: {type: 'null'}` (подсхема «только null») — предупреждение: `null` — это
  отсутствие, которого `Not` не видит.
- **`not` у класса** — ветка как в `ClassConditions` (`properties` → `Property`, `required` → `Present`); имена — свои
  свойства, наследование — как у условий; default под `properties` или в `required` — предупреждение (как у `if`).
- **`dependentSchemas` у класса**: для каждого имени — `IfThenElse(if: [Present([имя])], then: ветка)`; схема `false` —
  `Not([Present([имя])])`; схема без проверок — ничего. Имя и имена ветки — свои свойства (иначе, как у условий,
  предупреждение или молчаливая передача классу-владельцу); у имени-триггера с default — предупреждение (DTO не отличит
  отсутствие). У значения, не являющегося классом, — предупреждение «checked on classes only».
- **`prefixItems` у списка**: `Collection(fields: {i: [...]}, allowExtraFields: true, allowMissingFields: true)` на
  каждое место; элементы, чья подсхема ничего не проверяет, не пишутся; подсхема элемента — для типа элементов списка.
  Схема `items` рядом (в любом месте значения) — предупреждение: сгенерированный тип даёт её всем элементам, а она
  действует только после префикса. `items: false` рядом с `prefixItems` — `Count(max: n)`; `items: false` без него —
  `Count(max: 0)`. Не список — предупреждение.
- **`unevaluatedItems` у списка**: при `items` (схема или `true`) не действует — отметка молча; рядом с `contains`,
  `if`/`then`/`else`, `oneOf`/`anyOf` — предупреждение (оценку элементов ведут и они); `false` — `Count(max: n)`, где
  `n` — наибольшая длина `prefixItems` мест значения; `true` — ничего; схема без `prefixItems` — как `items` (`All` из
  подсхемы); схема рядом с `prefixItems` — предупреждение.
- `Collection` — композит (группы вложенных в `All`).

## 3. Вне рамок

- `unevaluatedProperties`: незаявленные свойства DTO теряет при десериализации, валидатору их не увидеть; остаётся
  предупреждение ядра.
- `prefixItems` рядом со схемой `items` (тип ядра), подсхемы с `$ref`/объектами.
- Различие `null` и отсутствующего ключа (как в G1/G2).

## 4. Тестирование

- Рантайм: настоящий валидатор 5.4–8, аннотации, группы, повторный вход, Infection 100 %.
- Мост: эмиссия, предупреждения, отметки ядра, группы, PHP 8.0 без `new`, аннотации для 7.4; `RealSymfonyTest` с `not`,
  `dependentSchemas`, `prefixItems`; матрица 5.4/6.4/7.4/8; Infection 100 %.
