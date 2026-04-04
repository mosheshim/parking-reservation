#!/usr/bin/env sh
set -e

# Always make sure composer is installed on boot.
# This is to make the first run smoother so no need to run composer install manually.
composer install --no-interaction --no-progress

# Ensure APP_KEY is set before running framework commands that rely on encryption.
if [ -f .env ]; then
	APP_KEY_LINE=$(grep '^APP_KEY=' .env || true)
	APP_KEY_VALUE=$(printf '%s' "$APP_KEY_LINE" | cut -d= -f2-)
	if [ -z "$APP_KEY_VALUE" ]; then
		php artisan key:generate --force
	fi
fi

php artisan migrate --force;
php artisan db:seed --force;

php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction &

# Start cron so the Laravel scheduler (configured in /etc/cron.d/laravel)
service cron start

# Process queued jobs using the database queue connection.
php artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=0 &

php artisan serve --host=0.0.0.0 --port=8000
