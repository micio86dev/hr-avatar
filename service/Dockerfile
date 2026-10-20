# syntax=docker/dockerfile:1
# Multi-stage image shared by the api, worker and scheduler services: the same bytes run
# locally and on Railway, and each service only passes its own start command.

# ─── Build stage ────────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS builder

COPY --from=mlocati/php-extension-installer:2.11.12 /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache unzip git \
    && install-php-extensions pdo_pgsql zip opcache

COPY --from=composer:2.10.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./

# --no-scripts: package discovery boots the application, which refuses to start without
# HRA_JWT_SECRET, and no secret exists at build time. Laravel rebuilds the manifest at runtime.
# The retry loop covers a registry or mirror outage; composer.lock still decides every version.
RUN set -eu; \
    for attempt in 1 2 3 4; do \
      if composer install --no-dev --no-scripts --no-autoloader; then exit 0; fi; \
      echo "composer install failed (attempt ${attempt}/4); retrying in $((attempt * 20))s"; \
      sleep $((attempt * 20)); \
    done; \
    echo "composer install failed 4 times"; \
    exit 1

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-scripts

# ─── Runtime stage ──────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS runtime

LABEL org.opencontainers.image.title="HR-Avatar service" \
      org.opencontainers.image.description="Provider-agnostic avatar conversation engine (Laravel 13 API)"

COPY --from=mlocati/php-extension-installer:2.11.12 /usr/bin/install-php-extensions /usr/local/bin/

# pcntl + posix: graceful worker shutdown on SIGTERM. redis: the phpredis client used by the
# cache and the queue.
RUN apk add --no-cache postgresql-client curl nginx supervisor \
    && install-php-extensions pdo_pgsql opcache pcntl posix redis \
    && rm -rf /var/cache/apk/*

RUN addgroup -g 1001 -S appgroup && adduser -u 1001 -S appuser -G appgroup

WORKDIR /var/www

COPY --from=builder --chown=appuser:appgroup /var/www .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R appuser:appgroup storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# php-fpm listens on a local TCP socket so nginx (same container, non-root user) needs no shared path.
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.conf
RUN mkdir -p /tmp/nginx-client-body /tmp/nginx-proxy /tmp/nginx-fastcgi /tmp/nginx-uwsgi /tmp/nginx-scgi \
    && chown -R appuser:appgroup /tmp/nginx-* /etc/nginx

# Do not announce the PHP patch level, and keep function arguments out of exception traces.
RUN printf 'expose_php = Off\nzend.exception_ignore_args = On\n' > /usr/local/etc/php/conf.d/99-hardening.ini

USER appuser

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:8000/v1/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
