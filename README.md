# Hardened Containerized WordPress

[![CI](https://github.com/webstudiobond/wordpress-docker/actions/workflows/ci.yml/badge.svg)](https://github.com/webstudiobond/wordpress-docker/actions/workflows/ci.yml)
[![GitHub last commit](https://img.shields.io/github/last-commit/webstudiobond/angie-docker-compose)](https://github.com/webstudiobond/wordpress-docker/commits/main)
[![GitHub issues](https://img.shields.io/github/issues/webstudiobond/angie-docker-compose)](https://github.com/webstudiobond/wordpress-docker/issues)
[![GitHub repo size](https://img.shields.io/github/repo-size/webstudiobond/angie-docker-compose)](https://github.com/webstudiobond/wordpress-docker)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Production-ready, fully decoupled, and resource-efficient containerized WordPress deployment architecture for multi-tenant hosts. Built on the latest-generation [PHP-FPM](https://packages.sury.org/php/) with all extensions required by WordPress (MySQLi, PDO, cURL, mbstring, XML, GD, Intl, Zip, BCMath, Exif) plus additional modules (Redis, igbinary, zstd, ImageMagick, APCu, GMP, OPcache) and media codecs (WebP, AVIF) pre-installed in the image, latest LTS [MariaDB](https://mariadb.org/), [Angie](https://en.angie.software/) reverse proxy, and [Valkey](https://valkey.io/) in-memory cache.

## Architecture Highlights

* **Shell-less FPM Runtime:** Web container has all shell binaries completely removed. Zero capability to spawn shell processes, even in the event of an arbitrary code execution exploit.
* **Pure UNIX Domain Socket IPC:** Zero exposed TCP network ports for MariaDB, Valkey, PHP-FPM, and go-notifier. All inter-service communication goes through dedicated, isolated in-memory tmpfs socket volumes (`sockets_mysql`, `sockets_valkey`, `sockets_php`, `sockets_notify`) accessible strictly to the required container pairs.
* **Zero-Privilege Security Profile:** Read-only root filesystems, all Linux capabilities dropped (`cap_drop: [ALL]`) with none added back (`cap_add: []`), privilege escalation blocked (`no-new-privileges`), running as a dedicated unprivileged host user.
* **Atomic Version Upgrades:** An automated pre-flight init service compares `wp-includes/version.php` between the image donor and the live site. On version mismatch it atomically replaces `wp-admin/`, `wp-includes/`, and root PHP files without ever touching `wp-content/` or user data.
* **Strict Docker Secrets:** All sensitive data — database credentials, database name, table prefix, and all eight authentication keys and salts — are loaded exclusively from secret files. No plaintext credentials in environment variables, `.env`, or Compose manifests. Missing or empty secrets cause an immediate fatal error, preventing the application from starting.
* **Zero-Secret Mail & Push Notifications:** Transactional mail (`wp_mail()`) is intercepted by a lightweight must-use plugin and dispatched over an isolated UNIX domain socket to [go-notifier](https://github.com/webstudiobond/go-notifier). This eliminates the need for third-party SMTP plugins, keeping WordPress and its database free of mail server credentials and API tokens. The daemon handles authenticated SMTP relay via Docker Secrets and supports multi-channel push alerting (Telegram, Matrix, ntfy) with subject regex routing and rate limiting.
* **Modular Must-Use (MU) Plugin Architecture:** Provides a suite of optional, zero-dependency must-use plugins configured declaratively via `wordpress.env` to harden runtime behavior, optimize administration workflows, and enforce client/server privacy controls without third-party plugin overhead or database-persisted settings.

---

<details>
<summary><strong>Directory Structure</strong></summary>

```text
├── docker-compose.yaml              # Production deployment manifest
├── .env                             # Host infrastructure variables (UID, GID, image versions)
├── wordpress.env                    # WordPress application overrides (cron, URLs, memory, debug, etc.)
├── notifier.env                     # Optional (go-notifier): channels, routing rules, and limits
├── secrets/                         # Docker secrets directory (read-only group access)
│   ├── db_name.txt                  # Database name
│   ├── db_user.txt                  # Database username
│   ├── db_password.txt              # Database password
│   ├── db_root_password.txt         # MariaDB root password
│   ├── table_prefix.txt             # WordPress table prefix
│   ├── auth_key.txt                 # Authentication key
│   ├── secure_auth_key.txt          # Secure authentication key
│   ├── logged_in_key.txt            # Logged-in key
│   ├── nonce_key.txt                # Nonce key
│   ├── auth_salt.txt                # Authentication salt
│   ├── secure_auth_salt.txt         # Secure authentication salt
│   ├── logged_in_salt.txt           # Logged-in salt
│   ├── nonce_salt.txt               # Nonce salt
│   ├── smtp_host.txt                # Optional (go-notifier): SMTP server hostname
│   ├── smtp_port.txt                # Optional (go-notifier): SMTP port (465 or 587)
│   ├── smtp_mail.txt                # Optional (go-notifier): SMTP username / sender email
│   ├── smtp_password.txt            # Optional (go-notifier): SMTP password / app password
│   └── ...                          # Optional (go-notifier): messenger secrets (telegram, etc.)
├── config/                          # Service configuration files
│   ├── php/                         # PHP-FPM configuration
│   │   ├── php-fpm.conf             # FPM master process config
│   │   ├── php.ini                  # PHP runtime directives
│   │   ├── opcache.ini              # OPcache tuning
│   │   └── www.conf                 # FPM pool settings (workers, limits, disabled functions)
│   ├── mysql/                       # MariaDB configuration
│   │   └── my.cnf                   # InnoDB buffer, connections, socket path
│   ├── valkey/                      # Valkey configuration
│   │   └── valkey.conf              # Memory limit, eviction policy, UNIX socket
│   └── angie/                       # Angie reverse proxy configuration
│       ├── angie.conf               # Main server config with FastCGI routing
│       ├── mime.types               # MIME type mappings for static assets
│       ├── modules.conf             # Dynamic modules activation (Brotli, Zstd, etc.)
│       └── conf.d/                  # Custom site snippets (blockbots, cache rules, etc.)
├── mariadb/                         # Persistent MariaDB data volume
├── mariadb-backup/                  # Database backups directory
├── tmp/                             # Upload buffering directory (avoids RAM exhaustion)
├── .wp-cli/                         # WP-CLI packages and Composer cache directory
└── data/                            # WordPress document root
    ├── wp-config.php                # Site configuration (from examples/data/wp-config.php.example)
    ├── wp-content/                  # User content directory
    │   ├── mu-plugins/              # Must-use plugins directory (optional templates)
    │   │   ├── wp-notify.php
    │   │   ├── wp-performance.php
    │   │   ├── wp-translation-updates-disabler.php
    │   │   ├── wp-telemetry-blocker.php
    │   │   ├── wp-acf-editor-control.php
    │   │   ├── wp-post-duplicator.php
    │   │   ├── wp-core-cleanup.php
    │   │   └── wp-transliterator.php
    │   ├── uploads/                 # Uploaded media assets
    │   └── ...
    └── ...                          # WordPress core files (auto-populated by init service)
```

</details>

---

<details>
<summary><strong>Deployment &amp; Setup</strong></summary>

### Prerequisites

* **Docker Engine & Compose:** Ensure Docker Engine and Docker Compose plugin are installed on the host. Follow the official installation guide for [Ubuntu](https://docs.docker.com/engine/install/ubuntu/#install-using-the-repository).
* **External Gateway Network:** The stack communicates with the host's Edge reverse proxy over a shared Docker network named `frontend_gateway` (declared as `external: true`). This network is automatically provisioned and managed with a dedicated subnet (`172.20.0.0/16`) by the **[angie-docker-compose](https://github.com/webstudiobond/angie-docker-compose)** stack. If deploying standalone without `angie-docker-compose`, create the network manually:

```bash
docker network create --subnet=172.20.0.0/16 frontend_gateway
```

### 1. Create a Dedicated System User

Create a system account with a disabled login shell:

```bash
SITE_USER=mysite
sudo useradd -m -d /home/${SITE_USER} -s /usr/sbin/nologin ${SITE_USER}
```

### 2. Create Directory Structure

```bash
sudo -u ${SITE_USER} mkdir -p /home/${SITE_USER}/{.wp-cli,data/wp-content/uploads,mariadb,tmp}
sudo mkdir -p /home/${SITE_USER}/{mariadb-backup,secrets,config/{angie/conf.d,mysql,php,valkey}}
sudo chown -R root:${SITE_USER} /home/${SITE_USER}/{config,secrets}
sudo chmod -R 0750 /home/${SITE_USER}/{config,secrets}
sudo chmod 0700 /home/${SITE_USER}/{.wp-cli,data,mariadb,mariadb-backup,tmp}
```

### 3. Generate Secrets

Install the required tools:

```bash
sudo apt update && sudo apt install -y pwgen openssl
```

All secrets are **strictly required** — the stack will refuse to start without them.

**Database credentials:**

```bash
printf "db_%s" "$(pwgen -s -A 6 1)" | sudo tee /home/${SITE_USER}/secrets/db_name.txt > /dev/null
printf "u_%s" "$(pwgen -s -A 6 1)" | sudo tee /home/${SITE_USER}/secrets/db_user.txt > /dev/null
pwgen -s 64 1 | tr -d '\n' | sudo tee /home/${SITE_USER}/secrets/db_password.txt > /dev/null
pwgen -s 64 1 | tr -d '\n' | sudo tee /home/${SITE_USER}/secrets/db_root_password.txt > /dev/null
printf "%s_" "$(pwgen -s -A 6 1)" | sudo tee /home/${SITE_USER}/secrets/table_prefix.txt > /dev/null
```

**Authentication keys and salts:**

```bash
for s in auth_key secure_auth_key logged_in_key nonce_key auth_salt secure_auth_salt logged_in_salt nonce_salt; do
  openssl rand -base64 48 | sudo tee /home/${SITE_USER}/secrets/${s}.txt > /dev/null
done
```

**Lock secret files:**

```bash
sudo chown root:${SITE_USER} /home/${SITE_USER}/secrets/*.txt
sudo chmod 0440 /home/${SITE_USER}/secrets/*.txt
```

### 4. Download Configuration Files

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"

sudo curl -fsSL ${REPO}/config/php/php-fpm.conf -o /home/${SITE_USER}/config/php/php-fpm.conf
sudo curl -fsSL ${REPO}/config/php/php.ini -o /home/${SITE_USER}/config/php/php.ini
sudo curl -fsSL ${REPO}/config/php/opcache.ini -o /home/${SITE_USER}/config/php/opcache.ini
sudo curl -fsSL ${REPO}/config/php/www.conf -o /home/${SITE_USER}/config/php/www.conf
sudo curl -fsSL ${REPO}/config/mysql/my.cnf -o /home/${SITE_USER}/config/mysql/my.cnf
sudo curl -fsSL ${REPO}/config/valkey/valkey.conf -o /home/${SITE_USER}/config/valkey/valkey.conf
sudo curl -fsSL ${REPO}/config/angie/angie.conf -o /home/${SITE_USER}/config/angie/angie.conf
sudo curl -fsSL ${REPO}/config/angie/mime.types -o /home/${SITE_USER}/config/angie/mime.types
sudo curl -fsSL ${REPO}/config/angie/modules.conf -o /home/${SITE_USER}/config/angie/modules.conf

sudo chown -R root:${SITE_USER} /home/${SITE_USER}/config
sudo find /home/${SITE_USER}/config -type f -exec chmod 0640 {} +
```

### 5. Download Compose Manifest and Application Config

```bash
sudo curl -fsSL ${REPO}/docker-compose.yaml -o /home/${SITE_USER}/docker-compose.yaml
sudo curl -fsSL ${REPO}/examples/.env.example -o /home/${SITE_USER}/.env
sudo curl -fsSL ${REPO}/examples/wordpress.env.example -o /home/${SITE_USER}/wordpress.env
sudo chmod 0600 /home/${SITE_USER}/{docker-compose.yaml,.env,wordpress.env}
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-config.php.example -o /home/${SITE_USER}/data/wp-config.php
```

### 6. Configure Environment

Edit `.env` to set the site user and UID/GID matching the system account created in Step 1 (check with `id ${SITE_USER}`):

```bash
sudo nano /home/${SITE_USER}/.env
```

See [examples/.env.example](examples/.env.example) for all available variables.

Most WordPress application settings (cron, memory limits, URLs, debug mode, etc.) can be tuned in `wordpress.env` without editing `wp-config.php`:

```bash
sudo nano /home/${SITE_USER}/wordpress.env
```

See [examples/wordpress.env.example](examples/wordpress.env.example) for all available overrides and the [official wp-config.php documentation](https://developer.wordpress.org/advanced-administration/wordpress/wp-config/) for detailed parameter descriptions.

### 7. Mail & Push Notifications (go-notifier)

The PHP-FPM container is strictly hardened (`read_only: true`, `cap_drop: [ALL]`) and does **not** contain a local mail transfer agent (MTA) such as `sendmail` or `postfix`. Standard PHP `mail()` execution is disabled. Without an email relay, WordPress cannot deliver critical administrative messages:
* Password recovery emails for administrators and users
* PHP fatal error Recovery Mode access links
* Security plugin alerts (e.g. Wordfence attack warnings)
* Contact forms and eCommerce notifications

To provide high-performance, non-blocking delivery without storing plaintext SMTP passwords in the WordPress database, the stack integrates **[go-notifier](https://github.com/webstudiobond/go-notifier)** — a zero-dependency micro-daemon running in a minimal scratch container. It communicates with WordPress strictly over a local UNIX domain socket (`/var/run/sockets/notify/notify.sock`) and loads credentials directly from Docker Secrets in memory.

#### Option A: Enable go-notifier (Default & Recommended)

1. **Enter SMTP server credentials:**
   Securely enter your SMTP server hostname (e.g. `smtp.example.com`), port (`587` or `465`), sender email / username, and password using `nano` without exposing credentials in shell command history:
   ```bash
   sudo nano /home/${SITE_USER}/secrets/smtp_host.txt
   sudo nano /home/${SITE_USER}/secrets/smtp_port.txt
   sudo nano /home/${SITE_USER}/secrets/smtp_mail.txt
   sudo nano /home/${SITE_USER}/secrets/smtp_password.txt
   ```

   *(Optional) If using Telegram, Matrix, ntfy, or custom admin alert routing, edit the corresponding secret files as needed:*

   **Telegram:**
   * `telegram_bot_token.txt` — Telegram Bot API token (e.g. `123456789:ABCdefGHIjkl...`).
   * `telegram_chat_id.txt` — Target chat, group, or channel ID (e.g. `-1001234567890` or `123456789`).
   ```bash
   sudo nano /home/${SITE_USER}/secrets/telegram_bot_token.txt
   sudo nano /home/${SITE_USER}/secrets/telegram_chat_id.txt
   ```

   **Matrix:**
   * `matrix_url.txt` — Direct Client-Server API URL to the Synapse / homeserver (e.g. `https://matrix.example.com` or `https://synapse.example.com:8448`).
     *NOTE:* `go-notifier` does not query `.well-known/matrix/client`; specify the direct homeserver endpoint, not the root organization domain. Unsure? Check `https://matrix.org/.well-known/matrix/client` and use the `base_url`.
   * `matrix_room_id.txt` — Internal room ID (e.g. `!abcdef:matrix.example.com` or `!opaque-v12_roomid`).
   * `matrix_access_token.txt` — Matrix bot / user access token.
   ```bash
   sudo nano /home/${SITE_USER}/secrets/matrix_url.txt
   sudo nano /home/${SITE_USER}/secrets/matrix_room_id.txt
   sudo nano /home/${SITE_USER}/secrets/matrix_access_token.txt
   ```

   **ntfy:**
   * `ntfy_url.txt` — ntfy server URL (e.g. `https://ntfy.sh` or `https://ntfy.example.com`).
   * `ntfy_topic.txt` — Target topic name.
   * `ntfy_token.txt` — Optional Bearer token for protected topics.
   ```bash
   sudo nano /home/${SITE_USER}/secrets/ntfy_url.txt
   sudo nano /home/${SITE_USER}/secrets/ntfy_topic.txt
   sudo nano /home/${SITE_USER}/secrets/ntfy_token.txt
   ```

   **Admin Alert Recipients:**
   * `admin_emails.txt` — Admin emails allowed to receive messenger alerts (comma/newline-separated, e.g. `admin@example.com,security@example.com`). Emails sent to other recipients (e.g. customer orders, password resets) are routed exclusively to SMTP to protect user privacy.
   ```bash
   sudo nano /home/${SITE_USER}/secrets/admin_emails.txt
   ```

   Lock secret files with strict ownership and permissions:
   ```bash
   sudo chown -R root:${SITE_USER} /home/${SITE_USER}/secrets
   sudo chmod 0750 /home/${SITE_USER}/secrets
   sudo chmod 0440 /home/${SITE_USER}/secrets/*.txt
   ```

2. **Download and configure routing (`notifier.env`):**
   Configure active notification channels (`NOTIFY_CHANNELS`), rate limits, and custom subject regex routing rules (`NOTIFY_RULE_<NAME>_*`). See [`examples/notifier.env.example`](examples/notifier.env.example) for detailed syntax:
   ```bash
   sudo curl -fsSL ${REPO}/examples/notifier.env.example -o /home/${SITE_USER}/notifier.env
   sudo nano /home/${SITE_USER}/notifier.env
   sudo chmod 0600 /home/${SITE_USER}/notifier.env
   ```

3. **Install the mu-plugin interceptor:**
   ```bash
   sudo -u ${SITE_USER} mkdir -p /home/${SITE_USER}/data/wp-content/mu-plugins
   sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-notify.php.example \
     -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-notify.php
   ```
   The `wp-notify.php` must-use plugin automatically intercepts all `wp_mail()` calls and dispatches the payload to `/var/run/sockets/notify/notify.sock`.

#### Option B: Disable go-notifier (Alternative)

If you plan to use an external WordPress plugin sending email via direct HTTP API (such as FluentSMTP or WP Mail SMTP connecting to AWS SES, Mailgun, SendGrid, or Postmark), or if the site does not require email delivery:

1. **Edit `docker-compose.yaml`:**
   Comment out the `notifier` service block, the `sockets_notify` volume mounts in `wordpress` and `wp-cli`, the `sockets_notify` definition in `volumes:`, and the 4 `smtp_*` secret definitions in the root `secrets:` section:
   ```bash
   sudo nano /home/${SITE_USER}/docker-compose.yaml
   ```
2. **Do not create** `data/wp-content/mu-plugins/wp-notify.php` or `notifier.env`.

### 8. Start the Stack

Pull all images (including `tools` profile services such as `wp-cli`) and start the stack:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml --profile tools pull
docker compose -f /home/${SITE_USER}/docker-compose.yaml up -d
```

On first run, the `init` service automatically populates `data/` with clean WordPress core files and exits. When deploying a new image (`docker compose --profile tools pull` followed by `docker compose up -d`), `init` automatically upgrades or rolls back WordPress core files (`wp-admin/`, `wp-includes/`, root PHP files) to strictly match the container image version, without ever touching user data, uploads, or `wp-content/`.

The hardened FPM runtime then starts with a read-only root filesystem — WordPress write operations (installing and updating plugins, themes, uploading media) continue to work normally through dedicated writable bind mounts.

To temporarily stop the containers:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml stop
```

To stop and remove containers and networks:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml down
```

</details>

---

<details>
<summary><strong>Must-Use (MU) Plugins</strong></summary>

## Optional Must-Use (MU) Plugins

WordPress automatically loads all PHP files located directly inside `data/wp-content/mu-plugins/` on every request. Must-use plugins cannot be accidentally deactivated in the web dashboard and do not store settings in the database.

This repository provides optional, zero-dependency MU-plugin templates in [`examples/data/wp-content/mu-plugins/`](examples/data/wp-content/mu-plugins/) configured via `/home/${SITE_USER}/wordpress.env`.

Ensure the target directory exists before installing any MU-plugin:

```bash
SITE_USER=mysite
sudo -u ${SITE_USER} mkdir -p /home/${SITE_USER}/data/wp-content/mu-plugins
```

---

### 1. WordPress Update & Network Optimization (`wp-performance.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-performance.php.example`](examples/data/wp-content/mu-plugins/wp-performance.php.example)

* Releases the browser tab immediately once a core, plugin, theme, or translation update finishes in the WordPress dashboard, allowing remaining post-update routines to complete asynchronously in the background without making the user wait.
* Enforces IPv4 for outbound WordPress HTTP requests so external API calls (`api.wordpress.org`, plugin servers, webhooks) do not hang waiting for unreachable IPv6 timeouts in an IPv4-only Docker network.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-performance.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-performance.php
```

Configuration (`wordpress.env` — both enabled by default when installed):
* `WP_PERF_FASTCGI_FINISH=true` — set to `false`, `0`, or `off` to disable early response flushing.
* `WP_PERF_FORCE_IPV4=true` — set to `false`, `0`, or `off` if your host and Docker network have native IPv6 connectivity.

---

### 2. Translation Update Control (`wp-translation-updates-disabler.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-translation-updates-disabler.php.example`](examples/data/wp-content/mu-plugins/wp-translation-updates-disabler.php.example)

* Prevents WordPress from silently overwriting custom or corrected localization files (`.po`, `.mo`, `.l10n.php`) in `wp-content/languages/` during background cron runs and plugin updates.
* Allows disabling automatic background translation updates globally (while keeping manual updates and core security updates intact) or blocking translation updates entirely for specific plugins and themes.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-translation-updates-disabler.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-translation-updates-disabler.php
```

Configuration (`wordpress.env`):
* `WP_TRANSLATION_DISABLE_AUTO_UPDATES=false` — set to `true` (`1`, `on`, `yes`) to block automatic background translation updates while still allowing manual updates.
* `WP_TRANSLATION_BLOCKED_SLUGS=` — comma-separated list of plugin/theme slugs (e.g., `plugin-slug,theme-slug`) excluded from all translation updates, or `*` to disable translation updates site-wide.

---

### 3. Client-Side Telemetry & Tracker Blocker (`wp-telemetry-blocker.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-telemetry-blocker.php.example`](examples/data/wp-content/mu-plugins/wp-telemetry-blocker.php.example)

* Selectively blocks outbound browser requests from plugins, themes, and scripts only for the external domains or URL substrings you explicitly define in `WP_TELEMETRY_BLOCKED_URLS` (nothing is blocked by default, so required analytics and integrations remain untouched).
* Unlike ad blockers, DNS sinkholes, or strict `Content-Security-Policy` rules that abort connections and can cause unhandled JavaScript errors or broken UI components, this plugin intercepts matching URLs in the browser and returns a synthetic `200 OK` response so scripts continue working normally without transmitting data externally.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-telemetry-blocker.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-telemetry-blocker.php
```

Configuration (`wordpress.env`):
* `WP_TELEMETRY_BLOCKED_URLS=` — comma-separated list of domains or URL substrings to intercept (e.g., `telemetry.example.com,analytics.example.net/collect`). When empty, the plugin remains inactive.

---

### 4. ACF & Gutenberg Visibility Sync (`wp-acf-editor-control.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-acf-editor-control.php.example`](examples/data/wp-content/mu-plugins/wp-acf-editor-control.php.example)

* In Advanced Custom Fields (ACF), enabling *Hide on screen → Content Editor* hides the Classic Editor, but does not disable the WordPress Block Editor (Gutenberg).
* Automatically disables Gutenberg on post types and pages (including Polylang translations) where active ACF field groups hide the content editor, caching the rules in `wp_options` when field groups are saved to avoid extra database queries on page load.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-acf-editor-control.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-acf-editor-control.php
```

*(Requires no `wordpress.env` variables; works automatically based on ACF field group settings).*

---

### 5. Post & Page Duplicator (`wp-post-duplicator.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-post-duplicator.php.example`](examples/data/wp-content/mu-plugins/wp-post-duplicator.php.example)

* Adds a one-click *Duplicate* action to posts, pages, and configured custom post types in the WordPress dashboard, cloning content, taxonomy terms, and custom fields (including serialized ACF and page-builder metadata) without installing third-party duplicator plugins.
* Preserves Polylang language assignments while excluding internal translation group bindings and edit locks so duplicated drafts never overwrite existing multilingual links or old slug redirects.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-post-duplicator.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-post-duplicator.php
```

Configuration (`wordpress.env` — works out of the box for `post` and `page` as drafts):
* `WP_DUPLICATOR_POST_TYPES=post,page` — comma-separated list of post type and custom post type (CPT) slugs (e.g., `post,page,product,portfolio,bbb-room`), `*` for all non-system post types, or `none` to disable.
* `WP_DUPLICATOR_STATUS=draft` — status assigned to newly created clones (`draft`, `pending`, `private`, or `publish`).
* `WP_DUPLICATOR_KEEP_AUTHOR=true` — set to `false` (`0`, `off`, `no`) to assign the user performing the duplication as the author instead of keeping the original author.
* `WP_DUPLICATOR_CAPABILITY=edit_posts` — base capability required in addition to per-post `edit_post` permission (e.g., `edit_posts`, `publish_posts`, `edit_others_posts`, `edit_pages`, or `manage_options`).

---

### 6. Core Head, Header & Comment Cleanup (`wp-core-cleanup.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-core-cleanup.php.example`](examples/data/wp-content/mu-plugins/wp-core-cleanup.php.example)

* Removes redundant WordPress `<head>` metadata (`wp_generator`, `wlwmanifest`, `rsd`, `shortlink`, REST API discovery links, feed links, oEmbed links, DNS prefetch hints, profile link), strips the `X-Powered-By` and `X-Pingback` HTTP headers, disables internal self-pingbacks, and removes core Emoji scripts/styles out of the box.
* Hardens comment forms against link spam by removing the website (`url`) field, disabling automatic link conversion (`make_clickable`), and deregistering `comment-reply.js`, with optional flags to disable Gutenberg global styles, `jquery-migrate`, and Speculative Loading.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-core-cleanup.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-core-cleanup.php
```

Configuration (`wordpress.env` — baseline head, header, emoji, self-ping, and comment cleanups are enabled by default):
* `WP_CLEANUP_HEAD_TAGS=generator,wlwmanifest,rsd,shortlink,rest_links,feeds,oembed,dns_prefetch,profile` — comma-separated list of `<head>` items to remove, `*` for all, or `none` to keep all default tags.
* `WP_CLEANUP_HIDE_POWERED_BY=true` — removes the `X-Powered-By` HTTP response header (`false` to disable).
* `WP_CLEANUP_DISABLE_SELF_PING=true` — blocks pingbacks to the site's own URLs and removes the `X-Pingback` HTTP header (`false` to disable).
* `WP_CLEANUP_DISABLE_EMOJIS=true` — disables core Emoji scripts and styles (`false` to keep).
* `WP_CLEANUP_COMMENTS=url_field,make_clickable,reply_js` — comma-separated list of comment cleanups (`url_field`, `make_clickable`, `reply_js`, `recent_styles`), `*` for all four, or `none` to disable.
* `WP_CLEANUP_DISABLE_GLOBAL_STYLES=false` — set to `true` to remove Gutenberg `global-styles` inline CSS and SVG duotone filters on the frontend.
* `WP_CLEANUP_DISABLE_JQUERY_MIGRATE=false` — set to `true` to remove `jquery-migrate` from frontend pages.
* `WP_CLEANUP_DISABLE_SPECULATIVE_LOADING=false` — set to `true` to disable WordPress Speculative Loading rules.

---

### 7. Slug & Filename Transliterator (`wp-transliterator.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-transliterator.php.example`](examples/data/wp-content/mu-plugins/wp-transliterator.php.example)

* Automatically transliterates non-Latin slugs of newly saved posts, pages, and taxonomy terms (`sanitize_title` in `'save'` context without breaking existing frontend URLs) and newly uploaded media filenames (`sanitize_file_name`), including macOS NFD decomposed character normalization and European diacritics removal via WordPress `remove_accents()`.
* Operates entirely in memory using OPcache-backed constant conversion tables (`ISO9`, `universal` combined multilingual table, `uk`, `bel`, `bg_BG`, `mk_MK`, `sr_RS`, `kk`, `el`, `hy`, `ka_GE`, `he_IL`) with zero database queries.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-transliterator.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-transliterator.php
```

Configuration (`wordpress.env` — defaults to `universal` with both slug and filename transliteration enabled):
* `WP_TRANSLIT_TABLE=universal` — conversion table to use (`universal` / `multi` / `all` combined multilingual table by default, `auto` to resolve from WordPress `get_locale()`, `uk`, `ISO9`, `bel`, `bg_BG`, `mk_MK`, `sr_RS`, `kk`, `el`, `hy`, `ka_GE`, or `he_IL`).
* `WP_TRANSLIT_SLUGS=true` — transliterate newly saved post, page, and term slugs (`false` to disable).
* `WP_TRANSLIT_FILENAMES=true` — transliterate newly uploaded media filenames (`false` to disable).
* `WP_TRANSLIT_OVERRIDES=` — optional comma-separated `char:replacement` pairs applied on top of the selected table (e.g., `ц:ts,Ц:TS`).

---

### 8. YouTube Channel Statistics & oEmbed Tools (`wp-youtube.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-youtube.php.example`](examples/data/wp-content/mu-plugins/wp-youtube.php.example)

* Automatically rewrites YouTube oEmbed iframes to privacy-enhanced `youtube-nocookie.com/embed/` and replaces `?feature=oembed` with clean, non-deprecated player parameters (`rel=0&playsinline=1&iv_load_policy=3`).
* Optionally provides cached YouTube Data API v3 shortcodes (`[ytv]` for total channel views, `[yts]` for subscriber count, `[ytc]` for video count) backed by WordPress transients and `wp_remote_get()`, reading default credentials from Docker Secrets (`wp_youtube_api_key`, `wp_youtube_channel_id`) or `wordpress.env`.

Installation:

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-youtube.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-youtube.php
```

Configuration (`wordpress.env` — oEmbed parameter cleanup and `youtube-nocookie.com` are enabled by default; channel statistics shortcodes are opt-in):
* `WP_YOUTUBE_OEMBED_CLEANUP=true` — customize YouTube oEmbed iframe parameters (`false` to disable).
* `WP_YOUTUBE_NOCOOKIE=true` — rewrite `youtube.com/embed/` to `youtube-nocookie.com/embed/` (`false` to disable).
* `WP_YOUTUBE_OEMBED_PARAMS=rel=0&playsinline=1&iv_load_policy=3` — query string parameters applied to YouTube oEmbed URLs.
* `WP_YOUTUBE_SHORTCODES=false` — set to `true` (`1`, `on`, `yes`) to register `[ytv]`, `[yts]`, and `[ytc]` shortcodes.
* `WP_YOUTUBE_API_KEY=` — fallback YouTube Data API v3 key if not passed via `secrets/wp_youtube_api_key.txt` or shortcode `apikey="..."` attribute.
* `WP_YOUTUBE_CHANNEL_ID=` — fallback YouTube channel ID if not passed via `secrets/wp_youtube_channel_id.txt` or shortcode `channel="..."` attribute.

---

### 9. UNIX Socket Mail Dispatcher (`wp-notify.php`)

Source: [`examples/data/wp-content/mu-plugins/wp-notify.php.example`](examples/data/wp-content/mu-plugins/wp-notify.php.example)

* Routes all `wp_mail()` calls over an isolated UNIX socket to [`go-notifier`](https://github.com/webstudiobond/go-notifier) because the hardened PHP-FPM container has no shell or local mail binary (`sendmail`/`postfix`), eliminating the need for third-party SMTP plugins and keeping mail credentials out of the WordPress database.

See [Deployment & Setup — Step 7](#7-mail--push-notifications-go-notifier) for full setup instructions.

</details>

---

<details>
<summary><strong>Memory Limits</strong></summary>

## Tuning PHP & Container Memory Limits

If your workload requires adjusting PHP memory limits (e.g., for heavy WooCommerce imports or large media processing), limits must be updated **consistently** across three layers so that PHP-FPM workers never exceed the container cgroup ceiling (which would trigger a Linux kernel OOM kill):

1. **`wordpress.env`** — `WORDPRESS_MEMORY_LIMIT` (standard frontend limit) and `WORDPRESS_MAX_MEMORY_LIMIT` (administration and WP-CLI ceiling).
2. **`docker-compose.yaml`** — `mem_limit` under the `wordpress` (and `wp-cli`) service definition.
3. **`config/php/www.conf`** — FPM worker pool directives (`pm.max_children`) and `memory_limit` in `config/php/php.ini`.

</details>

---

<details>
<summary><strong>Permissions &amp; Ownership</strong></summary>

## Host Permissions & Privilege Separation

The stack enforces strict host-level privilege separation across three ownership tiers so that even in the event of a container compromise (`${SITE_USER}`), the process cannot modify deployment manifests, service configurations, or secret files:

* **`root:root` (Host-Only Manifests & Backups):**
  * `docker-compose.yaml`, `.env`, `wordpress.env`, `notifier.env` — `0600`
  * `mariadb-backup/` — `0700`
* **`root:${SITE_USER}` (Read-Only Service Configs & Secrets):**
  * `config/` — directories `0750`, files `0640` (mounted `:ro` into containers)
  * `secrets/` — directory `0750`, secret files `0440` (containers have group read-only access and cannot `chmod` or overwrite files)
* **`${SITE_USER}:${SITE_USER}` (Runtime Writable Storage):**
  * `.wp-cli/`, `mariadb/`, `tmp/` — `0700`
  * `data/` — top-level directory `0700`, internal directories `0755`, internal files `0644`

### One-Shot Permission Reset (Running Stack)

Use this self-contained block to apply or restore canonical ownership and permissions on an existing or migrated installation without stopping the stack:

```bash
SITE_USER=mysite

# 1. Host-only manifests & backups (root:root)
sudo chown root:root /home/${SITE_USER}/docker-compose.yaml /home/${SITE_USER}/.env /home/${SITE_USER}/wordpress.env
sudo chmod 0600 /home/${SITE_USER}/docker-compose.yaml /home/${SITE_USER}/.env /home/${SITE_USER}/wordpress.env
[ -f /home/${SITE_USER}/notifier.env ] && sudo chown root:root /home/${SITE_USER}/notifier.env && sudo chmod 0600 /home/${SITE_USER}/notifier.env
sudo chown -R root:root /home/${SITE_USER}/mariadb-backup
sudo chmod 0700 /home/${SITE_USER}/mariadb-backup

# 2. Read-only service configs & secrets (root:${SITE_USER})
sudo chown -R root:${SITE_USER} /home/${SITE_USER}/{config,secrets}
sudo find /home/${SITE_USER}/config -type d -exec chmod 0750 {} +
sudo find /home/${SITE_USER}/config -type f -exec chmod 0640 {} +
sudo chmod 0750 /home/${SITE_USER}/secrets
sudo chmod 0440 /home/${SITE_USER}/secrets/*.txt

# 3. Runtime writable directories & WordPress files (${SITE_USER}:${SITE_USER})
sudo chown -R ${SITE_USER}:${SITE_USER} /home/${SITE_USER}/{.wp-cli,data,mariadb,tmp}
sudo chmod 0700 /home/${SITE_USER}/{.wp-cli,data,mariadb,tmp}
sudo find /home/${SITE_USER}/data -mindepth 1 -type d -exec chmod 0755 {} +
sudo find /home/${SITE_USER}/data -type f -exec chmod 0644 {} +
```

</details>

---

<details>
<summary><strong>External Angie</strong></summary>

## Edge Reverse Proxy (External Angie)

Each site stack includes an internal Angie container that handles FastCGI routing to PHP-FPM via the UNIX socket. It does **not** publish any ports on the host. An external Edge Angie instance running on the host acts as the hardened perimeter gateway: it terminates TLS, manages automated certificates, enforces perimeter security, and forwards incoming traffic to the internal site containers.

The external Edge Angie operates at a dedicated static IP (`172.20.0.2`) inside the **`frontend_gateway`** network (`172.20.0.0/16`). All WordPress site stacks connect their internal Angie container (`${SITE_USER}_angie`) to this network, allowing Edge Angie to route requests directly by container name while internal Angie instances strictly verify `$realip_remote_addr` to accept `X-Forwarded-For` headers and HTTP connections exclusively from `172.20.0.2` (blocking lateral traffic from any other container in the shared subnet).

A complete, production-hardened, and optimized Edge reverse proxy deployment with a security-by-default architecture is available in the **[angie-docker-compose](https://github.com/webstudiobond/angie-docker-compose)** repository.

See the canonical site virtual host template:
* **[`data/conf.d/domains/wordpress.conf`](https://github.com/webstudiobond/angie-docker-compose/blob/main/data/conf.d/domains/wordpress.conf)** — production reverse proxy virtual host configuration (automated ACME TLS lifecycle, HTTP/3 QUIC, TLS 1.3 0-RTT anti-replay mitigation, upstream keepalive pooling to `${SITE_USER}_angie:80`, baseline security headers, HSTS, anonymous perimeter error pages, real client IP forwarding, tuned WordPress timeouts and body limits, etc.).

For each WordPress site, download the template into your Edge Angie configuration directory (`${DATA_ANGIE}/conf.d/domains/`), renaming the config file to your unique site identifier (e.g., `${SITE_USER}.conf` as defined earlier):

```bash
DATA_ANGIE="/home/angie/data"
curl -fsSL https://raw.githubusercontent.com/webstudiobond/angie-docker-compose/main/data/conf.d/domains/wordpress.conf \
  -o ${DATA_ANGIE}/conf.d/domains/${SITE_USER}.conf
```

> WARNING: Replace 'wordpress.example' with your domain and 'mysite' with '${SITE_USER}' so upstream requests resolve to '${SITE_USER}_angie:80'.

Substitute the placeholders inside the downloaded configuration file using `sed`:

```bash
DOMAIN="example.com"

sed -i \
  -e "s|wordpress\.example|${DOMAIN}|g" \
  -e "s|mysite|${SITE_USER}|g" \
  ${DATA_ANGIE}/conf.d/domains/${SITE_USER}.conf
```

Review and customize the configuration as needed (for example, add the `www` subdomain to `server_name` or adjust `Conditional Access Logging` rules):

```bash
nano ${DATA_ANGIE}/conf.d/domains/${SITE_USER}.conf
```

</details>

---

<details>
<summary><strong>WP-CLI</strong></summary>

WP-CLI is an on-demand tool service under `profiles: [tools]` — it does not start with the main stack and only runs when explicitly invoked. It shares the WordPress document root (`/var/www/html`) and communicates with MariaDB and Valkey over their UNIX domain sockets.

> NOTE: In official documentation, commands are written with a leading `wp` (e.g., `wp core version`, `wp plugin list`). In this stack, `wp-cli` is the Docker Compose service name whose container entrypoint directly executes the `wp` binary. Consequently, you pass subcommands directly without typing `wp` again.

See the [official WP-CLI command reference](https://developer.wordpress.org/cli/commands/) for all available commands.

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli core version
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli db check
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli plugin list
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli plugin update --all
```

> TIP: When the stack is already running, appending `--no-deps` (`run --rm --no-deps wp-cli ...`) skips dependency polling and health checks for `mariadb` and `valkey`, making commands execute instantaneously. If the stack is stopped or starting, omit `--no-deps` so Docker Compose automatically starts dependencies first.

### Shell Alias for Interactive Use

For convenience in interactive SSH sessions, you can define a shell function per site in `~/.bashrc`:

```bash
nano ~/.bashrc
```

```bash
wp_mysite() {
    docker compose -f /home/mysite/docker-compose.yaml run --rm wp-cli "$@"
}
```

```bash
source ~/.bashrc
```

For multiple sites, create a function per site (e.g. `wp_site1`, `wp_site2`). After that, run commands directly:

```bash
wp_mysite core version
wp_mysite plugin list
```

> NOTE: Functions and aliases defined in `~/.bashrc` are only loaded in interactive login shells. System cron jobs and non-interactive scripts execute via `/bin/sh` without loading `~/.bashrc`, and must always use the full `docker compose -f ...` command.

### Common Administrative Tasks

Using the configured shell alias (e.g. `wp_mysite`):

**Plugin management:**

```bash
# Check plugin list with available updates
wp_mysite plugin list --fields=name,status,update,version

# List plugins with available updates in JSON format
wp_mysite plugin list --format=json --fields=name --update=available

# Preview updates without modifying files
wp_mysite plugin update --all --dry-run

# Update a specific plugin or all plugins
wp_mysite plugin update <plugin-name>
wp_mysite plugin update --all

# Update all plugins while excluding critical ones that require separate testing
wp_mysite plugin update --all --exclude=plugin-name-1,plugin-name-2

# Run command without loading active plugins (prevents crashes from broken plugin code)
wp_mysite plugin update --all --skip-plugins

# Install or update a plugin from a local zip archive placed in /home/mysite/tmp
sudo chown mysite:mysite /home/mysite/tmp/plugin-archive.zip
wp_mysite plugin install /var/www/tmp/plugin-archive.zip --force

# Or install/update and immediately activate the plugin
wp_mysite plugin install /var/www/tmp/plugin-archive.zip --force --activate

# Force-refresh update check and update translations (core, plugins, themes)
wp_mysite core check-update --force-check
wp_mysite language core update
wp_mysite language plugin update --all
wp_mysite language theme update --all
```

**Package management:**

```bash
# Install community WP-CLI packages (e.g. WP Rocket CLI)
wp_mysite package install wp-media/wp-rocket-cli:trunk

# List installed packages
wp_mysite package list
```

**Maintenance mode:**

```bash
# Check current maintenance mode status
wp_mysite maintenance-mode status

# Activate maintenance mode before updates or migrations
wp_mysite maintenance-mode activate

# Deactivate maintenance mode once operations complete
wp_mysite maintenance-mode deactivate
```

**Cache & permalinks:**

```bash
# Flush rewrite rules in the database (resolves 404 errors on custom post types or after migrations)
wp_mysite rewrite flush

# Flush persistent object cache
wp_mysite cache flush
```

**Action Scheduler queue cleanup:**

```bash
# Clean completed and failed actions from the Action Scheduler queue
wp_mysite action-scheduler clean --batches=20
```

### Manual Database Backup & Restore

Create a fast, compressed database backup using `zstd`:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli db export --single-transaction --quick - | zstd -q > /home/${SITE_USER}/mariadb-backup/db_backup_$(date +%Y-%m-%d_%H-%M-%S).sql.zst
```

Or using the shell alias:

```bash
wp_mysite db export --single-transaction --quick - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +%Y-%m-%d_%H-%M-%S).sql.zst
```

To restore a compressed database backup:

```bash
zstd -dc /home/${SITE_USER}/mariadb-backup/db_backup_YYYY-MM-DD_HH-MM-SS.sql.zst | docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm -T wp-cli db import -
```

> NOTE: The `-T` flag disables pseudo-TTY allocation in Docker Compose, ensuring the piped SQL stream passes through stdin reliably without corruption or terminal escape sequences.

### CLI Host & URL Context

If your site or plugins rely on `HTTP_HOST` for dynamic URLs, configure the URL context using either:

1. **`wp-cli.yml` (recommended):**
   ```bash
   sudo -u ${SITE_USER} nano /home/${SITE_USER}/data/wp-cli.yml
   ```
   ```yaml
   url: https://wordpress.example
   ```
2. **`WORDPRESS_CLI_HOST`:** Set `WORDPRESS_CLI_HOST=wordpress.example` in `wordpress.env`.

</details>

---

<details>
<summary><strong>DB Bridge</strong></summary>

## Remote Database Access (DB Bridge)

MariaDB runs with TCP networking completely disabled (`skip-networking`) and communicates exclusively via a UNIX socket. The `db-bridge` service is an on-demand socat relay that exposes the socket as a local TCP port for use with desktop database clients.

**Start the bridge:**

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml --profile tools up -d db-bridge
```

**Establish an SSH tunnel** from your local machine:

```bash
ssh -i ~/.ssh/id_ed25519 -p 22 -L 3307:127.0.0.1:3307 user@your-server-ip
```

Connect your database client (DataGrip, DBeaver, TablePlus, VS Code Database Client, Zed) to `127.0.0.1:3307` using the database name from `secrets/db_name.txt`, username from `secrets/db_user.txt`, and password from `secrets/db_password.txt`. Most of these tools also support configuring SSH tunnels directly in their connection settings.

> IMPORTANT: Always stop the bridge when you are finished:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml --profile tools stop db-bridge
```

</details>

---

<details>
<summary><strong>Additional Configuration &amp; Automation</strong></summary>

## Server Cron & Automated Backups

WordPress by default uses a virtual cron (`wp-cron.php`) that runs asynchronously when visitors browse the site. On high-traffic sites, this wastes PHP worker processes; on low-traffic sites, scheduled tasks (such as publishing posts, checking updates, and background maintenance) may not trigger on time.

### 1. Disable Virtual Web Cron

Set `WORDPRESS_DISABLE_CRON=true` in `/home/${SITE_USER}/wordpress.env`:

```bash
sudo nano /home/${SITE_USER}/wordpress.env
```

```ini
WORDPRESS_DISABLE_CRON=true
```

### 2. Configure Dedicated System Cron

Configuration examples ([full example](examples/etc/cron.d/wordpress-mysite.example)):

**WordPress scheduled events (every 10 minutes):**
```cron
*/10 * * * * root docker compose -f /home/mysite/docker-compose.yaml run --rm wp-cli cron event run --due-now >/dev/null 2>&1
```

**Action Scheduler queue runner (every 5 minutes, recommended for WooCommerce & background task queues):**
```cron
*/5 * * * * root docker compose -f /home/mysite/docker-compose.yaml run --rm wp-cli action-scheduler run --batches=5 >/dev/null 2>&1
```

**Automated database backup (every 6 hours, keeps backups for 7 days):**
```cron
0 */6 * * * root docker compose -f /home/mysite/docker-compose.yaml run --rm wp-cli db export --single-transaction --quick - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +\%Y-\%m-\%d_\%H-\%M-\%S).sql.zst && find /home/mysite/mariadb-backup -type f -name "*.sql.zst" -mtime +7 -delete
```
> NOTE:
> The automated backup job requires `zstd` installed on the host (`sudo apt update && sudo apt install -y zstd`).

#### Pre-configured Cron File

Download the template directly into `/etc/cron.d/`, specifying your unique site identifier in the destination filename (files in `/etc/cron.d/` must not contain extensions):

```bash
sudo curl -fsSL https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main/examples/etc/cron.d/wordpress-mysite.example \
  -o /etc/cron.d/wordpress-${SITE_USER}
```

Replace the placeholder `mysite` with your unique site identifier (`${SITE_USER}`):

```bash
sudo sed -i "s|mysite|${SITE_USER}|g" /etc/cron.d/wordpress-${SITE_USER}
```

Fine-tune the schedule to your requirements if needed (for example, modify task execution intervals, change backup frequencies, or adjust the backup retention period):

```bash
sudo nano /etc/cron.d/wordpress-${SITE_USER}
```

Set permissions:

```bash
sudo chmod 0644 /etc/cron.d/wordpress-${SITE_USER}
```

> NOTE:
> * **Tenant Isolation:** Placing cron schedules in `/etc/cron.d/wordpress-${SITE_USER}` keeps each site's automation isolated.
> * **Security & Permissions:** The host executes `docker compose` as `root` (to access the Docker daemon socket), while the WP-CLI container process inside strictly runs unprivileged under `${APP_UID}:${APP_GID}` (`${SITE_USER}`).
> * **Non-Interactive Shell:** Cron executes via `/bin/sh` without sourcing user shell configurations like `~/.bashrc`. Therefore, full `docker compose -f ...` commands must be used instead of shell aliases (`wp_mysite`).
> * **Cron File Naming:** Files in `/etc/cron.d/` must contain only alphanumeric characters, underscores, and hyphens (no periods or extensions).

</details>

---

<details>
<summary><strong>Migration from a Classic Server</strong></summary>

## Migrating an Existing Site (Bare-Metal → Containerized)

Use this guide to move a running WordPress + MariaDB site from a classic LAMP/LEMP host into this stack. You will need: a **tar archive of the document root** and a **MariaDB dump in plain SQL**.

> IMPORTANT: This stack loads database credentials, database name, and table prefix from **Docker secrets**, and uses its own secret-driven `wp-config.php`. The old `wp-config.php` file must **not** be carried over — it is excluded during archiving/extraction.

### 1. On the source server — archive the files

> NOTE: `/old/path/to/site/root` and `wordpress.example` throughout this guide are placeholders for your source server's document root and your site domain. You must replace them with your actual values in all commands.

```bash
tar -czf wordpress-files.tar.gz \
  --exclude=wp-config.php \
  -C /old/path/to/site/root .
```

> NOTE: Keep a copy of the old `wp-config.php` handy! If your existing site uses plugins with custom constants or licenses defined in `wp-config.php` (such as Object Cache Pro `WP_REDIS_CONFIG`, license tokens, SMTP settings, or security plugin constants), you will need to copy those specific definitions into the new configuration in step 3.

### 2. On the source server — dump the database

Export the site database to a plain SQL file:

```bash
mariadb-dump -uroot --single-transaction --quick wp_db_name > site.sql
# Or if a password is required: mariadb-dump -uroot -p --single-transaction --quick wp_db_name > site.sql
# (On older MySQL hosts, use mysqldump instead of mariadb-dump)
```

### 3. On the new host — prepare deployment and secrets

Provision the host user, directories, secrets, and configuration files in a single pass (see [Deployment & Setup](#deployment--setup) for detailed descriptions of each setting, optional messenger channels, and `go-notifier` alternatives):

```bash
SITE_USER=mysite
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"

# 1. Create system user and directory structure
sudo useradd -m -d /home/${SITE_USER} -s /usr/sbin/nologin ${SITE_USER}
sudo -u ${SITE_USER} mkdir -p /home/${SITE_USER}/{.wp-cli,data/wp-content/{mu-plugins,uploads},mariadb,tmp}
sudo mkdir -p /home/${SITE_USER}/{mariadb-backup,secrets,config/{angie/conf.d,mysql,php,valkey}}
sudo chown -R root:${SITE_USER} /home/${SITE_USER}/{config,secrets}
sudo chmod -R 0750 /home/${SITE_USER}/{config,secrets}
sudo chmod 0700 /home/${SITE_USER}/{.wp-cli,data,mariadb,mariadb-backup,tmp}

# 2. Generate database credentials and authentication salts
sudo apt update && sudo apt install -y pwgen openssl zstd
printf "db_%s" "$(pwgen -s -A 6 1)" | sudo tee /home/${SITE_USER}/secrets/db_name.txt > /dev/null
printf "u_%s" "$(pwgen -s -A 6 1)" | sudo tee /home/${SITE_USER}/secrets/db_user.txt > /dev/null
pwgen -s 64 1 | tr -d '\n' | sudo tee /home/${SITE_USER}/secrets/db_password.txt > /dev/null
pwgen -s 64 1 | tr -d '\n' | sudo tee /home/${SITE_USER}/secrets/db_root_password.txt > /dev/null
for s in auth_key secure_auth_key logged_in_key nonce_key auth_salt secure_auth_salt logged_in_salt nonce_salt; do
  openssl rand -base64 48 | sudo tee /home/${SITE_USER}/secrets/${s}.txt > /dev/null
done
```

Set **`table_prefix.txt` to match the old site's `$table_prefix`** from the old `wp-config.php` so WordPress recognizes the imported tables, and enter SMTP credentials for `go-notifier`:

```bash
sudo nano /home/${SITE_USER}/secrets/table_prefix.txt
sudo nano /home/${SITE_USER}/secrets/smtp_host.txt
sudo nano /home/${SITE_USER}/secrets/smtp_port.txt
sudo nano /home/${SITE_USER}/secrets/smtp_mail.txt
sudo nano /home/${SITE_USER}/secrets/smtp_password.txt

sudo chown -R root:${SITE_USER} /home/${SITE_USER}/secrets
sudo chmod 0750 /home/${SITE_USER}/secrets
sudo chmod 0440 /home/${SITE_USER}/secrets/*.txt
```

Download service configurations, Compose manifest, environment files, and mu-plugins:

```bash
sudo curl -fsSL ${REPO}/config/php/php-fpm.conf -o /home/${SITE_USER}/config/php/php-fpm.conf
sudo curl -fsSL ${REPO}/config/php/php.ini -o /home/${SITE_USER}/config/php/php.ini
sudo curl -fsSL ${REPO}/config/php/opcache.ini -o /home/${SITE_USER}/config/php/opcache.ini
sudo curl -fsSL ${REPO}/config/php/www.conf -o /home/${SITE_USER}/config/php/www.conf
sudo curl -fsSL ${REPO}/config/mysql/my.cnf -o /home/${SITE_USER}/config/mysql/my.cnf
sudo curl -fsSL ${REPO}/config/valkey/valkey.conf -o /home/${SITE_USER}/config/valkey/valkey.conf
sudo curl -fsSL ${REPO}/config/angie/angie.conf -o /home/${SITE_USER}/config/angie/angie.conf
sudo curl -fsSL ${REPO}/config/angie/mime.types -o /home/${SITE_USER}/config/angie/mime.types
sudo curl -fsSL ${REPO}/config/angie/modules.conf -o /home/${SITE_USER}/config/angie/modules.conf
sudo chown -R root:${SITE_USER} /home/${SITE_USER}/config
sudo find /home/${SITE_USER}/config -type f -exec chmod 0640 {} +

sudo curl -fsSL ${REPO}/docker-compose.yaml -o /home/${SITE_USER}/docker-compose.yaml
sudo curl -fsSL ${REPO}/examples/.env.example -o /home/${SITE_USER}/.env
sudo curl -fsSL ${REPO}/examples/wordpress.env.example -o /home/${SITE_USER}/wordpress.env
sudo curl -fsSL ${REPO}/examples/notifier.env.example -o /home/${SITE_USER}/notifier.env
sudo chmod 0600 /home/${SITE_USER}/{docker-compose.yaml,.env,wordpress.env,notifier.env}

sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-config.php.example -o /home/${SITE_USER}/data/wp-config.php
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-notify.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-notify.php
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-content/mu-plugins/wp-performance.php.example \
  -o /home/${SITE_USER}/data/wp-content/mu-plugins/wp-performance.php
```

Configure `.env` (set `SITE_USER`, `APP_UID`, `APP_GID` from `id ${SITE_USER}`), `wordpress.env`, `notifier.env`, and transfer any custom plugin/theme constants from your old `wp-config.php` into `/home/${SITE_USER}/data/wp-config.php`:

```bash
sudo nano /home/${SITE_USER}/.env
sudo nano /home/${SITE_USER}/wordpress.env
sudo nano /home/${SITE_USER}/notifier.env
sudo -u ${SITE_USER} nano /home/${SITE_USER}/data/wp-config.php
```

Transfer `wordpress-files.tar.gz` to `/home/${SITE_USER}/` and the SQL dump `site.sql` into `/home/${SITE_USER}/mariadb-backup/` (e.g. via `scp`/`rsync`).

### 4. Extract the archive and fix ownership

```bash
sudo -u ${SITE_USER} tar -xzf /home/${SITE_USER}/wordpress-files.tar.gz \
  -C /home/${SITE_USER}/data \
  --exclude=wp-config.php
sudo chown -R ${SITE_USER}:${SITE_USER} /home/${SITE_USER}/data
sudo chmod 0700 /home/${SITE_USER}/data
sudo find /home/${SITE_USER}/data -mindepth 1 -type d -exec chmod 0755 {} +
sudo find /home/${SITE_USER}/data -type f -exec chmod 0644 {} +
```

### 5. Replace old filesystem paths in files

On a classic host, WordPress was located at an arbitrary path (`/old/path/to/site/root`). In this containerized stack, the WordPress root is always `/var/www/html`.

Before starting the containers, update all absolute filesystem paths inside `.php`, `.json`, `.ini`, `.txt`, `.conf`, and `.xml` files:

```bash
# Standard filesystem paths
find /home/${SITE_USER}/data -type f \( -name "*.php" -o -name "*.json" -o -name "*.ini" -o -name "*.txt" -o -name "*.conf" -o -name "*.xml" \) -exec sed -i 's|/old/path/to/site/root|/var/www/html|g' {} +

# JSON-escaped paths (common in security rewrite rules and serialized config files)
find /home/${SITE_USER}/data -type f \( -name "*.php" -o -name "*.json" -o -name "*.ini" -o -name "*.txt" -o -name "*.conf" -o -name "*.xml" \) -exec sed -i 's|\\/old\\/path\\/to\\/site\\/root|\\/var\\/www\\/html|g' {} +
```

### 6. Start the stack

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml --profile tools pull
docker compose -f /home/${SITE_USER}/docker-compose.yaml up -d
```

On first start MariaDB creates the database and user from the secrets, and the `init` service synchronizes the WordPress core (`wp-admin/`, `wp-includes/`, root files) to the image version — old core files from the archive get upgraded automatically, user data and `wp-content/` are never touched.

> WARNING: `/home/${SITE_USER}/mariadb` must be **empty** at the first start, otherwise the database and user from the secrets will not be created. If a previous experiment already initialized it, clear `mariadb/` before proceeding.

### 7. Import the database dump

You can import the database using either WP-CLI or your preferred desktop database client:

#### Option A: Import via WP-CLI (recommended)

Stream the plain SQL dump directly into MariaDB:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm -T wp-cli db import - < /home/${SITE_USER}/mariadb-backup/site.sql
```

#### Option B: Import via Desktop DB Client (DB Bridge)

If you prefer a graphical interface:
1. Start the database bridge as described in [DB Bridge](#db-bridge):
   ```bash
   docker compose -f /home/${SITE_USER}/docker-compose.yaml --profile tools up -d db-bridge
   ```
2. Establish the SSH tunnel and connect your client (DataGrip, DBeaver, TablePlus) to `127.0.0.1:3307`.
3. Execute or import `site.sql` into the database named in `secrets/db_name.txt`.
4. Stop the bridge when finished:
   ```bash
   docker compose -f /home/${SITE_USER}/docker-compose.yaml --profile tools stop db-bridge
   ```

### 8. Replace old filesystem paths in the database and flush caches

The database often stores old filesystem paths in serialized plugin settings, widget caches, and options. Use WP-CLI to safely perform search-and-replace across all tables, then delete transients and flush the object cache:

```bash
# Dry run to preview changes
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli search-replace '/old/path/to/site/root' '/var/www/html' --all-tables --dry-run

# Apply changes to all tables
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli search-replace '/old/path/to/site/root' '/var/www/html' --all-tables

# Clear transients, flush object cache, and regenerate rewrite rules
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli transient delete --all
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli cache flush
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli rewrite flush
```

### 9. Update Site URL (only if changed)

If the site keeps the same domain and scheme, **skip this step**: all URLs in the dump remain valid.

If the domain changed or if upgrading from plain HTTP to HTTPS, check the current database URLs:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli option get siteurl
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli option get home
```

Then update both WordPress options and rewrite URLs across all tables:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli option update home 'https://wordpress.example'
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli option update siteurl 'https://wordpress.example'
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli search-replace 'http://old.wordpress.example' 'https://wordpress.example' --all-tables --skip-columns=guid
```

### 10. Verify Object Cache (if used)

If the site uses an object cache drop-in (`object-cache.php`), verify that it connects to Valkey:

```bash
# Check status (works for both Redis Object Cache and Object Cache Pro):
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli redis status

# Detailed diagnostics (Object Cache Pro):
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli redis diagnostics
```

### 11. Configure Edge Reverse Proxy & DNS

Configure the domain virtual host in the host's Edge reverse proxy to route traffic to `${SITE_USER}_angie` (see [External Angie](#edge-reverse-proxy-external-angie)).

> TIP: Pre-cutover Testing: You can safely test proxy routing and WordPress response directly on the server before pointing public DNS:

```bash
curl -k -I --resolve wordpress.example:443:127.0.0.1 https://wordpress.example
```

*(Replace `wordpress.example` with your actual domain).* Once verified, point your domain's DNS A/AAAA records to the server IP. Edge Angie's native ACME will automatically negotiate and issue the Let's Encrypt TLS certificate as soon as DNS traffic arrives.

### 12. Verification

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli core version
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli db check
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli plugin list
curl -I https://wordpress.example
```

Check the homepage, media assets, and the admin dashboard in your browser. Old `.htaccess` files from the archive are inert — the stack routes requests through the Angie proxy, not Apache.

</details>

---

<details>
<summary><strong>Development</strong></summary>

## Local Development & Image Building

Clone the repository:

```bash
git clone git@github.com:webstudiobond/wordpress-docker.git
cd wordpress-docker
```

Build the FPM image:

```bash
docker compose -f docker-compose.dev.yaml build
```

Build the CLI image (it is under the `tools` profile):

```bash
docker compose -f docker-compose.dev.yaml --profile tools build wp-cli
```

> NOTE: In `docker-compose.dev.yaml`, the `notifier` service, its `sockets_notify` volume, and its SMTP secrets are commented out by default so that local development environments can run without configuring mail credentials. If you wish to test notifications locally (e.g. using Mailpit or an external SMTP relay), uncomment the `notifier` service, `sockets_notify` volume mounts/definition, and `smtp_*` secrets in `docker-compose.dev.yaml` and provide the corresponding secret files.

### Code Quality Checks

Ensure PHP 8.5, PHPStan, and PHP_CodeSniffer are installed, then run:

```bash
find . -type f \( -name "*.php" -o -name "*.php.example" \) -print0 | xargs -0 -n1 php -l
phpstan analyse --debug --no-progress
phpcs
```

All checks must pass with zero errors:
- **PHPStan** runs at **Level 9** (strictest) — see [phpstan.neon](phpstan.neon)
- **PHP_CodeSniffer** enforces **strict PSR-12** with zero rule exclusions — see [phpcs.xml](phpcs.xml)

For pull requests, CI additionally runs **Hadolint** (Dockerfile linting), **Docker Compose** config validation, and **Trivy** filesystem vulnerability scanning — see [ci.yml](.github/workflows/ci.yml).

</details>

---

<details>
<summary><strong>License & Attribution</strong></summary>

## License & Attribution

* This repository and deployment architecture are licensed under the [MIT License](LICENSE).
* **WordPress License & Ownership:** WordPress is free, open-source software licensed under the [GNU General Public License v2 or later (GPLv2+)](https://github.com/WordPress/WordPress?tab=License-1-ov-file) and belongs to [WordPress](https://github.com/WordPress) and the WordPress Foundation. This project is an independent containerized deployment architecture and is not affiliated with, endorsed, or sponsored by WordPress or the WordPress Foundation.

</details>
