=== RAG Interaction Logger Monitor ===
Contributors: ict-sns
Tags: rag, logs, monitoring, admin
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Administrator-only monitor for RAG Interaction Logger data.

== Description ==

RAG Interaction Logger Monitor lets WordPress administrators inspect and analyse interaction logs from an external MySQL or MariaDB source.

The plugin has no frontend output, public REST API, shortcode, or block. The external database host, port, database, table, user and password are set on the plugin Settings page. The password is saved encrypted with a key derived from the security keys in `wp-config.php`, and is never shown again; it can alternatively be defined as the constant `ICT_RAG_MONITOR_DB_PASSWORD`, which takes precedence.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/rag-interaction-logger-monitor` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Make sure `wp-config.php` defines unique security keys (`SECURE_AUTH_KEY` and `SECURE_AUTH_SALT`), which WordPress installs normally do. Without them the password cannot be saved from the Settings page.
4. Set the database host, port, database, table, user and password under Monitor RAG > Settings, or define `ICT_RAG_MONITOR_DB_PASSWORD` in `wp-config.php` instead of saving the password.

== Changelog ==

= 0.1.0 =
* Initial project skeleton.

