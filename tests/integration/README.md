# Integration Tests

Tests based on `WP_UnitTestCase` that boot a real WordPress test environment (`wordpress-develop`) and load the plugin: admin hooks, capability and nonce enforcement, local-time display, and safe connection failure handling.

## One-time setup

1. `composer install` in the plugin root.
	The development dependencies include the PHPUnit Polyfills required by the current WordPress test framework.
2. Clone `wordpress-develop`:
   ```bash
   git clone https://github.com/WordPress/wordpress-develop.git C:/WordpressDEV/wordpress-develop
   ```
   Another path is fine if `WP_TESTS_DIR` points to its `tests/phpunit` folder.
3. Create an **empty, dedicated** database, e.g. `rag-interaction-logger-tests`, on the MySQL server of a running Local site (host `127.0.0.1`, port from the site's *Database* tab):
   ```sql
   CREATE DATABASE IF NOT EXISTS `rag-interaction-logger-tests` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   The suite drops and recreates the `wptests_` tables on every run. Never point it at the Local site's own database, any shared database, or the external RAG logging database/table (`rag-interaction-logger-db.ril_interactions` by default).
4. Copy `wp-tests-config-sample.php` to `wp-tests-config.php` in `wordpress-develop/tests/phpunit/` (or `wordpress-develop/`, per its docs) and set `ABSPATH` to `wordpress-develop/src/`, `DB_NAME=rag-interaction-logger-tests`, `DB_USER`, `DB_PASSWORD` and `DB_HOST=127.0.0.1:<port>`.
5. Check the connection (optional). Set all dedicated test-database variables in the shell; do not use production or external-log database credentials:
   ```powershell
	$env:RILM_TEST_DB_HOST = "127.0.0.1"
   $env:RILM_TEST_DB_PORT = "10089"
	$env:RILM_TEST_DB_USER = "<test-db-user>"
	$env:RILM_TEST_DB_PASSWORD = "<test-db-password>"
	$env:RILM_TEST_DB_NAME = "rag-interaction-logger-tests"
   php tests/check-db-connection.php
   ```

The Local MySQL port can change when sites are restarted: update `DB_HOST` if the connection fails.

## Run

```powershell
# Only if wordpress-develop is not in C:/WordpressDEV/wordpress-develop
$env:WP_TESTS_DIR = "C:/your/path/wordpress-develop/tests/phpunit"
composer test:integration
```

To run unit and integration tests in sequence, use `composer test:all`.

The Local site must be running (MySQL only runs while the site is on).

## Conventions

- Files end with `Test.php`, live in `tests/integration/`, use the `RILM\Tests\Integration` namespace and extend `WP_UnitTestCase`.
- `tests/bootstrap-integration.php` loads the Composer autoloader and the plugin main file on `muplugins_loaded`.

## Troubleshooting

- `WordPress test library not found`: check `WP_TESTS_DIR`.
- Database connection errors: check `wp-tests-config.php`, the port, and that the Local site is running.
- No tests executed: file names must end with `Test.php`.
