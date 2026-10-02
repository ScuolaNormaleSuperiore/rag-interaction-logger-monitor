# Unit Tests

Fast tests for pure PHP logic: filters, UTC/local time conversion, row mapping, query-fragment validation, statistics, and authorization rules. No WordPress or database.

## Setup

```bash
composer install
```

## Run

```bash
composer test:unit
```

To run both test suites, use `composer test:all` after completing the integration-test setup.

## Conventions

- Files end with `Test.php`, live in `tests/unit/`, use the `RILM\Tests\Unit` namespace and extend `PHPUnit\Framework\TestCase`.
- Classes under test are autoloaded by Composer (PSR-4, `RILM\` => `includes/`). `tests/bootstrap-unit.php` only loads `vendor/autoload.php`: no per-class `require`.
- Anything that needs WordPress hooks, the admin menu, capabilities, nonces, the WordPress test database, or plugin bootstrap belongs in `tests/integration/`.

## Pre-commit

`.githooks/pre-commit` runs `composer test:unit` when `vendor/bin/phpunit` exists. Enable the hooks once per clone:

```bash
git config core.hooksPath .githooks
```

Keep this suite fast and deterministic.
