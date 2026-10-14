
- PHP 7.4 builds bundle PCRE2 10.35, which compiles an unknown Unicode property such as `\p{Foo}` (it matches nothing);
  PCRE2 10.44 refuses it, and 10.43+ also accepts variable-length lookbehind. A test that needs a pattern PCRE cannot
  compile on every PHP line uses an unbalanced group (`a(`).

- **`Valid` не вкладывается ни в какой Composite (2026-10-14 UTC).** Symfony `Composite::initializeNestedConstraints` бросает `ConstraintDefinitionException` для `Valid` внутри любого составного ограничения (`Property`, `IfThenElse`, `All`…), причём только при загрузке метаданных — генерация и `php -l` это не ловят. `ConstraintBuilder::build()` (через `nested()`) единственный даёт `Valid`; `narrowings()` его отбрасывает — объект обходит `Valid` свойства родителя. Нашлось на OpenAI: общие свойства вариантов поднимаются в union-базу, а декларация варианта становится «сужением». Корпусный `load.php` читает метаданные Validator — гонять его после изменений в составных ограничениях.
- **Интерфейсная union-база (core 1.6, `ClassKind::INTERFACE`)** мосту не требует особой обработки: `DiscriminatorMap` на интерфейсе Symfony читает при (де)нормализации на 5.4–8 (`RealSymfonyTest::testBuildsTheVariantAnInterfaceSelects`); у интерфейса нет свойств, поэтому энричеры свойств его не трогают. Порядок путей нарушений между 5.4/6.4 и 7+ различается — сортировать в тестах.
