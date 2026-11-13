# [MODUL 7] Image produksi Disty HelpDesk: PHP-FPM + Nginx dalam satu kontainer sederhana.
# Konteks build adalah folder distyhelpdesk/ (akar). Kode Laravel ada di laravel/, kode aplikasi di folder setaranya.
FROM composer:2 AS vendor
WORKDIR /app
COPY auth ./auth
COPY api ./api
COPY admin ./admin
COPY client ./client
COPY teknisi ./teknisi
COPY bersama ./bersama
COPY config ./config
COPY database ./database
COPY tests ./tests
WORKDIR /app/laravel
COPY laravel/composer.json laravel/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo_mysql
WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/laravel/vendor ./laravel/vendor
WORKDIR /var/www/html/laravel
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache public/uploads \
    && chown -R www-data:www-data storage bootstrap/cache public/uploads
USER www-data
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s CMD wget -qO- http://127.0.0.1:8000/api/v1/ping || exit 1
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
