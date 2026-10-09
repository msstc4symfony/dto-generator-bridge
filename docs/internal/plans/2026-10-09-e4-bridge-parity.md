# E4 — мост: паритет с CLI и форматы: план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Команда бандла `dto-generator:generate` готовит процесс так же, как CLI ядра; Validator получает `Range` из
`format: int32` и `Regex` из `format: byte`; поведение моста на выводе ядра 1.2 (смешанные `enum`, вынесенные члены
`oneOf`/`anyOf`, проверка дискриминатора в конструкторе варианта) закреплено тестами, в том числе на матрице Symfony.

**Architecture:** `GenerateCommand::run()` вызывает `DtoGenerator::prepareProcess($output)` перед `guard()`;
`GenerationCheckWarmer` его не вызывает. `int32` — неявные границы в `ConstraintBuilder::numbers()`: они участвуют в
выборе самой строгой границы наравне с `minimum`/`maximum`/`exclusive*`, поэтому получается один `Range` или пара
сравнений, как сейчас. `byte` — ещё одна строка таблицы `ConstraintBuilder::FORMATS` (`Regex`). Для значения
нескольких типов оба формата дают предупреждение «…not checked», как ключевые слова одного вида.

**Tech Stack:** PHP 7.4 (исходники моста), PHPUnit 9.6, PHPStan max (`phpstan.dist.neon`, `phpstan-symfony.neon`,
`phpstan-php74.neon`), Infection (MSI 100%), матрица Symfony 5.4/6.4/7.4/8 в Docker.

**Spec:** `../../msstc4php/dto-generator/docs/internal/specs/2026-10-09-e-features-design.md` §6, §9, §10 (решения
владельца — §11; E5 отложен).

## Global Constraints

- Исходники `src/` — PHP 7.4 (профиль `php74`): без promotion, `match`, union-типов, атрибутов, `str_contains()`.
- Комментарии в коде — английский, только неочевидное «почему». Без `@phpstan-ignore`.
- Ядро — `msstc4php/dto-generator ^1.2` во всех манифестах; path-репозитории (`composer-local.json`, `run-matrix.sh`)
  объявляют версию `1.2.0`.
- `uri` и `time` не добавляются (spec §6: `Url` отвергает `urn:`/`mailto:`/относительные ссылки, `Time` не знает
  RFC 3339 `full-time`).
- `int64` — без ограничения. `int32` — только у целочисленного значения, `byte` — только у строкового.
- После каждой задачи: `make fix`, `vendor/bin/phpunit`; в конце — все гейты из Task 7.

## Review Focus

1. `int32` рядом с более узкой схемой: `minimum: 0, maximum: 10` → один `Range(min: 0, max: 10)`, а не два
   ограничения (Task 3, `testMergesInt32WithTheSchemaBounds`).
2. `int32` рядом с исключающей границей: `exclusiveMaximum: 10` → `GreaterThanOrEqual(-2147483648)` +
   `LessThan(10)` — та же форма, что у схем без формата (Task 3).
3. `int32` вне диапазона схемы (`minimum: 3000000000`) — предупреждение «"minimum" and "format: int32" leave no valid
   value», без ограничений (Task 3).
4. Прогрев кэша не вызывает `prepareProcess()`: он идёт в долгоживущем процессе, warmup не меняет `memory_limit`
   (Task 2, `testLeavesTheProcessAloneOnWarmup`).
5. Иностранное значение дискриминатора при denormalize прямо в вариант — `\InvalidArgumentException` из конструктора
   (не исключение Serializer) на всех линиях Symfony (Task 6).

---

### Task 1: ядро `^1.2` во всех манифестах

**Files:**
- Modify: `composer.json`, `composer-ci.json`, `composer-local.json` (не в git), `tests/Integration/Symfony/run-matrix.sh`

- [ ] **Step 1:** `"msstc4php/dto-generator": "^1.2"` в `require` трёх манифестов; в `composer-local.json`
  `options.versions` → `1.2.0`; в `run-matrix.sh` — `'versions': {'msstc4php/dto-generator': '1.2.0'}`.
- [ ] **Step 2:** `COMPOSER=composer-local.json composer update msstc4php/dto-generator` (вне песочницы),
  `composer validate --strict --no-check-publish` для `composer.json`, `vendor/bin/phpunit` — зелёный.
- [ ] **Step 3: Commit** — `build: require msstc4php/dto-generator ^1.2`.

