# RAG Interaction Logger Monitor

An administrator-only, read-only backoffice for the data written by **RAG Interaction Logger**. It reads the `ril_interactions` table and helps you spot anomalies and inspect single interactions.

Requires WordPress 7.1+ and PHP 8.3+ (with the `sodium` extension), and a MySQL or MariaDB database that holds the logger table.

## Features

- **Dashboard** – turns, durations, indicators (generated, fast reply, incomplete, blocks, Guardrails, recall, tools), the use of each tool or form, and blocks by verdict, with a collapsible legend; a "Test status" box shows the last interaction recorded, completed turns and incompleteness, Guardrails coverage, the block rate on only the turns Guardrails covered, and the tool-invocation rate when available.
- **Daily trend** – the same figures charted over time: one bar per day up to a month, per week up to about six months, and per month beyond that, so a chart never has to draw too many bars. Its table, open by default, also shows each count as a percentage of that row's own turns.
- **Interactions** – a paginated list with filters and a text search; the search text travels in a POST body, never in a URL.
- **Detail** – every field of one interaction, with a comparison of the generated and delivered answers; recalled sources, always shown, include a caution note and, when the logger records them, a document's title, a link opening in a new tab, origin, WordPress ID and type.
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

## Build

```bash
composer build
```

Creates `dist/rag-interaction-logger-monitor-<version>.zip`, ready to install on WordPress. The version is read from the plugin header, so update it there (and in `readme.txt`) before building. The package holds only what the plugin needs at run time: the code, `languages/`, `LICENSE`, `readme.txt` and `uninstall.php`. Everything listed in `.distignore` (tests, Composer files and dependencies, hooks, documents, hidden files) is left out, because the plugin has no run-time dependencies. Rebuild the translation catalogs first if you changed any text (`composer i18n:pot` and `composer i18n:mo`). The PHP `zip` extension is required.

## License

GPL-3.0-or-later.
