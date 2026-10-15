# Client-Side Telemetry & Tracker Blocker

* **Slug:** `telemetry-blocker`
* **Source:** `examples/data/wp-content/mu-plugins/wp-telemetry-blocker.php.example`

## Overview

Intercepts external analytics, phone-home telemetry, and diagnostic tracking calls directly in the browser:

* Injects an early inline script hook into `admin_head` and `wp_head` that wraps browser `fetch()`, `XMLHttpRequest.prototype.open()`, and `navigator.sendBeacon()`.
* Intercepts matching outbound network requests and returns a synthetic HTTP `200 OK` response with empty JSON (`{}`) payload, preventing JavaScript errors and broken UI states that occur when scripts are hard-blocked by CSP or network errors.
* Evaluates requests against a declarative list of blocked domains, URLs, or path substrings. Inactive by default when `WP_TELEMETRY_BLOCKED_URLS` is empty.

## Configuration (`wordpress.env`)

* `WP_TELEMETRY_BLOCKED_URLS=`
  Comma-separated list of domain names, URL prefixes, or substrings to intercept. Matching is case-insensitive.
  Example: `WP_TELEMETRY_BLOCKED_URLS='telemetry.example.com,tracking.example.org/collect,stats.pluginprovider.net'`
