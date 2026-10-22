# syntax=docker/dockerfile:1
# Backend de producción (SPEC-003 P9, RNF-35): php-fpm sin privilegios (target `app`) + nginx sin privilegios (target `web`).
# Dos imágenes porque cada proceso tiene su propio ciclo de vida; `deploy/compose.example.yml` las une. Contexto de build = backend/.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-progress --no-autoloader
# El mapa de clases se genera con el código ya copiado (si no, las clases de la aplicación no entran en él).
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache postgresql-libs libzip \
 && apk add --no-cache --virtual .build postgresql-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-install pdo_pgsql bcmath zip opcache \
 && apk del .build
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-gamification.ini
COPY deploy/fpm.conf /usr/local/etc/php-fpm.d/zz-gamification.conf
WORKDIR /var/www/html
COPY --chown=10001:10001 . .
COPY --from=vendor --chown=10001:10001 /app/vendor ./vendor
# Solo lo que Laravel debe poder escribir; el código queda de solo lectura para el proceso.
RUN rm -rf tests storage/logs/* .env bootstrap/cache/*.php \
 && mkdir -p storage/framework/cache storage/framework/views storage/framework/sessions storage/logs bootstrap/cache \
 && chown -R 10001:10001 storage bootstrap/cache \
 && chmod -R u+rwX,go-rwx storage bootstrap/cache
# Sin los paquetes de desarrollo (Pail, etc.): el caché de paquetes se regenera con lo que realmente hay en vendor/.
RUN php artisan package:discover --ansi && chown -R 10001:10001 bootstrap/cache
USER 10001:10001
EXPOSE 9000
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && exec php-fpm"]

FROM nginxinc/nginx-unprivileged:1.27-alpine AS web
ENV FPM_UPSTREAM="app:9000"
COPY deploy/api.conf.template /etc/nginx/templates/default.conf.template
COPY --from=app /var/www/html/public /var/www/html/public
EXPOSE 8080