### Task 2: `prepareProcess()` в команде бандла

**Files:**
- Modify: `src/Bundle/Command/GenerateCommand.php`
- Test: `tests/Unit/Bundle/DtoGeneratorBundleTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function testPreparesTheProcessAsTheGeneratorCliDoes(): void
    {
        ini_set('memory_limit', '256M');
        ini_set('display_errors', 'stdout');

        self::assertSame(0, $this->command([])->execute(['--dry-run' => true]));

        self::assertSame('1G', ini_get('memory_limit'));
        self::assertSame('stderr', ini_get('display_errors'));
    }

    public function testLeavesTheProcessAloneOnWarmup(): void
    {
        $kernel = $this->bootedKernel(['check_on_warmup' => true]);
        $warmer = $this->service($kernel, GenerationCheckWarmer::class);
        assert($warmer instanceof GenerationCheckWarmer);
        ini_set('memory_limit', '256M');
        ini_set('display_errors', 'stdout');

        $warmer->warmUp($kernel->getCacheDir());

        self::assertSame('256M', ini_get('memory_limit'));
        self::assertSame('stdout', ini_get('display_errors'));
    }
```

`setUp()` запоминает `memory_limit`, `display_errors` и `DTO_GENERATOR_MEMORY_LIMIT` (сбрасывает его), `tearDown()`
восстанавливает.

- [ ] **Step 2:** `vendor/bin/phpunit --filter testPreparesTheProcessAsTheGeneratorCliDoes` — FAIL (`'256M'`).
- [ ] **Step 3: Implementation**

```php
    public function run(InputInterface $input, OutputInterface $output): int
    {
        DtoGenerator::prepareProcess($output);

        return DtoGenerator::guard($input, $output, fn (): int => parent::run($input, $output));
    }
```

PHPDoc класса: «…the same options, output, exit codes and process settings…».

- [ ] **Step 4:** тесты зелёные; `vendor/bin/phpstan analyse -c phpstan-symfony.neon`.
- [ ] **Step 5: Commit** — `feat: the bundle command prepares the process as the generator CLI does`.

### Task 3: `format: int32` → `Range`

**Files:**
- Modify: `src/Validator/ConstraintBuilder.php`
- Test: `tests/Unit/Validator/ValidatorEnricherTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function testBoundsInt32ValuesAndMergesTheSchemaBounds(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'plain' => ['type' => 'integer', 'format' => 'int32'],
            'narrow' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 0, 'maximum' => 10],
            'low' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 0],
            'wide' => ['type' => 'integer', 'format' => 'int32', 'minimum' => -3000000000, 'maximum' => 3000000000],
            'open' => ['type' => 'integer', 'format' => 'int32', 'exclusiveMaximum' => 10],
            'tie' => ['type' => 'integer', 'format' => 'int32', 'exclusiveMinimum' => -2147483648],
            'maybe' => ['type' => ['integer', 'null'], 'format' => 'int32'],
            'items' => ['type' => 'array', 'items' => ['type' => 'integer', 'format' => 'int32']],
            'long' => ['type' => 'integer', 'format' => 'int64'],
            'beyond' => ['type' => 'integer', 'format' => 'int32', 'minimum' => 3000000000],
            'either' => ['type' => ['integer', 'string'], 'format' => 'int32'],
        ]]);
        $code = $this->code($output, 'Pet.php');
        $int32 = 'Assert\\Range(min: -2147483648, max: 2147483647)';

        self::assertSame([$int32], $this->attributesOf($code, 'plain'));
        self::assertSame(['Assert\\Range(min: 0, max: 10)'], $this->attributesOf($code, 'narrow'));
        self::assertSame(['Assert\\Range(min: 0, max: 2147483647)'], $this->attributesOf($code, 'low'));
        self::assertSame([$int32], $this->attributesOf($code, 'wide'));
        self::assertSame(['Assert\\GreaterThanOrEqual(value: -2147483648)', 'Assert\\LessThan(value: 10)'], $this->attributesOf($code, 'open'));
        self::assertSame(['Assert\\GreaterThan(value: -2147483648)', 'Assert\\LessThanOrEqual(value: 2147483647)'], $this->attributesOf($code, 'tie'));
        self::assertSame([$int32], $this->attributesOf($code, 'maybe'));
        self::assertSame(['Assert\\All(constraints: [new \\Symfony\\Component\\Validator\\Constraints\\Range(min: -2147483648, max: 2147483647)])'], $this->attributesOf($code, 'items'));
        self::assertSame([], $this->attributesOf($code, 'long'));
        self::assertSame([], $this->attributesOf($code, 'beyond'));
        self::assertSame([], $this->attributesOf($code, 'either'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/beyond: "minimum" and "format: int32" leave no valid value; they are not checked.', $this->messages($output));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/either: "format: int32" checks only an integer, and the value may be of another type; it is not checked.', $this->messages($output));
    }
```

