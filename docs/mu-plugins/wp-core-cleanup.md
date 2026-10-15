# Core Head, Header & Comment Cleanup

* **Slug:** `core-cleanup`
* **Source:** `examples/data/wp-content/mu-plugins/wp-core-cleanup.php.example`

## Overview

Purges unnecessary HTML `<head>` tags, security-leaking HTTP headers, and comment spam vectors:

* Strips WordPress version generators, RSD links, WLW manifest tags, shortlinks, REST discovery links, RSS/Atom feed links, oEmbed discovery links, DNS prefetch hints, and profile link elements.
* Removes the `X-Powered-By` and `X-Pingback` HTTP headers and prevents internal self-pingback loops.
* Disables WordPress Emoji scripts and styles from frontend pages and admin screens.
* Hardens comment forms by removing the website URL field, disabling auto-conversion of plain text URLs to clickable links, and dequeuing `comment-reply.js`.
* Provides optional switches to disable frontend Gutenberg inline `global-styles` CSS and SVG filters, `jquery-migrate`, and Speculative Loading.

## Configuration (`wordpress.env`)

* `WP_CLEANUP_HEAD_TAGS=generator,wlwmanifest,rsd,shortlink,rest_links,feeds,oembed,dns_prefetch,profile`
  Comma-separated list of head tags to remove, `*` for all, or `none` to disable removal.
  Available targets: `generator`, `wlwmanifest`, `rsd`, `shortlink`, `rest_links`, `feeds`, `oembed`, `dns_prefetch`, `profile`.
  Example: `WP_CLEANUP_HEAD_TAGS='*'`

* `WP_CLEANUP_HIDE_POWERED_BY=true`
  Removes the `X-Powered-By` HTTP response header. Set to `false` to keep.
  Example: `WP_CLEANUP_HIDE_POWERED_BY='true'`

* `WP_CLEANUP_DISABLE_SELF_PING=true`
  Blocks pingbacks directed to the site's own domain and removes the `X-Pingback` HTTP header. Set to `false` to keep.
  Example: `WP_CLEANUP_DISABLE_SELF_PING='true'`

* `WP_CLEANUP_DISABLE_EMOJIS=true`
  Removes core Emoji detection scripts, inline styles, TinyMCE emoji plugins, and email emoji filters. Set to `false` to keep.
  Example: `WP_CLEANUP_DISABLE_EMOJIS='true'`

* `WP_CLEANUP_COMMENTS=url_field,make_clickable,reply_js`
  Comma-separated list of comment cleanups to apply, `*` for all four, or `none` to disable.
  Available targets: `url_field` (removes website field), `make_clickable` (disables auto-linking in comments), `reply_js` (dequeues comment-reply script), `recent_styles` (removes inline styles for recent comments widget).
  Example: `WP_CLEANUP_COMMENTS='url_field,make_clickable,reply_js'`

* `WP_CLEANUP_DISABLE_GLOBAL_STYLES=false`
  Removes Gutenberg `global-styles-inline-css` and SVG duotone filters from frontend HTML. Set to `true` when building classic themes or custom CSS design systems.
  Example: `WP_CLEANUP_DISABLE_GLOBAL_STYLES='true'`

* `WP_CLEANUP_DISABLE_JQUERY_MIGRATE=false`
  Deregisters `jquery-migrate` from frontend pages if your active theme and scripts use modern jQuery APIs.
  Example: `WP_CLEANUP_DISABLE_JQUERY_MIGRATE='true'`

* `WP_CLEANUP_DISABLE_SPECULATIVE_LOADING=false`
  Disables WordPress Speculative Loading rules (`wp_enqueue_speculation_rules`). Set to `true` to block pre-rendering behavior.
  Example: `WP_CLEANUP_DISABLE_SPECULATIVE_LOADING='true'`
