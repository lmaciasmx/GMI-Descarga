FROM php:8.4-fpm-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       libcurl4-openssl-dev libicu-dev libjpeg62-turbo-dev libfreetype6-dev \
       libonig-dev libpng-dev libxml2-dev libzip-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl dom gd intl mbstring mysqli pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
