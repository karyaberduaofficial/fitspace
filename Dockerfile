# =============================================================================
# FitSpace PWA — Hardened Multi-Stage Production Dockerfile (Non-Root PHP 8.2)
# =============================================================================
FROM composer:2.7 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.2-cli-alpine AS runner
WORKDIR /var/www/html

RUN apk add --no-cache \
    icu-dev \
    libxml2-dev \
    oniguruma-dev \
    sqlite-dev \
    curl \
  && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite mbstring xml bcmath opcache \
  && addgroup -g 10001 fitspace \
  && adduser -D -u 10001 -G fitspace fitspace

COPY --from=vendor /app/vendor ./vendor
COPY --chown=fitspace:fitspace . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && chown -R fitspace:fitspace storage bootstrap/cache \
  && chmod -R 775 storage bootstrap/cache

USER fitspace
EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8000/healthz || exit 1

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
