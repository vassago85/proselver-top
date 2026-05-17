FROM php:8.3-fpm-alpine

# Runtime + build deps in a single apk fetch. We bundle the PHP build
# toolchain (autoconf/make/g++/re2c/file/dpkg-dev) here even though
# docker-php-ext-install would otherwise install them on the fly, because
# this CDN connection is unreliable from our network and a second apk
# round-trip in a later RUN keeps failing with TLS errors. One fetch =
# one chance to fail.
RUN apk add --no-cache \
    nginx \
    supervisor \
    git \
    curl \
    netcat-openbsd \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    icu-dev \
    postgresql-dev \
    postgresql-client \
    nodejs \
    npm \
    openssl \
    autoconf \
    make \
    g++ \
    re2c \
    file \
    dpkg-dev \
    dpkg \
    pkgconfig

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl

# Build deps for the Redis extension are already installed above, so
# we don't need the apk virtual package + cleanup dance. Just build it.
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

RUN composer run-script post-autoload-dump 2>/dev/null || true
RUN npm run build && rm -rf node_modules

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# FPM pool sizing — overrides the base image's pm.max_children=5 default which
# starves under concurrent driver photo uploads. See docker/php-fpm-pool.conf
# header for the full rationale.
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-overrides.conf

RUN mkdir -p /var/log/supervisor /run/nginx

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
