# syntax=docker/dockerfile:1.7

FROM php:8.3-cli-alpine AS dependencies
WORKDIR /app

RUN apk add --no-cache icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl zip gd
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# El build de Vite/Tailwind necesita el CSS que trae el paquete filament/filament,
# así que esta etapa depende de `dependencies` (vendor/) en vez de partir en limpio.
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=dependencies /app/vendor ./vendor
RUN npm run build

FROM php:8.3-fpm-alpine AS application
WORKDIR /var/www/html

RUN apk add --no-cache bash curl libzip-dev postgresql-dev icu-dev libxml2-dev nodejs su-exec libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip intl opcache pcntl gd \
    && rm -rf /tmp/*

# Los reportes Kardex pueden contener decenas de miles de filas. PhpSpreadsheet
# carga estructuras XML y shared strings en memoria, por lo que el límite
# predeterminado de 128 MB terminaba abruptamente workers y dejaba locales en
# estado "en_progreso". 512 MB por worker es compatible con 20 workers y 32 GB
# de RAM del entorno local.
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/zz-kardex-memory.ini

COPY --from=dependencies /app/vendor ./vendor
COPY --from=frontend /app/node_modules ./node_modules
COPY --from=frontend /app/public/build ./public/build
COPY . .
COPY docker/app/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && cp -a public /opt/public \
    && rm -f bootstrap/cache/*.php \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/app/entrypoint.sh \
    && php artisan package:discover --ansi

ENV NODE_ENV=production \
    NODE_EXECUTABLE=node \
    COMPOSER_ALLOW_SUPERUSER=1

ENTRYPOINT ["/var/www/html/docker/app/entrypoint.sh"]
CMD ["php-fpm", "-F"]
