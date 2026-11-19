# Short wrappers around docker compose (host has no PHP).
export UID := $(shell id -u)
export GID := $(shell id -g)
RUN := docker compose run --rm app

.PHONY: up down build test test-unit test-feature lint fix migrate shell logs

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

test:
	$(RUN) php artisan test

test-unit:
	$(RUN) php artisan test --testsuite=Unit

test-feature:
	$(RUN) php artisan test --testsuite=Feature

lint:
	$(RUN) vendor/bin/pint --test

fix:
	$(RUN) vendor/bin/pint

migrate:
	$(RUN) php artisan migrate --force

shell:
	$(RUN) sh

logs:
	docker compose logs -f app worker
