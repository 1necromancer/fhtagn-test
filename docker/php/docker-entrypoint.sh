#!/bin/sh
set -e
cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo "vendor/autoload.php not found; running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

exec docker-php-entrypoint "$@"
