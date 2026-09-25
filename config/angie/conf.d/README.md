# Angie Custom Configuration Directory (`conf.d`)

Place optional site-specific Angie configuration snippets here.
Snippets are mounted inside the `angie` container at `/etc/angie/conf.d/` as read-only.
Each snippet has a corresponding `.example` template that can be copied or renamed to `.conf`.

### `http` Context:
- `limit_zones.conf` (`limit_zones.conf.example`) — Rate-limiting zone definitions (pairs with `limit_all.conf`)
- `nginx-map.conf` (`nginx-map.conf.example`) — Custom site-specific maps (high-performance SEO redirects, rate-limit whitelisting, dynamic CORS)
- `firewall-7g.conf` (`firewall-7g.conf.example`) — Official 7G WAF rule maps for query string, request URI, and bad user agents

### `server` Context (Top):
- `allow-deny-access.conf` (`allow-deny-access.conf.example`) — IP access control (whitelisting/blacklisting based on trusted client real IP)
- `rocket-nginx.conf` (`rocket-nginx.conf.example`) — SatelliteWP WP Rocket static cache integration (alongside `rocket-nginx/` snippets)
- `wphide-nginx.conf` — WP Hide path rewriting rules

### `location /` Context:
- `blockbots.conf` (`blockbots.conf.example`) — Bad bot blocking rules
- `limit_all.conf` (`limit_all.conf.example`) — Rate limiting enforcement (pairs with `limit_zones.conf`)
- `cache.conf` (`cache.conf.example`) — Static caching headers / rules

### `location ~ \.php` Context:
- `fastcgi_cache.conf` (or `nginx.fastcgi_cache.conf`) — FastCGI microcaching directives
- `csp-dynamic-php.conf` (`csp-dynamic-php.conf.example`) — Specialized CSP rules for dynamic/authenticated PHP output (admin, logged-in sessions, checkout)

### `server` Context (Bottom):
- `prefetch-proxy.conf` (`prefetch-proxy.conf.example`) — Disallow Chrome prefetch-proxy crawler
- `redirect.conf` (`redirect.conf.example`) — Custom site SEO redirects
- `wordfence.conf` (`wordfence.conf.example`) — Wordfence WAF .user.ini protection
*(Note: W3 Total Cache rules are read directly from WordPress root at `/var/www/html/nginx[.]conf`)*

---

### Interdependent Snippet Pairs:
- **`limit_zones.conf` & `limit_all.conf`**: `limit_zones.conf` allocates rate-limiting memory zones in `http`, which are then enforced by `limit_all.conf` inside `location /`.

> **Note**: Angie uses glob syntax `[.]conf` for these includes, so if any file is omitted, Angie starts normally without errors.
