.PHONY: up down db-init migrate seed seed-demo fresh logs test verify bash

up:
	SEED_DEMO_DATA=true RUN_MIGRATIONS=true docker compose up -d --build

down:
	docker compose down

db-init:
	docker compose exec app composer db:init

migrate:
	docker compose exec app php artisan migrate --force --no-interaction

seed:
	docker compose exec app php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force --no-interaction

seed-demo:
	docker compose exec app php artisan db:seed --class=Database\\Seeders\\SharedDemoDataSeeder --force --no-interaction

fresh:
	docker compose exec app php artisan migrate:fresh --seed --force --no-interaction

logs:
	docker compose logs -f app

test:
	docker compose exec app composer test

verify:
	docker compose exec app php artisan demo:verify

bash:
	docker compose exec app sh