Плюс аннотации цели 7.4 (`@Assert\Range(min=-2147483648, max=2147483647)`) и правка
`testChecksEveryFormatThatApplies`: `count` (`int32` + `int64` из `allOf`) теперь получает `Range`.

- [ ] **Step 2:** FAIL — `plain` без ограничений.
- [ ] **Step 3: Implementation** — `numbers()` получает `bool $int32`; `strictest()` собирает кандидатов (сначала
  исключающие, затем включающие ключевые слова, последним — граница `int32` с меткой `format: int32`) и берёт самого
  строгого, при равенстве — первого: исключающая граница выигрывает у включающей, а явная — у неявной. `Range` пишется,
  когда обе границы не исключающие. Предупреждение для значения нескольких типов — новый `formatApplies()`.
- [ ] **Step 4:** тесты зелёные, `make infection` — без выживших.
- [ ] **Step 5: Commit** — `feat: Range for format int32`.

### Task 4: `format: byte` → `Regex`

**Files:**
- Modify: `src/Validator/ConstraintBuilder.php`
- Test: `tests/Unit/Validator/ValidatorEnricherTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function testChecksBase64OfByteStrings(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'blob' => ['type' => 'string', 'format' => 'byte', 'maxLength' => 8],
            'maybe' => ['type' => ['string', 'null'], 'format' => 'byte'],
            'many' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'byte']],
            'raw' => ['type' => 'string', 'format' => 'binary'],
            'either' => ['type' => ['integer', 'string'], 'format' => 'byte'],
        ]]);
        $code = $this->code($output, 'Pet.php');
        $base64 = "Assert\\Regex(pattern: '/^(?:[A-Za-z0-9+\\/]{4})*(?:[A-Za-z0-9+\\/]{2}==|[A-Za-z0-9+\\/]{3}=)?\$/D')";

        self::assertSame(['Assert\\Length(max: 8)', $base64], $this->attributesOf($code, 'blob'));
        self::assertSame([$base64], $this->attributesOf($code, 'maybe'));
        self::assertSame([], $this->attributesOf($code, 'raw'));
        self::assertSame([], $this->attributesOf($code, 'either'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/either: "format: byte" checks only a string, and the value may be of another type; it is not checked.', $this->messages($output));
    }
```

- [ ] **Step 2:** FAIL. **Step 3:** строка `'byte' => ['Regex', 'pattern', self::BASE64]` в `FORMATS`; модификатор `D`,
  как у `pattern` (`Pattern::toPcre()`): иначе `$` пропустил бы перевод строки в конце.
- [ ] **Step 4:** зелёные тесты, Infection. **Step 5: Commit** — `feat: Regex for format byte`.

### Task 5: тесты на вывод ядра 1.2 (E1)

**Files:**
- Test: `tests/Unit/Validator/ValidatorEnricherTest.php`

- [ ] **Step 1:** тесты (ожидаются зелёными сразу: они закрепляют поведение, которое мост уже даёт на выводе 1.2;
  проверить, что они падают при поломке — временно убрать `UnionType` из `kindOf()` и `Valid` из `nested()`):

```php
    public function testChoosesAmongTheValuesOfAMixedEnumAndLeavesKeywordsOfOneKind(): void
    {
        $output = $this->generate(['type' => 'object', 'properties' => [
            'level' => ['enum' => ['low', 1], 'minLength' => 2, 'minimum' => 0],
        ]]);

        self::assertSame(["Assert\\Choice(choices: ['low', 1])"], $this->attributesOf($this->code($output, 'Pet.php'), 'level'));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/level: "minLength" checks only a string, and the value may be of another type; it is not checked.', $this->messages($output));
        self::assertContains('warning /api.yaml#/components/schemas/Pet/properties/level: "minimum" checks only a number, and the value may be of another type; it is not checked.', $this->messages($output));
    }

    public function testCascadesIntoHoistedUnionMembers(): void
    {
        $code = $this->pet(['type' => 'object', 'properties' => [
            'either' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'title' => 'Bee', 'properties' => ['b' => ['type' => 'integer']]],
            ]],
            'many' => ['type' => 'array', 'maxItems' => 3, 'items' => ['anyOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]],
            ]]],
        ]]);

        self::assertStringContainsString('PetEitherOption1|Bee|null $either', $code);
        self::assertSame(['Assert\\Valid'], $this->attributesOf($code, 'either'));
        self::assertSame(['Assert\\Count(max: 3)', 'Assert\\Valid'], $this->attributesOf($code, 'many'));
    }
```

