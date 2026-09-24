FROM wordpress:cli-2.12.0-php8.5@sha256:c522811ac21e737b9e986adfc068bdd085fb5b3bfbd786642303336114ea78c4 AS wpcli-donor

FROM wordpress:7.1.2-php8.5-fpm-alpine@sha256:72435cd45884447f7d679390fc7f65808943f9bf0e2d5c8992b47e57089f948c AS wordpress-donor

FROM debian:trixie-slim@sha256:a99cfc517144bc59b1978475ec53b46ecabec7e43635402ee5b77cc54cd1b20a AS base

LABEL maintainer="underhax" \
      description="Hardened, zero-network WordPress runtime featuring UNIX domain socket IPC, integrated WP-CLI, Redis/Valkey caching, restricted ImageMagick policies, and AVIF/WebP codecs"

ENV DEBIAN_FRONTEND=noninteractive \
    LC_ALL=C.UTF-8 \
    LANG=C.UTF-8

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# hadolint ignore=DL3008
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg; \
    install -m 0755 -d /etc/apt/keyrings; \
    curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /etc/apt/keyrings/deb.sury.org-php.gpg; \
    chmod 0644 /etc/apt/keyrings/deb.sury.org-php.gpg; \
    echo "deb [signed-by=/etc/apt/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ trixie main" \
        > /etc/apt/sources.list.d/php.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        php8.5-fpm \
        php8.5-cli \
        php8.5-common \
        php8.5-mysql \
        php8.5-redis \
        php8.5-igbinary \
        php8.5-zstd \
        php8.5-imagick \
        php8.5-apcu \
        php8.5-gmp \
        php8.5-intl \
        php8.5-zip \
        php8.5-bcmath \
        php8.5-curl \
        php8.5-mbstring \
        php8.5-xml \
        php8.5-gd \
        webp \
        libavif-bin \
        libfcgi-bin; \
    apt-get purge -y --auto-remove gnupg; \
    ln -sf /usr/sbin/php-fpm8.5 /usr/local/sbin/php-fpm; \
    ln -sf /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm; \
    ln -sf /usr/bin/php8.5 /usr/local/bin/php; \
    POLICIES=$(find /etc/ImageMagick-* /usr/local/etc/ImageMagick-* -name "policy.xml" 2>/dev/null || true); \
    if [ -n "$POLICIES" ]; then \
        for p in $POLICIES; do \
            for coder in EPHEMERAL URL HTTPS FTP MVG MSVG TEXT SHOW WIN PLT PS EPS PDF XPS; do \
                sed -i "/pattern=[\"']${coder}[\"']/d" "$p"; \
                sed -i "/<\/policymap>/i \  <policy domain=\"coder\" rights=\"none\" pattern=\"${coder}\" />" "$p"; \
            done; \
        done; \
    fi; \
    find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true; \
    mkdir -p /var/run/sockets /var/www/html /var/www/tmp /run/php; \
    chmod 1777 /var/www/tmp /run/php; \
    chmod 0700 /var/run/sockets; \
    sed -i 's|^error_log = .*|error_log = /proc/self/fd/2|' /etc/php/*/fpm/php-fpm.conf 2>/dev/null || true; \
    sed -i 's|^pid = .*|pid = /tmp/php-fpm.pid|' /etc/php/*/fpm/php-fpm.conf 2>/dev/null || true; \
    find /etc/php -mindepth 1 -maxdepth 1 -type d -exec ln -s {} /etc/php/current \; 2>/dev/null || true; \
    apt-get clean; \
    rm -rf \
        /var/lib/apt/lists/* \
        /tmp/* \
        /var/tmp/* \
        /usr/share/man \
        /usr/share/doc \
        /usr/share/info

WORKDIR /var/www/html

FROM base AS cli

HEALTHCHECK NONE

# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends git mariadb-client && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=wpcli-donor --chmod=0755 /usr/local/bin/wp /usr/local/bin/wp

ENTRYPOINT ["wp"]

FROM base AS fpm

COPY --from=wordpress-donor /usr/src/wordpress /usr/src/wordpress
COPY --chmod=0755 build/wp-sync.php /usr/local/bin/wp-sync.php

RUN rm -f /bin/sh /bin/dash /bin/bash /usr/bin/sh /usr/bin/dash /usr/bin/bash

STOPSIGNAL SIGQUIT

HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD ["/usr/bin/env", "SCRIPT_NAME=/ping", "SCRIPT_FILENAME=/ping", "REQUEST_METHOD=GET", "cgi-fcgi", "-bind", "-connect", "/var/run/sockets/php-fpm.sock"]

ENTRYPOINT ["php-fpm", "-F"]
