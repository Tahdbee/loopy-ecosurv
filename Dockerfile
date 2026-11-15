FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    libonig-dev libpng-dev libxml2-dev libzip-dev \
    && docker-php-ext-install gd mbstring mysqli xml zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY . .
RUN printf "upload_max_filesize=5M\npost_max_size=6M\nmemory_limit=256M\nmax_execution_time=120\n" > /usr/local/etc/php/conf.d/admin-dashboard.ini

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} railway-router.php"]
