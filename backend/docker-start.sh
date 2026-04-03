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

php artisan serve --host=0.0.0.0 --port=8000
