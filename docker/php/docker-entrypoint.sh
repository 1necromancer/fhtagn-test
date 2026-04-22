#!/bin/sh
set -e
cd /var/www/html

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_MEMORY_LIMIT=-1

mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/logs \
    bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
    echo "vendor/autoload.php not found; running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Права для php-fpm (www-data): запись в storage и кэш конфигов
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Гарантируем foreground, иначе Docker может завершить контейнер
if [ "$#" -eq 0 ]; then
    set -- php-fpm -F
elif [ "$1" = "php-fpm" ] && [ "$2" != "-F" ] && [ "$2" != "--nodaemonize" ]; then
    set -- php-fpm -F
fi

exec /usr/local/bin/docker-php-entrypoint "$@"
