# syntax=docker/dockerfile:1

FROM composer:2.9.8 AS composer

# base: расширения, которые нужны приложению и ws-серверу в любом окружении.
FROM php:8.3.37-fpm-bookworm AS base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

# dev: то же плюс Xdebug для отладки и pcov для покрытия в unit-тестах.
FROM base AS dev

RUN pecl install xdebug-3.5.3 pcov-1.0.12 \
    && docker-php-ext-enable xdebug pcov \
    && pecl clear-cache

COPY conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
