# UNIX Socket Mail Dispatcher

* **Slug:** `notify`
* **Source:** `mu-plugins/sbs-platform/wp-notify.php`
* **Activation:** Enabled by default (or explicitly `WP_ENABLED_MU_PLUGINS=notify`)

## Overview

Dispatches WordPress transactional email over an isolated UNIX domain socket to `go-notifier`:

* In hardened environments, the PHP-FPM container runs with no local mail transfer agent (`sendmail`/`postfix`) and no interactive shell tools.
* Overrides `wp_mail()` by hooking `pre_wp_mail`, packing email headers, recipients, attachments, and MIME bodies into a compact JSON payload.
* Transmits the JSON payload over a local UNIX socket (`/var/run/sockets/notify/notify.sock`) to the asynchronous daemon `go-notifier`.
* Eliminates the need for third-party SMTP plugins and prevents database storage of email server credentials.

## Socket Configuration

The socket location defaults to `/var/run/sockets/notify/notify.sock` and is shared between containers via the Docker volume `sockets_notify`.

Testing socket connectivity from the WordPress container:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli eval \
  '$fp = @fsockopen("unix:///var/run/sockets/notify/notify.sock", -1, $errno, $errstr, 2); if ($fp) { echo "Socket connected\n"; fclose($fp); } else { echo "Failed: $errstr ($errno)\n"; }'
```
