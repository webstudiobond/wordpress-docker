# Conditional Snippets, Preloads & Asset Control

* **Slug:** `snippets`
* **Source:** `examples/data/wp-content/mu-plugins/wp-snippets.php.example`

## Overview

Controls header/footer asset loading, Google Tag Manager injection, preloads, and file-based code snippets:

* Evaluates condition rules (`target|audience|device|location|acf`) without database lookups or `eval()` execution.
* Injects Google Tag Manager containers with preconnect hints.
* Adds `<link rel="preload">` resource hints for local fonts and critical hero images.
* Dequeues and deregisters unwanted plugin/theme styles and scripts conditionally.
* Injects static `.html`, `.css`, and `.js` snippet files directly from `/etc/wordpress/snippets`.

## Condition Modifiers Syntax

Format: `target|modifier1|modifier2; target2|modifier1`

* **Audience:** `guest` (unauthenticated only), `auth` (logged-in only), `role:editor,author`, `all` (default for all rules except `WP_GTM_ID`, which defaults to `guest`).
* **Device:** `desktop` (`!wp_is_mobile()`), `mobile` (`wp_is_mobile()`).
  Note: When full-page caching is active, device modifiers require separate mobile cache storage.
* **Location** (prefix with `!` to invert): `front`, `home`, `page`, `page:10,20,contact`, `single`, `single:42`, `singular:product,brand`, `archive`, `archive:product`, `category:1,news`, `tag:promo`, `tax:product_cat:12,shoes`.
* **ACF Field** (prefix with `!` to invert): `acf:field_name`, `acf:field_a,field_b` — checks if the ACF field evaluates as truthy.
* **Editor** (fonts and styles only): `editor` — also loads the asset inside the WordPress block editor and Elementor builder canvas.

## Configuration (`wordpress.env`)

* `WP_GTM_ID=`
  Injects GTM preconnect hints, head script, and body `<noscript>` iframe. Defaults to `guest` audience.
  Example: `WP_GTM_ID='GTM-ABC1234|all'`

* `WP_FONTS_PRELOAD=`
  Generates `<link rel="preload" as="font" ... crossorigin>` tags.
  Example: `WP_FONTS_PRELOAD='/wp-content/fonts/inter-bold.woff2|editor; /wp-content/fonts/inter-regular.woff2'`

* `WP_FONTS_STYLES=`
  Generates `<link rel="stylesheet">` tags.
  Example: `WP_FONTS_STYLES='/wp-content/fonts/custom-fonts.css|editor'`

* `WP_PRELOAD_IMAGES=`
  Generates `<link rel="preload" as="image">` tags.
  Example: `WP_PRELOAD_IMAGES='/wp-content/uploads/hero-desktop.webp|front|desktop; /wp-content/uploads/hero-mobile.webp|front|mobile'`

* `WP_DEREGISTER_STYLES=`
  Dequeues and deregisters CSS style handles when conditions match.
  Example: `WP_DEREGISTER_STYLES='wp-block-library,global-styles|!single; contact-form-7|!page:contact'`

* `WP_DEREGISTER_SCRIPTS=`
  Dequeues and deregisters JavaScript script handles when conditions match.
  Example: `WP_DEREGISTER_SCRIPTS='comment-reply|!single; wp-embed|all'`

* `WP_SNIPPETS_HEAD=`
  Injects snippets into `<head>`. HTML is output raw, `.css` is wrapped in `<style>`, `.js` is wrapped in `<script>`.
  Example: `WP_SNIPPETS_HEAD='critical-layout.css|front; schema.html|single'`

* `WP_SNIPPETS_BODY=`
  Injects snippets immediately after the opening `<body>` tag via `wp_body_open`.
  Example: `WP_SNIPPETS_BODY='analytics-body.html|guest'`

* `WP_SNIPPETS_FOOTER=`
  Injects snippets before the closing `</body>` tag via `wp_footer`.
  Example: `WP_SNIPPETS_FOOTER='cookie-consent.js|guest'`

### Discovering Registered Style & Script Handles

Inspect all registered styles and scripts on the site:

```bash
# List all registered style handles:
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli eval \
  'global $wp_styles; foreach ($wp_styles->registered as $handle => $d) { echo "$handle\n"; }'

# List all registered script handles:
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli eval \
  'global $wp_scripts; foreach ($wp_scripts->registered as $handle => $d) { echo "$handle\n"; }'
```

## Snippet Storage Security

Snippet files are read strictly from `/etc/wordpress/snippets`. Path traversal is blocked and files must be mounted into the container:

1. Create the host directory and set permissions:
   ```bash
   sudo mkdir -p /home/${SITE_USER}/snippets
   sudo chown -R root:${SITE_USER} /home/${SITE_USER}/snippets
   sudo chmod 0750 /home/${SITE_USER}/snippets
   sudo find /home/${SITE_USER}/snippets -type f -exec chmod 0640 {} +
   ```

2. Mount `./snippets` into `/etc/wordpress/snippets:ro` in `docker-compose.yaml`.
