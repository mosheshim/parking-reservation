backend := docker exec parking_backend
frontend := docker exec parking_frontend

db-migrate:
	$(backend) php artisan migrate

db-migrate-revert:
	$(backend) php artisan migrate:rollback --step=1

db-migrate-create:
	$(backend) php artisan make:migration $(name) $(args)

artisan-ide-helper:
	$(backend) /bin/bash -c "composer require --dev barryvdh/laravel-ide-helper"

composer-du:
	$(backend) /bin/bash -c "composer dump-autoload --quiet --optimize --classmap-authoritative $(args)"

composer-install:
	$(backend) /bin/bash -c "composer install"

frontend-npm-install:
	$(frontend) npm install

optimize-clear-all:
	$(backend) php artisan optimize:clear
