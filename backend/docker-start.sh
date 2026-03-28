#!/usr/bin/env sh
set -e

if [ ! -f vendor/autoload.php ]; then
	composer install --no-interaction --no-progress
fi

if [ ! -d node_modules ]; then
	npm install
fi
php artisan migrate --force;

php artisan serve --host=0.0.0.0 --port=8000
