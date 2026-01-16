FROM php:8.4-fpm-alpine

# System deps
RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    linux-headers \
    $PHPIZE_DEPS

# PHP extensions needed by Laravel + PostgreSQL
RUN docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    intl \
    mbstring \
    zip \
    opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create non-root user (optional but recommended)
RUN addgroup -g 1000 www && adduser -G www -g www -s /bin/sh -D -u 1000 www

WORKDIR /var/www/html

# Copy app
COPY . .

# Permissions for Laravel storage/cache
RUN mkdir -p storage bootstrap/cache \
 && chown -R www:www /var/www/html \
 && chmod -R 775 storage bootstrap/cache

USER www

# Install dependencies (no-dev for production; you can change for local)
RUN composer install --no-interaction --prefer-dist

EXPOSE 9000
CMD ["php-fpm"]
