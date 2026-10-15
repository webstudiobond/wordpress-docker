# WordPress Update & Network Optimization

* **Slug:** `performance`
* **Source:** `examples/data/wp-content/mu-plugins/wp-performance.php.example`

## Overview

Optimizes WordPress admin update cycles and container outbound networking:

* Calls `fastcgi_finish_request()` immediately after upgrade routines finish printing output to the browser, releasing the browser tab while allowing background post-update cleanup tasks to complete asynchronously.
* Enforces IPv4 resolution for outbound HTTP requests (`wp_remote_get`, `wp_remote_post`) via the `http_api_curl` action, preventing timeout delays on Docker bridges that lack outbound IPv6 routing.

## Configuration (`wordpress.env`)

* `WP_PERF_FASTCGI_FINISH=true`
  Flushes HTTP response to the client immediately before executing background post-update hooks. Set to `false` or `0` to wait for all hooks before closing the connection.
  Example: `WP_PERF_FASTCGI_FINISH='true'`

* `WP_PERF_FORCE_IPV4=true`
  Sets `CURLOPT_IPRESOLVE` to `CURL_IPRESOLVE_V4` for all outbound WordPress cURL requests. Set to `false` or `0` if the Docker host and network have native dual-stack IPv6 connectivity.
  Example: `WP_PERF_FORCE_IPV4='true'`
