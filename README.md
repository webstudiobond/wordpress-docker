# Hardened Containerized WordPress

[![CI](https://github.com/webstudiobond/wordpress-docker/actions/workflows/ci.yml/badge.svg)](https://github.com/webstudiobond/wordpress-docker/actions/workflows/ci.yml)
[![GitHub last commit](https://img.shields.io/github/last-commit/underhax/mihomo-warp-proxy)](https://github.com/webstudiobond/wordpress-docker/commits/main)
[![GitHub issues](https://img.shields.io/github/issues/underhax/mihomo-warp-proxy)](https://github.com/webstudiobond/wordpress-docker/issues)
[![GitHub repo size](https://img.shields.io/github/repo-size/underhax/mihomo-warp-proxy)](https://github.com/webstudiobond/wordpress-docker)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Production-ready, fully decoupled, and resource-efficient containerized WordPress deployment architecture for multi-tenant hosts. Built on the latest-generation [PHP-FPM](https://packages.sury.org/php/) with all extensions required by WordPress (MySQLi, PDO, cURL, mbstring, XML, GD, Intl, Zip, BCMath, Exif) plus additional modules (Redis, igbinary, zstd, ImageMagick, APCu, GMP, OPcache) and media codecs (WebP, AVIF) pre-installed in the image, latest LTS [MariaDB](https://mariadb.org/), [Angie](https://en.angie.software/) reverse proxy, and [Valkey](https://valkey.io/) in-memory cache.

## Architecture Highlights

* **Shell-less FPM Runtime:** Web container has all shell binaries completely removed. Zero capability to spawn shell processes, even in the event of an arbitrary code execution exploit.
* **Pure UNIX Domain Socket IPC:** Zero exposed TCP network ports for MariaDB, Valkey, and PHP-FPM. All inter-service communication goes through a high-performance in-memory tmpfs socket directory accessible only to the container user.
* **Zero-Privilege Security Profile:** Read-only root filesystems, all Linux capabilities dropped (`cap_drop: [ALL]`) with none added back (`cap_add: []`), privilege escalation blocked (`no-new-privileges`), running as a dedicated unprivileged host user.
* **Atomic Version Upgrades:** An automated pre-flight init service compares `wp-includes/version.php` between the image donor and the live site. On version mismatch it atomically replaces `wp-admin/`, `wp-includes/`, and root PHP files without ever touching `wp-content/` or user data.
* **Strict Docker Secrets:** All sensitive data — database credentials, database name, table prefix, and all eight authentication keys and salts — are loaded exclusively from secret files. No plaintext credentials in environment variables, `.env`, or Compose manifests. Missing or empty secrets cause an immediate fatal error, preventing the application from starting.

---

<details>
<summary><strong>Directory Structure</strong></summary>

```text
├── docker-compose.yaml              # Production deployment manifest
├── .env                             # Host infrastructure variables (UID, GID, image versions)
├── wordpress.env                    # WordPress application overrides (cron, URLs, memory, debug, etc.)
├── secrets/                         # Docker secrets directory (owner-only access)
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
│   └── nonce_salt.txt               # Nonce salt
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
sudo -u ${SITE_USER} mkdir -p /home/${SITE_USER}/{.wp-cli,data/wp-content/uploads,mariadb,mariadb-backup,tmp,secrets,config/{angie/conf.d,mysql,php,valkey}}
sudo chmod 0700 /home/${SITE_USER}/secrets
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
sudo chmod 0400 /home/${SITE_USER}/secrets/*.txt
```

### 4. Download Configuration Files

```bash
REPO="https://raw.githubusercontent.com/webstudiobond/wordpress-docker/main"

sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/php/php-fpm.conf -o /home/${SITE_USER}/config/php/php-fpm.conf
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/php/php.ini -o /home/${SITE_USER}/config/php/php.ini
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/php/opcache.ini -o /home/${SITE_USER}/config/php/opcache.ini
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/php/www.conf -o /home/${SITE_USER}/config/php/www.conf
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/mysql/my.cnf -o /home/${SITE_USER}/config/mysql/my.cnf
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/valkey/valkey.conf -o /home/${SITE_USER}/config/valkey/valkey.conf
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/angie/angie.conf -o /home/${SITE_USER}/config/angie/angie.conf
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/angie/mime.types -o /home/${SITE_USER}/config/angie/mime.types
sudo -u ${SITE_USER} curl -fsSL ${REPO}/config/angie/modules.conf -o /home/${SITE_USER}/config/angie/modules.conf
```

### 5. Download Compose Manifest and Application Config

```bash
sudo -u ${SITE_USER} curl -fsSL ${REPO}/docker-compose.yaml -o /home/${SITE_USER}/docker-compose.yaml
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/.env.example -o /home/${SITE_USER}/.env
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/wordpress.env.example -o /home/${SITE_USER}/wordpress.env
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/data/wp-config.php.example -o /home/${SITE_USER}/data/wp-config.php
```

### 6. Configure Environment

Edit `.env` to set the site user and UID/GID matching the system account created in Step 1 (check with `id ${SITE_USER}`):

```bash
sudo -u ${SITE_USER} nano /home/${SITE_USER}/.env
```

See [examples/.env.example](examples/.env.example) for all available variables.

Most WordPress application settings (cron, memory limits, URLs, debug mode, etc.) can be tuned in `wordpress.env` without editing `wp-config.php`:

```bash
sudo -u ${SITE_USER} nano /home/${SITE_USER}/wordpress.env
```

See [examples/wordpress.env.example](examples/wordpress.env.example) for all available overrides and the [official wp-config.php documentation](https://developer.wordpress.org/advanced-administration/wordpress/wp-config/) for detailed parameter descriptions.

### 7. Memory Limits

If you need to change PHP memory limits, they must be adjusted **consistently** across three places:

1. `wordpress.env` — `WORDPRESS_MEMORY_LIMIT` and `WORDPRESS_MAX_MEMORY_LIMIT`
2. `docker-compose.yaml` — `mem_limit` for the `wordpress` service
3. `config/php/www.conf` — FPM pool memory-related directives

### 8. Set Permissions

After all directories, configurations, and secrets have been created and edited, set ownership to the site user across the entire directory and apply strict permissions:

```bash
sudo chown -R ${SITE_USER}:${SITE_USER} /home/${SITE_USER}
sudo chmod 0700 /home/${SITE_USER}/secrets
sudo chmod 0400 /home/${SITE_USER}/secrets/*.txt
sudo chmod 0600 /home/${SITE_USER}/.env
sudo chmod 0600 /home/${SITE_USER}/wordpress.env
```

### 9. Start the Stack

Pull images and start:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml pull
docker compose -f /home/${SITE_USER}/docker-compose.yaml up -d
```

On first run, the `init` service automatically populates `data/` with clean WordPress core files and exits. When deploying a new image (`docker compose pull` followed by `docker compose up -d`), `init` automatically upgrades or rolls back WordPress core files (`wp-admin/`, `wp-includes/`, root PHP files) to strictly match the container image version, without ever touching user data, uploads, or `wp-content/`.

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
<summary><strong>External Angie</strong></summary>

## Edge Reverse Proxy (External Angie)

Each site stack includes an internal Angie container that handles FastCGI routing to PHP-FPM via the UNIX socket. It does **not** publish any ports on the host. An external Edge Angie instance running on the host acts as the hardened perimeter gateway: it terminates TLS, manages automated certificates, enforces perimeter security, and forwards incoming traffic to the internal site containers.

The external Edge Angie operates in the **`frontend_gateway`** network (`172.20.0.0/16`). All WordPress site stacks connect their internal Angie container (`${SITE_USER}_angie`) to this network, allowing Edge Angie to route requests directly by container name.

A complete, production-hardened, and optimized Edge reverse proxy deployment with a security-by-default architecture is available in the **[angie-docker-compose](https://github.com/webstudiobond/angie-docker-compose)** repository.

See the canonical site virtual host template:
* **[`data/conf.d/domains/wordpress.conf`](https://github.com/webstudiobond/angie-docker-compose/blob/main/data/conf.d/domains/wordpress.conf)** — production reverse proxy virtual host configuration (automated ACME TLS lifecycle, HTTP/3 QUIC, TLS 1.3 0-RTT anti-replay mitigation, upstream keepalive pooling to `${SITE_USER}_angie:80`, baseline security headers, HSTS, anonymous perimeter error pages, real client IP forwarding, tuned WordPress timeouts and body limits, etc.).

For each WordPress site, download the template into your Edge Angie configuration directory (`data/conf.d/domains/`), renaming the config file to your unique site identifier (e.g., `${SITE_USER}.conf` as defined earlier):

```bash
curl -fsSL https://raw.githubusercontent.com/webstudiobond/angie-docker-compose/main/data/conf.d/domains/wordpress.conf \
  -o data/conf.d/domains/${SITE_USER}.conf
```

> WARNING: Replace 'wordpress.example' with your domain and 'mysite' with '${SITE_USER}' so upstream requests resolve to '${SITE_USER}_angie:80'.

Substitute the placeholders inside the downloaded configuration file using `sed`:

```bash
DOMAIN="example.com"

sed -i \
  -e "s|wordpress\.example|${DOMAIN}|g" \
  -e "s|mysite|${SITE_USER}|g" \
  data/conf.d/domains/${SITE_USER}.conf
```

Review and customize the configuration as needed (for example, add the `www` subdomain to `server_name` or adjust `Conditional Access Logging` rules):

```bash
nano data/conf.d/domains/${SITE_USER}.conf
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
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli db export --single-transaction --quick - | zstd -q > /home/${SITE_USER}/mariadb-backup/db_backup_$(date +%Y%m%d_%H%M%S).sql.zst
```

Or using the shell alias:

```bash
wp_mysite db export --single-transaction --quick - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +%Y%m%d_%H%M%S).sql.zst
```

To restore a compressed database backup:

```bash
zstd -dc /home/${SITE_USER}/mariadb-backup/db_backup_YYYYMMDD_HHMMSS.sql.zst | docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm -T wp-cli db import -
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
sudo -u ${SITE_USER} nano /home/${SITE_USER}/wordpress.env
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
0 */6 * * * root docker compose -f /home/mysite/docker-compose.yaml run --rm wp-cli db export --single-transaction --quick - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +\%Y\%m\%d_\%H\%M\%S).sql.zst && find /home/mysite/mariadb-backup -type f -name "*.sql.zst" -mtime +7 -delete && chown -R mysite:mysite /home/mysite/mariadb-backup
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

Transfer `wordpress-files.tar.gz` to `/home/${SITE_USER}/` and the SQL dump `site.sql` into `/home/${SITE_USER}/mariadb-backup/` (e.g. via `scp`/`rsync`).

### 3. On the new host — prepare deployment and secrets

Follow [Deployment & Setup](#deployment--setup) (steps 1–6) to create `${SITE_USER}`, directories, configuration files, and `.env`.

When generating secrets (step 3), **`table_prefix.txt` MUST match the old site's `$table_prefix`** from the old `wp-config.php` so WordPress recognizes the imported tables.

Review your old `wp-config.php` and transfer any plugin or theme-specific constants (such as `WP_REDIS_CONFIG`, license keys, SMTP options, or security constants) into the new `/home/${SITE_USER}/data/wp-config.php` (or into `wordpress.env` for environment-driven variables).

### 4. Extract the archive and fix ownership

```bash
sudo -u ${SITE_USER} tar -xzf /home/${SITE_USER}/wordpress-files.tar.gz \
  -C /home/${SITE_USER}/data \
  --exclude=wp-config.php
sudo chown -R ${SITE_USER}:${SITE_USER} /home/${SITE_USER}
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
