 # Use a single variable for Docker Compose invocation.
 # On Windows (including Git Bash / MSYS / MINGW / Cygwin), `docker-compose` is typically available and more reliable.
 # On macOS/Linux, prefer the modern Docker CLI plugin form: `docker compose`.
UNAME := $(shell uname -s 2>/dev/null || echo Unknown)

ifeq ($(OS),Windows_NT)
compose := docker-compose
else ifneq (,$(filter MINGW% MSYS% CYGWIN%,$(UNAME)))
compose := docker-compose
else
compose := docker compose
endif

backend := $(compose) exec backend
frontend := $(compose) exec frontend

docker-init:
	[ -f backend/.env ] || cp backend/.env.example backend/.env
	[ -f frontend/.env ] || cp frontend/.env.example frontend/.env
	[ -f db/.env ] || cp db/.env.example db/.env
	$(compose) up -d

docker-up:
	$(compose) up -d

docker-down:
	$(compose) down

docker-rebuild:
	$(compose) up -d --build

db-migrate:
	$(backend) php artisan migrate

db-migrate-revert:
	$(backend) php artisan migrate:rollback --step=1

db-migrate-create:
	$(backend) php artisan make:migration $(name) $(args)

db-seeders:
	$(backend) php artisan db:seed --force

artisan-ide-helper:
	$(backend) /bin/bash -c "composer require --dev barryvdh/laravel-ide-helper"

artisan-command:
	$(backend) php artisan $(args)

artisan-reverb-start:
	$(backend) /bin/bash -c "php artisan reverb:start --host=0.0.0.0 --port=8080 --debug"

composer-du:
	$(backend) /bin/bash -c "composer dump-autoload --quiet --optimize --classmap-authoritative $(args)"

composer-install:
	$(backend) /bin/bash -c "composer install"

frontend-npm-install:
	$(frontend) npm install

tests:
	$(backend) php artisan test

# Optimization
optimize-clear-all:
	$(backend) php artisan optimize:clear