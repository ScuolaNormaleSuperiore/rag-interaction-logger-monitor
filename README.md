# RAG Interaction Logger Monitor

An administrator-only, read-only backoffice for the data written by **RAG Interaction Logger**. It reads the `ril_interactions` table and helps you spot anomalies and inspect single interactions.

Requires WordPress 7.1+ and PHP 8.3+ (with the `sodium` extension), and a MySQL or MariaDB database that holds the logger table.

## Features

- **Dashboard** – turns, durations, indicators (generated, fast reply, incomplete, blocks, Guardrails, recall, tools) and daily charts, with a legend.
- **Interactions** – a paginated list with filters and a text search; the search text travels in a POST body, never in a URL.
- **Detail** – every field of one interaction, with a comparison of the generated and delivered answers.
- **Anomalies** – predefined views, each linking to the filtered list.
- **Settings** – the connection to the log database.

The plugin only runs `SELECT` statements and never changes the logs. Access requires the `manage_options` capability. The interface follows the user's language (English and Italian are included).

## Installation

1. Copy the plugin folder to `wp-content/plugins/` and activate it.
2. Create a database account that can only read the log table:

   ```sql
   CREATE USER 'rilm_reader'@'%' IDENTIFIED BY '<strong password>';
   GRANT SELECT ON `rag-interaction-logger-db`.`ril_interactions` TO 'rilm_reader'@'%';
   ```

   Use the narrowest host you can instead of `%`.
3. Configure the connection (below).

## Configuration

Each value comes from, in order of precedence, a constant in `wp-config.php`, the **Monitor RAG → Settings** page, or the default.

| Constant | Default |
| --- | --- |
| `ICT_RAG_MONITOR_DB_HOST` | required |
| `ICT_RAG_MONITOR_DB_PORT` | `3306` |
| `ICT_RAG_MONITOR_DB_NAME` | `rag-interaction-logger-db` |
| `ICT_RAG_MONITOR_DB_TABLE` | `ril_interactions` |
| `ICT_RAG_MONITOR_DB_USER` | required |
| `ICT_RAG_MONITOR_DB_PASSWORD` | required |

```php
define( 'ICT_RAG_MONITOR_DB_HOST', 'db.example.org' );
define( 'ICT_RAG_MONITOR_DB_USER', 'rilm_reader' );
define( 'ICT_RAG_MONITOR_DB_PASSWORD', '<password>' );
```

The password can also be saved on the Settings page: it is stored encrypted with a key derived from the site's security keys (`SECURE_AUTH_KEY` and `SECURE_AUTH_SALT` must be unique), and it is never shown again. If those keys change, type the password again. A constant always takes precedence.

TLS uses the site's own database settings: if the account requires SSL, define `MYSQL_CLIENT_FLAGS` in `wp-config.php`.

## Development

```bash
composer install
composer lint              # WordPress Coding Standards
composer test:unit         # no WordPress needed
composer test:integration  # needs WordPress develop and a test database
composer audit
```

The integration suite reads its database from the `WP_TESTS_*` environment variables of the WordPress test library (see `tests/bootstrap-integration.php`).

Translations: `composer i18n:pot` rebuilds the catalog and `composer i18n:mo` compiles the `.po` files in `languages/`.

## License

GPL-3.0-or-later.
