# Hardened Containerized WordPress

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
│       └── conf.d/                  # Custom site snippets (blockbots, cache rules, etc.)
├── mariadb/                         # Persistent MariaDB data volume
├── mariadb-backup/                  # Database backups directory
├── tmp/                             # Upload buffering directory (avoids RAM exhaustion)
└── data/                            # WordPress document root
    ├── wp-config.php                # Site configuration (from examples/wp-config.php.example)
    └── ...                          # WordPress core files (auto-populated by init service)
```

</details>

---

<details>
<summary><strong>Deployment &amp; Setup</strong></summary>

### Prerequisites

* **Docker Engine & Compose:** Ensure Docker Engine and Docker Compose plugin are installed on the host. Follow the official installation guide for [Ubuntu](https://docs.docker.com/engine/install/ubuntu/#install-using-the-repository).
* **External Gateway Network:** The stack requires a Docker network named `frontend_gateway` (declared as `external: true`) to communicate with the host's Edge reverse proxy. If this network is not already managed by your Edge Angie stack, create it manually:

```bash
docker network create frontend_gateway
```

### 1. Create a Dedicated System User

Create a system account with a disabled login shell:

```bash
SITE_USER=mysite
sudo useradd -m -d /home/${SITE_USER} -s /usr/sbin/nologin ${SITE_USER}
```

### 2. Create Directory Structure

```bash
sudo -u ${SITE_USER} mkdir -p /home/${SITE_USER}/{data/wp-content/uploads,mariadb,mariadb-backup,tmp,secrets,config/{angie/conf.d,mysql,php,valkey}}
sudo chmod 0700 /home/${SITE_USER}/secrets
```

### 3. Generate Secrets

Install the required tools:

```bash
sudo apt update && sudo apt install -y pwgen openssl cron zstd
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
```

### 5. Download Compose Manifest and Application Config

```bash
sudo -u ${SITE_USER} curl -fsSL ${REPO}/docker-compose.yaml -o /home/${SITE_USER}/docker-compose.yaml
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/.env.example -o /home/${SITE_USER}/.env
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/wordpress.env.example -o /home/${SITE_USER}/wordpress.env
sudo -u ${SITE_USER} curl -fsSL ${REPO}/examples/wp-config.php.example -o /home/${SITE_USER}/data/wp-config.php
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

### 7. Server Cron (Recommended)

Disable the virtual web cron by setting `WORDPRESS_DISABLE_CRON=true` in `wordpress.env`, then configure a real system cron for the site user:

```bash
sudo crontab -u ${SITE_USER} -e
```

> [!NOTE]
> Environment variables such as `${SITE_USER}` are not automatically expanded in crontab. Replace `/home/mysite/` with the actual absolute path to your site directory:

```cron
*/10 * * * * docker compose -f /home/mysite/docker-compose.yaml run --rm --no-deps wp-cli cron event run --due-now >/dev/null 2>&1
```

### 8. Memory Limits

If you need to change PHP memory limits, they must be adjusted **consistently** across three places:

1. `wordpress.env` — `WORDPRESS_MEMORY_LIMIT` and `WORDPRESS_MAX_MEMORY_LIMIT`
2. `docker-compose.yaml` — `mem_limit` for the `wordpress` service
3. `config/php/www.conf` — FPM pool memory-related directives

### 9. Set Permissions

After all directories, configurations, and secrets have been created and edited, set ownership to the site user across the entire directory and apply strict permissions:

```bash
sudo chown -R ${SITE_USER}:${SITE_USER} /home/${SITE_USER}
sudo chmod 0700 /home/${SITE_USER}/secrets
sudo chmod 0400 /home/${SITE_USER}/secrets/*.txt
sudo chmod 0600 /home/${SITE_USER}/.env
sudo chmod 0600 /home/${SITE_USER}/wordpress.env
```

### 10. Start the Stack

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
<summary><strong>WP-CLI</strong></summary>

WP-CLI is an on-demand tool service under `profiles: [tools]` — it does not start with the main stack and only runs when explicitly invoked.

