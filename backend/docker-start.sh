#!/usr/bin/env sh
set -e

composer install --no-interaction --no-progress
npm install
php artisan migrate --force;

php artisan serve --host=0.0.0.0 --port=8000
