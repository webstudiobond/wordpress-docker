# Angie Custom Configuration Directory (`conf.d`)

Place optional site-specific Angie configuration snippets here.
Snippets are mounted inside the `angie` container at `/etc/angie/conf.d/` as read-only.

### `http` Context:
- `limit_zones.conf` — Rate-limiting zone definitions (`limit_req_zone`, `limit_conn_zone`)
- `nginx-map.conf` — Custom maps (`map $uri $custom_var`, etc.)
- `wphide-firewall-nginx.conf` — Global firewall rules / WP Hide rules for http context

### `server` Context (Top):
- `allow-deny-nginx.conf` — IP allow/deny rules
- `rocket-nginx.conf` — WP Rocket Nginx acceleration rules
- `wphide-nginx.conf` — WP Hide rewriting rules

### `location /` Context:
- `blockbots.conf` — Bad bot blocking rules
- `limit_all.conf` — Rate limiting enforcement (`limit_req`, etc.)
- `avif.conf` — AVIF format rewrite / serving rules
- `webp.conf` — WebP format rewrite / serving rules
- `cache.conf` — Static caching headers / rules
- `fastcgi_cache.conf` (or `nginx.fastcgi_cache.conf`) — FastCGI microcaching directives
- `wphide-nginx-secyrity-headers.conf` — WP Hide security headers for PHP responses
- `fastcgi_cache_purge.conf` — FastCGI cache purge rules

### `server` Context (Bottom):
- `redirect.conf` — Custom redirects
- `security.conf` — Additional security headers & blocking rules
*(Note: W3 Total Cache rules are read directly from WordPress root at `/var/www/html/nginx[.]conf`)*

> **Note**: Angie uses glob syntax `[.]conf` for these includes, so if any file is omitted, Angie starts normally without errors.
