backend := docker exec parking_backend

db-migrate:
	$(backend) php artisan migrate

db-migrate-create:
	$(backend) php artisan make:migration $(name) $(args)

artisan-ide-helper:
	docker exec -it parking_backend composer require --dev barryvdh/laravel-ide-helper

composer-du:
	$(backend) /bin/bash -c "composer dump-autoload --quiet --optimize --classmap-authoritative $(args)"

