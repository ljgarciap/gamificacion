# Backend de premios (Laravel 13, PHP 8.3). Imagen de desarrollo: sirve con el servidor integrado de PHP.
# Producción usará php-fpm + nginx (SPEC-002 §8); aquí importa tener el mismo PHP y las mismas extensiones.
FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev libzip-dev git unzip \
 && docker-php-ext-install pdo_pgsql pcntl bcmath zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias primero para aprovechar la caché de capas.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --no-progress

COPY . .
# La comprobación de proxies de producción (GAMIF_TRUSTED_PROXIES) no aplica al construir la imagen de desarrollo.
RUN GAMIF_TRUSTED_PROXIES=none composer dump-autoload --optimize --no-interaction

EXPOSE 8000
# Servidor integrado de PHP (no `artisan serve`: no pasa las variables de entorno al servidor). Solo desarrollo.
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
