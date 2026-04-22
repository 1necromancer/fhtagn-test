FROM php:8.3-fpm-alpine

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl opcache pdo_mysql \
    && apk del .build-deps

RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-app-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-app-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-app-entrypoint.sh"]
CMD ["php-fpm"]