See the [official WP-CLI command reference](https://developer.wordpress.org/cli/commands/) for all available commands.

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli core version
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli db check
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli plugin list
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm wp-cli plugin update --all
```

For convenience, create a shell alias per site:

```bash
nano ~/.bashrc
```

```bash
function wp_mysite() {
    docker compose -f /home/mysite/docker-compose.yaml run --rm wp-cli "$@"
}
```

```bash
source ~/.bashrc
```

For multiple sites, add a function per site (e.g. `wp_site1`, `wp_site2`). After that:

```bash
wp_mysite core version
wp_mysite plugin list
```

### Database Backup (wp db export)

Create a fast, highly-compressed database backup using `zstd` (install with `sudo apt install -y zstd`):

```bash
docker compose -f /home/mysite/docker-compose.yaml run --rm wp-cli db export - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +%Y%m%d_%H%M%S).sql.zst
```

Or using the shell alias:

```bash
wp_mysite db export - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +%Y%m%d_%H%M%S).sql.zst
```

To automate daily backups, add a job to the site user's crontab (`sudo crontab -u ${SITE_USER} -e`):

```cron
# Daily backup at 03:00 (note: % characters must be escaped with \ in crontab)
0 3 * * * docker compose -f /home/mysite/docker-compose.yaml run --rm --no-deps wp-cli db export - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +\%Y\%m\%d_\%H\%M\%S).sql.zst
```

To automatically remove backups older than 14 days:

```cron
0 3 * * * docker compose -f /home/mysite/docker-compose.yaml run --rm --no-deps wp-cli db export - | zstd -q > /home/mysite/mariadb-backup/db_backup_$(date +\%Y\%m\%d_\%H\%M\%S).sql.zst && find /home/mysite/mariadb-backup -type f -name "*.sql.zst" -mtime +14 -delete
```

To restore a database dump:

```bash
zstd -dc /home/mysite/mariadb-backup/db_backup_YYYYMMDD_HHMMSS.sql.zst | wp_mysite db import -
```

### CLI Host & URL Context

If your site or plugins rely on `HTTP_HOST` for dynamic URLs, configure the URL context using either:

1. **`wp-cli.yml` (recommended):**
   ```bash
   sudo -u ${SITE_USER} nano /home/${SITE_USER}/data/wp-cli.yml
   ```
   ```yaml
   url: https://example.com
   ```
2. **`WORDPRESS_CLI_HOST`:** Set `WORDPRESS_CLI_HOST=example.com` in `wordpress.env`.

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

> [!IMPORTANT]
> Always stop the bridge when you are finished:
> ```bash
> docker compose -f /home/${SITE_USER}/docker-compose.yaml --profile tools stop db-bridge
> ```

</details>

---

<details>
<summary><strong>External Angie</strong></summary>

## Edge Reverse Proxy (External Angie)

Each site stack includes an internal Angie container that handles FastCGI routing to PHP-FPM via the UNIX socket. It does **not** publish any ports on the host. An external Edge Angie instance running on the host terminates TLS and forwards incoming traffic to the internal Angie instances.

The external Edge Angie operates in the **`frontend_gateway`** network. All WordPress site stacks connect their internal Angie container (`${SITE_USER}_angie`) to this network, allowing Edge Angie to route requests directly by container name.

See the [official Angie configuration documentation](https://en.angie.software/angie/docs/configuration/) for detailed directive references.

Example Edge Angie site configuration with native ACME certificate management (`/etc/angie/http.d/mysite.conf`):

```nginx
acme_client example https://acme-v02.api.letsencrypt.org/directory;

server {
    listen 80;
    listen [::]:80;

    listen 443 ssl;

    server_name example.com;

    acme example;

    ssl_certificate $acme_cert_example;
    ssl_certificate_key $acme_cert_key_example;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    access_log /var/log/angie/domains/example.com.log extended;
    error_log /var/log/angie/domains/example.com.error.log error;

    client_max_body_size 256M;

    if ($scheme = http) {
        return 301 https://$host$request_uri;
    }

    location / {
        proxy_pass http://mysite_angie:80;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_connect_timeout 60s;
        proxy_send_timeout 300s;
        proxy_read_timeout 300s;
    }
}
```

The `proxy_pass` target `mysite_angie` corresponds to the `container_name` of the internal Angie service (formatted as `${SITE_USER}_angie`).

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
