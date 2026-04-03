#!/usr/bin/env sh
set -e

#Todo check if needed.
#needs_composer_install=0
if [ ! -f vendor/autoload.php ]; then
#	needs_composer_install=1
#else
#	php -r "require 'vendor/autoload.php'; exit(class_exists('Laravel\\Reverb\\ReverbServiceProvider') ? 0 : 1);" || needs_composer_install=1
#fi
#
#if [ "$needs_composer_install" -eq 1 ]; then
	composer install --no-interaction --no-progress
fi

if [ ! -d node_modules ]; then
	npm install
fi
php artisan migrate --force;
php artisan db:seed --force;

php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction &

# Start cron so the Laravel scheduler (configured in /etc/cron.d/laravel)
service cron start

# Process queued jobs using the database queue connection.
php artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=0 &

php artisan serve --host=0.0.0.0 --port=8000
