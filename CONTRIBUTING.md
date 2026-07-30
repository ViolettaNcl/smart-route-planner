# Contributing

[English version below](#contributing-english)

## Как начать

Проекту не нужна база данных, Composer нужен только для dev-инструментов
(PHPStan, PHP-CS-Fixer) — сама кодовая база работает без единой внешней
зависимости.

```bash
git clone https://github.com/ViolettaNcl/smart-route-planner.git
cd smart-route-planner
composer install          # ставит только dev-tooling, см. composer.json
php -S localhost:8000 -t public
```

Подробности по установке (XAMPP, Docker) — в
[`docs/setup_guide.md`](docs/setup_guide.md).

## Перед тем как открыть Pull Request

Прогоните все три проверки — они же выполняются в CI на каждый push:

```bash
composer run cs-check   # стиль кода (PSR-12 + правила проекта)
composer run stan       # статический анализ, PHPStan уровень 6
composer run test       # 132 автотеста
```

Или всё сразу:

```bash
composer run check
```

Если `cs-check` нашёл расхождения — `composer run cs` исправит их
автоматически.

## Стиль кода

- PSR-12 + правила из `.php-cs-fixer.dist.php` (короткий синтаксис
  массивов, одинарные кавычки, сортировка `use`).
- Новые публичные методы с параметрами-массивами — с PHPDoc-генериками
  (`array<string, float>`, а не голый `array`), чтобы PHPStan level 6
  оставался чистым.
- Комментарии на русском (основной язык кодовой базы), но это не жёсткое
  правило — понятный английский тоже нормально.

## Тесты

Свой минимальный test-runner без зависимостей, без PHPUnit
(`tests/run.php` + `tests/TestReporter.php`). Юнит-тесты используют
поддельные зависимости (`tests/Fakes/`) — никакого реального сетевого
обращения к Nominatim/OSRM. HTTP-интеграционные тесты (`tests/Http/`)
поднимают настоящий `php -S` и бьют по реальным `api/*.php`.

Добавляя новую фичу — добавьте тест рядом (по образцу существующих файлов
в `tests/`), а не только ручную проверку в браузере.

## Структура коммитов

Коротко и по делу, в повелительном наклонении: `Add X`, `Fix Y`, а не
`Added`/`Fixed`. Один коммит — одно логическое изменение.

## Вопросы и баги

Через [Issues](https://github.com/ViolettaNcl/smart-route-planner/issues) —
шаблоны есть в `.github/ISSUE_TEMPLATE/`.

---

## Contributing (English)

The project needs no database; Composer is only used for dev tooling
(PHPStan, PHP-CS-Fixer) — the codebase itself has zero runtime dependencies.

```bash
git clone https://github.com/ViolettaNcl/smart-route-planner.git
cd smart-route-planner
composer install          # dev-tooling only, see composer.json
php -S localhost:8000 -t public
```

See [`docs/setup_guide.en.md`](docs/setup_guide.en.md) for XAMPP/Docker
setup.

### Before opening a Pull Request

Run the same three checks CI runs on every push:

```bash
composer run cs-check   # code style (PSR-12 + project rules)
composer run stan       # static analysis, PHPStan level 6
composer run test       # 132 automated tests
```

Or all at once: `composer run check`. If `cs-check` finds issues,
`composer run cs` fixes them automatically.

### Code style

PSR-12 + the rules in `.php-cs-fixer.dist.php`. New public methods taking
array parameters should carry PHPDoc generics (`array<string, float>`, not
a bare `array`) to keep PHPStan level 6 clean.

### Tests

A minimal, dependency-free custom test runner (no PHPUnit) —
`tests/run.php` + `tests/TestReporter.php`. Unit tests use fake
dependencies (`tests/Fakes/`) — no real network calls to Nominatim/OSRM.
HTTP integration tests (`tests/Http/`) boot a real `php -S` and hit the
actual `api/*.php` endpoints. When adding a feature, add a test alongside
it rather than only checking it manually in a browser.

### Commit messages

Short, imperative mood: `Add X`, `Fix Y` (not `Added`/`Fixed`). One commit,
one logical change.
