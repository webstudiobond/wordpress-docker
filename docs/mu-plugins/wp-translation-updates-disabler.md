# Translation Update Control

* **Slug:** `translation-updates-disabler`
* **Source:** `examples/data/wp-content/mu-plugins/wp-translation-updates-disabler.php.example`

## Overview

Controls automatic background language pack downloads and selective translation overrides:

* Prevents WordPress background cron updates and automatic upgrader runs from overwriting customized localization files (`.po`, `.mo`, `.l10n.php`) in `wp-content/languages/`.
* Disables automatic translation update checks via `auto_update_translation` filter while leaving manual dashboard update checks and core security updates functional.
* Filters `site_transient_update_translations` and `async_update_translation` to exclude specific plugin and theme slugs or all translations site-wide.

## Configuration (`wordpress.env`)

* `WP_TRANSLATION_DISABLE_AUTO_UPDATES=false`
  Blocks automatic background translation downloads via cron. When set to `true`, translation updates are only downloaded when manually initiated from Dashboard -> Updates.
  Example: `WP_TRANSLATION_DISABLE_AUTO_UPDATES='true'`

* `WP_TRANSLATION_BLOCKED_SLUGS=`
  Comma-separated list of plugin or theme slugs excluded from all translation downloads, or `*` to block translation updates for every installed package.
  Example: `WP_TRANSLATION_BLOCKED_SLUGS='woocommerce,elementor,my-custom-theme'`

### Discovering Installed Plugin & Theme Slugs

Retrieve exact slugs for installed plugins and themes using WP-CLI:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli plugin list --field=name
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli theme list --field=name
```
