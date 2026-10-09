=== RAG Interaction Logger Monitor ===
Contributors: ict-sns
Tags: rag, logs, monitoring, chatbot, admin
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Read-only dashboard, search and anomaly views for the interaction logs written by RAG Interaction Logger.

== Description ==

RAG Interaction Logger Monitor is an administrator-only backoffice for the data that the RAG Interaction Logger plugin writes to an external MySQL or MariaDB database. It never changes the logs: it only runs `SELECT` statements.

**Pages**

* **Dashboard** – turns, average and median duration, indicators (generated, fast reply, incomplete, blocked input and output, turns Guardrails did not handle, answers generated without recalled sources, turns that used tools), the use of each tool or form, and blocks by verdict. A collapsible legend explains every indicator. A "Test status" box shows the last interaction recorded, completed turns and incompleteness, Guardrails coverage, the block rate on only the turns Guardrails covered, and the tool-invocation rate when available.
* **Daily trend** – the same figures charted over time, one bar per day, week or month depending on how long the chosen period is, so the chart stays readable whatever the period. Its table, open by default, also shows each count as a percentage of that row's own turns.
* **Interactions** – a paginated list with filters (period, text search, Guardrails, verdicts, outcome, instance, user, tools and more) and sorting.
* **Interaction detail** – every field of one interaction, with a comparison of the generated and delivered answers. Recalled sources are always shown, with a caution note that recall does not prove a source was cited or used in the delivered answer, and, when the logger records them, each source's title, a link opening in a new tab, origin, WordPress ID and type.
* **Anomalies** – predefined views (incomplete turns, turns without Guardrails, blocks, answers changed without a verdict, empty recall, tools that ran in a risky turn), each with a count and a link to the filtered list.
* **Settings** – the connection to the log database.

**Good to know**

* Only users with the `manage_options` capability can open the pages.
* The plugin has no frontend output, shortcode, block or public REST endpoint.
* The text you search for is sent in the request body and never appears in a URL.
* Dates are shown, and periods are read, in the site time zone; the log stores UTC.
* The interface follows each user's language. English and Italian are included. The logged data (questions, answers, verdicts) is never translated.

**Requirements**

* WordPress 7.1 or later, PHP 8.3 or later with the `sodium` extension.
* RAG Interaction Logger writing to a MySQL or MariaDB table (by default `ril_interactions`).
* A database account that can only `SELECT` from that table.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/rag-interaction-logger-monitor`, or install it from the Plugins screen, and activate it.
2. Create a database account with read-only access to the log table (example below), using the narrowest host you can instead of `%`.
3. Set the connection under **Monitor RAG > Settings**, or define constants in `wp-config.php` (example below). A constant always takes precedence over the saved value.
4. To save the password from the Settings page instead of using the constant, make sure `wp-config.php` defines unique `SECURE_AUTH_KEY` and `SECURE_AUTH_SALT`, as WordPress installs normally do.

Read-only account:

    CREATE USER 'rilm_reader'@'%' IDENTIFIED BY '<strong password>';
    GRANT SELECT ON `rag-interaction-logger-db`.`ril_interactions` TO 'rilm_reader'@'%';

Constants in `wp-config.php`:

    define( 'ICT_RAG_MONITOR_DB_HOST', 'db.example.org' );
    define( 'ICT_RAG_MONITOR_DB_PORT', 3306 ); // optional, default 3306
    define( 'ICT_RAG_MONITOR_DB_NAME', 'rag-interaction-logger-db' ); // optional
    define( 'ICT_RAG_MONITOR_DB_TABLE', 'ril_interactions' ); // optional
    define( 'ICT_RAG_MONITOR_DB_USER', 'rilm_reader' );
    define( 'ICT_RAG_MONITOR_DB_PASSWORD', '<password>' );

== Frequently Asked Questions ==

= Does the plugin change or delete my logs? =

No. It opens its own connection to the log database and refuses any statement that is not a `SELECT`. Use an account that has only the `SELECT` privilege, so the database enforces the same rule.

= Where is the database password stored? =

Either in `wp-config.php` as `ICT_RAG_MONITOR_DB_PASSWORD`, or in the database of your site, encrypted with a key derived from your security keys. It is never shown again after saving. If those keys change, the saved password can no longer be read: type it again.

= My database account requires SSL. How do I connect? =

The plugin uses the TLS settings of your site's own database connection. Define `MYSQL_CLIENT_FLAGS` in `wp-config.php` (for example `MYSQLI_CLIENT_SSL`).

= The Dashboard has no tools row, or the Interactions page has no tools filter. =

Those features need the `tools_used` column, which older versions of RAG Interaction Logger do not create. Without the column they are hidden.

= What happens if the log database is unreachable? =

The plugin pages show a message and nothing else is affected: your site and its frontend keep working, and no connection detail is displayed.

= Why does a tool count add up to more than the turns that used tools? =

A turn that used several tools or forms is counted once in each of them.

== Privacy ==

The plugin shows, to administrators only, what the logger recorded: questions, answers, user identifiers and tool data. These are personal data of your visitors; decide who may be an administrator and for how long the logger keeps them. The plugin stores no interaction data in your WordPress database, sets no cookies and contacts no external service other than the log database you configure. It stores only the connection settings (and, if you choose, the encrypted password) in two options, which are removed when the plugin is deleted.

== Changelog ==
= 1.0.2 =
* Added a Daily trend page, split out of the Dashboard, with charts that adapt to day, week or month depending on the period.
* Added a "Test status" box to the Dashboard.
* Added percentages to the Daily trend table, now open by default.
* Recalled sources now show title, link, origin, WordPress ID and type when the logger records them.
* Clearer wording for Guardrails and Tool across the plugin.

= 1.0.1 =
* Bug fixing.
* Added the Tools column to the Interactions list.
* Removed the Instance column from the Interactions list.
* Default period is now Today, configurable in Settings.

= 1.0.0 =
* First version: Dashboard with indicator legend, Daily trend, Interactions list with filters and search, Interaction detail, Anomalies, tools and forms filters and figures, Settings with encrypted password, Italian translation.

== Upgrade Notice ==

= 1.0.2 =
New Daily trend page, Dashboard test-status box, richer recalled sources, and clearer Guardrails/Tool wording.

= 1.0.1 =
Bug fixes, a Tools column instead of Instance in the Interactions list, and a configurable default period (Today).

= 1.0.0 =
First version.
