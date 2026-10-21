FROM wordpress:cli-2.12.0-php8.5@sha256:52d8832dc8b90022c22fe963e696e4107d86f68b9b891e9624d82fec94fdc3b7 AS wpcli-donor

FROM wordpress:7.1.3-php8.5-fpm-alpine@sha256:a98f10983d9ea107067a36fbf650af47d553d83df0568183cc0ad6fe9b90b3a5 AS wordpress-donor

FROM --platform=$BUILDPLATFORM golang:1.27.2-alpine3.24@sha256:f92b6ef800e499660581efdabdf25d9d817a9d124eaf900924f0504e7e27e12d AS wp-guard-builder

WORKDIR /src

COPY go.mod ./
RUN go mod download
COPY cmd/ cmd/
COPY internal/ internal/
COPY mu-plugins/ /mu-plugins-src/
COPY prepend/ /prepend-src/
COPY --from=wordpress-donor /usr/src/wordpress /usr/src/wordpress

ARG TARGETOS
ARG TARGETARCH

RUN set -eux; \
    CGO_ENABLED=0 go build -ldflags="-s -w" -trimpath -o /bin/wp-guard-host ./cmd/wp-guard; \
    mkdir -p /usr/local/share/wordpress/mu-plugins /usr/local/share/wordpress/prepend; \
    cp -a /mu-plugins-src/sbs-platform/. /usr/local/share/wordpress/mu-plugins/; \
    cp /mu-plugins-src/sbs-loader.php /usr/local/share/wordpress/sbs-loader.php; \
    cp /prepend-src/sbs-prepend.php /usr/local/share/wordpress/prepend/sbs-prepend.php; \
    /bin/wp-guard-host manifest generate; \
    find /usr/local/share/wordpress -type d -exec chmod 0555 {} +; \
    find /usr/local/share/wordpress -type f -exec chmod 0444 {} +; \
    CGO_ENABLED=0 GOOS=${TARGETOS:-linux} GOARCH=${TARGETARCH:-amd64} go build \
        -ldflags="-s -w" \
        -trimpath \
        -o /bin/wp-guard \
        ./cmd/wp-guard

FROM debian:trixie-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f AS base

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
    mkdir -p /var/run/sockets/mysql /var/run/sockets/valkey /var/run/sockets/php /var/run/sockets/notify /var/www/html /var/www/tmp /run/php; \
    chmod 1777 /var/www/tmp /run/php; \
    chmod 0755 /var/run/sockets; \
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

COPY --from=wp-guard-builder --chmod=0555 /bin/wp-guard /usr/local/bin/wp-guard

WORKDIR /var/www/html

FROM base AS cli

HEALTHCHECK NONE

# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends git mariadb-client less && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=wpcli-donor --chmod=0555 /usr/local/bin/wp /usr/local/bin/wp

ENTRYPOINT ["wp"]

FROM base AS fpm

COPY --from=wordpress-donor /usr/src/wordpress /usr/src/wordpress
COPY --from=wp-guard-builder /usr/local/share/wordpress /usr/local/share/wordpress

RUN find /usr/local/share/wordpress -type d -exec chmod 0555 {} + && \
    find /usr/local/share/wordpress -type f -exec chmod 0444 {} + && \
    find /usr/src/wordpress -type d -exec chmod 0555 {} + && \
    find /usr/src/wordpress -type f -exec chmod 0444 {} + && \
    rm -f /bin/sh /bin/dash /bin/bash /usr/bin/sh /usr/bin/dash /usr/bin/bash

STOPSIGNAL SIGQUIT

HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD ["/usr/bin/env", "-i", "SCRIPT_NAME=/ping", "SCRIPT_FILENAME=/ping", "REQUEST_METHOD=GET", "cgi-fcgi", "-bind", "-connect", "/var/run/sockets/php/php-fpm.sock"]

ENTRYPOINT ["php-fpm", "-F"]