Плюс ограничения внутри вынесенного члена (`PetEitherOption1.php`: `Length` у `a`).

- [ ] **Step 2: Commit** — `test: mixed enums and hoisted union members of the core 1.2`.

### Task 6: матрица — дискриминатор E2, `int32`, `byte`, смешанный `enum`

**Files:**
- Modify: `tests/Integration/Symfony/api.yaml`, `tests/Integration/Symfony/RealSymfonyTest.php`

- [ ] **Step 1:** в `api.yaml`: у `Pet` — `count: {type: integer, format: int32, minimum: 0}`,
  `blob: {type: string, format: byte}`, `level: {enum: [low, 1]}`; схемы `Creature` (`oneOf` Fish/Bird,
  дискриминатор `kind` с mapping), `Fish`, `Bird`, `CreatureKind` (`enum: [fish, bird]`, на который ссылается `kind`).
- [ ] **Step 2:** в `violations()` — `count` выше int32 и ниже `minimum`, `blob` не base64 и с `\n` в конце, `level`
  вне списка; `VALID` получает `count`, `blob`, `level`.
- [ ] **Step 3:** новые тесты на всех целях (`targets()`):
  - `testBuildsTheVariantTheDiscriminatorSelects` — `Creature` с `kind: fish` → `Fish` (enum-дискриминатор: на 8.2
    case PHP-enum, на 8.0/7.4 — строка), normalize пишет `kind`;
  - `testTakesTheOnlyValueOfAVariantAsTheDefault` — denormalize прямо в `Cat`/`Fish` без ключа дискриминатора →
    значение по умолчанию; normalize пишет его;
  - `testRejectsAForeignDiscriminatorValueInAVariant` — `['animal_type' => 'dog']` прямо в `Cat` → ровно
    `\InvalidArgumentException` с сообщением ядра; `['kind' => 'bird']` в `Fish` — так же.
- [ ] **Step 4:** локально `vendor/bin/phpunit --testsuite=integration` (Symfony 8.1), затем матрица (Task 7).
- [ ] **Step 5: Commit** — `test: the discriminator check, int32 and byte against the real Symfony`.

### Task 7: документация, база знаний, гейты

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `SECURITY.md`, `CLAUDE.md`, `.claude/docs/README.md`

- [ ] **Step 1:** README: требования `^1.2`; строки таблицы форматов (`int32` → `Range`, `byte` → `Regex`; `int64`,
  `uri`, `time` — без ограничения и почему); раздел бандла без оговорки о `memory_limit`/`255`; заметка о
  `#[MapRequestPayload]` с типом варианта (иностранное значение → `\InvalidArgumentException` → 500; мапить на базу).
- [ ] **Step 2:** CHANGELOG `## [Unreleased]`: Added (`int32`, `byte`), Changed (`^1.2`, команда бандла как CLI,
  `Range` в существующих DTO с `int32`, смешанный `enum` → `Choice` с обоими видами и предупреждения «…not checked»).
- [ ] **Step 3:** `.claude/docs/README.md` — раздел E4. `SECURITY.md`, `CLAUDE.md` — `^1.2`.
- [ ] **Step 4: гейты** (вне песочницы): `make fix`; `COMPOSER=composer-local.json make check`; `vendor/bin/phpunit`;
  `vendor/bin/phpstan analyse -c phpstan-symfony.neon`; `vendor/bin/phpstan analyse -c phpstan-php74.neon`;
  `make infection` (MSI 100%); `php ../bundle-standard/bin/verify-standard.php .`; матрица
  `tests/Integration/Symfony/run-matrix.sh '5.4.*' 8.4-cli`, `'6.4.*' 8.4-cli`, `'7.4.*' 8.5-cli`, `'8.*' 8.5-cli`,
  `LOWEST=1 … '7.4.*' 8.4-cli`.
- [ ] **Step 5: Commit** — `docs: int32, byte and the bundle command's parity with the CLI`.
