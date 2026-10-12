# G — проверка `dependentRequired`, `propertyNames`, `contains`, `patternProperties`, `if/then/else`

**Дата:** 2026-10-12 (UTC). **Статус:** решение принято автономно; владелец выбрал пункт 2026-10-12 («начинай»: п. 6 —
ключевые слова, которые Validator не выражает без своих constraint'ов; спецификация моста §1 «Вне рамок»).
Внутренние спецификации ведутся по-русски, как все прежние (правило «код и пользовательские документы — по-английски»).

## 1. Проблема

Ядро предупреждает «"X" is not supported and has no effect on the generated type.» для `dependentRequired`,
`propertyNames`, `contains`/`minContains`/`maxContains`, `patternProperties`, `if`/`then`/`else`, и DTO принимает
больше, чем схема. Стандартных constraint'ов Symfony 5.4–8 для них нет (`When` — с 6.2 и требует expression-language).

## 2. Этапы

- **G1** (эта итерация): `dependentRequired`, `propertyNames`, `contains` + `minContains`/`maxContains`.
- **G2**: `patternProperties`, `if`/`then`/`else` — своя спецификация-дополнение после G1.

## 3. Ядро (1.4)

- `ExtensionRegistry::claimKeywords(string ...)`: расширение заявляет слова из списка `UnsupportedKeywords`. Парсер
  (`SchemaParser::claiming(KeywordChecks)`) вместо предупреждения записывает каждое вхождение в
  `Domain\Diagnostic\KeywordChecks`; энричеры отмечают проверенные или оговорённые своим предупреждением места через
  `PropertyContext/ClassContext::keywordChecks()->checked(место, слово)`; после обогащения `Generate\Action` выдаёт
  прежнее «not supported» для неотмеченных. Так недостижимое для расширения (ветки `oneOf`, слова рядом с `$ref`,
  отключённый валидатор) не теряется молча.
- Заявка слова вне списка — предупреждение, заявка игнорируется (новое ядро может поддержать слово, которое старый
  мост ещё заявляет). Метод интерфейса — намеренный BC-разрыв для реализующих (только двойники), в roave-baseline.
- Контракт `ClassEnricher`: класс обогащается до своих свойств.

## 4. Рантайм (1.1)

Namespace `Msstc4Symfony\DtoGeneratorBridge\Runtime\Constraints\`; атрибут и аннотация (`@Annotation`,
`@NamedArgumentConstructor`), конструктор с именованными параметрами, валидатор `<Имя>Validator`; `symfony/validator`
5.4–8 на PHP 7.4+ (`suggest`, `conflict <5.4`). Без `payload` (Rector стандарта дописал бы нативный `mixed`), имена
ошибок только `ERROR_NAMES` (Symfony 6.1+; статический `$errorNames` Rector делает `private`, ломая 5.4).

- `DependentRequired(array $requirements)` — на классе. Форма проверяется в конструкторе
  (`ConstraintDefinitionException`). Свойство «есть», когда не `null`; неинициализированное — нет. Значения — через
  `(array)`-приведение (без `setAccessible()`, устаревшего в PHP 8.5): точные ключи `\0Class\0name` по иерархии от
  класса к родителям, затем `\0*\0name`, затем `name`. Одно отсутствующее свойство — одно нарушение.
- `Keys(array $constraints)` — `Composite`, только массив; каждый ключ (`(string)`) — в контексте, путь `[ключ]`.
- `Contains(array $constraints, int $min = 1, ?int $max = null)` — `Composite`, только массив; элемент подходит, когда
  отдельная проверка не дала нарушений. Нарушение пишется в контекст, взятый в начале `validate()`: на Symfony < 7.4
  вложенный `Contains` переинициализирует общий экземпляр валидатора. Вложенные constraint'ы обязаны быть во всех
  группах композита (иначе элемент «подходил» бы по части схемы).

## 5. Мост

- `SymfonyExtension` заявляет пять слов, если `validator` не `false`. `ConstraintBuilder::claimed()` и
  `ValidatorEnricher::markChecked()` отмечают каждое место (`Keywords::schemas()`), которое мост проверил или о котором
  предупредил, в том числе члены `oneOf`/`anyOf` с несколькими значениями (предупреждение на свойстве: общий компонент
  мог быть отмечен через другое свойство); остальное (слова рядом с `$ref` на класс, `x-validator-skip`, нет
  валидатора) остаётся ядру. `minContains`/`maxContains` без `contains` в том же месте — предупреждение. В схеме с `x-php-type` мост молчит, как ядро.
- Требует ядро ^1.4 и рантайм ^1.1; `RuntimeConstraints` всё же проверяет рантайм ≥ 1.1 в `composer.lock`
  (ветка `dev-*` — новая) и иначе предупреждает: lock может отставать.
- `Subschema` (`contains`, `propertyNames`): только плоская схема; элементы — скаляры (иначе — только аннотации);
  `type` не уже PHP-типа элемента (без учёта null). Если схема отвергает `null` (`type`/`const`/`enum` без него), а
  элементы nullable, добавляется `NotNull`. Подсхема строится отдельным `ConstraintBuilder` со своими `Diagnostics`
  (общие склеивают повторы): любое предупреждение — constraint не пишется.
- `contains` — по каждому месту схемы со своими `minContains`/`maxContains`; на не-списке — предупреждение.
- Вложенные в `All` композиты (`All`, `Contains`, `Keys`) получают группы свойства: Symfony фиксирует группы
  вложенного композита при создании.
- `propertyNames` — `Keys` для map и для `$additionalProperties` (ключевые слова класса — в `ValidatorEnricher` по FQCN,
  с группами класса; `x-validator-skip` схемы значений их не снимает). Класс без `$additionalProperties` —
  предупреждение (кроме `additionalProperties: false`).
- `dependentRequired` (`DependentRequirements`): пары по местам; оба свойства свои — проверяются, кроме триггера с
  default (предупреждение); у класса с родителем пару, которую перечисляет схема, объявляющая оба свойства, оставляют
  ей; иначе — предупреждение «names … not a property» или «… the class inherits …». На не-классе — предупреждение.

## 6. Решения по ревью, оставленные без изменений

- Путь нарушения ключа `labels[key]` совпадает с путём значений — описано в README (CR-025).
- Элемент в `Contains` проверяется как корневое значение: `Expression`/`Callback` внутри не видят объекта; мост таких
  не пишет — README рантайма (CR-023).
- Ветка `dev-*` старой линии рантайма: Composer не поставит её рядом с мостом (`^1.1`) (CR-027).
- `Contains` без вложенных constraint'ов не сокращается до `count()`: ветка была бы эквивалентным мутантом (s1).

## 7. Вне рамок

- Проверка имён объявленных свойств по `propertyNames` во время генерации.
- Подсхемы с `$ref`/объектами в `contains`/`propertyNames`; `contains` для объектов и PHP-enum.
- `dependentRequired` между свойствами родителя и потомка при `extends`.
- `dependentSchemas`, `not`, `prefixItems`, `unevaluated*` — без изменений (warning ядра).

## 8. Тестирование

- Ядро: заявки в `RegistryTest`, `SchemaParserTest`, `LoadTest`, `GenerateTest`.
- Рантайм: настоящий валидатор на Symfony 5.4–8; аннотации через Doctrine (`AnnotationsTest`, Symfony < 7); задание CI
  на PHP 7.4 с 5.4 и `doctrine/annotations`; Infection 100 %.
- Мост: эмиссия, предупреждения, группы; `RealSymfonyTest` — настоящие валидаторы (8.2, 8.0, 7.4 с аннотациями на
  5.4/6.4); матрица 5.4/6.4/7.4/8; Infection 100 %.
