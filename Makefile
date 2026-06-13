# Servana by Citrus — developer task runner (Plan §26.1, CLAUDE.md §7).
# All targets run against the Docker dev stack. On Windows, run from a
# bash-capable shell (Git Bash / WSL) or ensure GNU make + Docker are on PATH.

COMPOSE = docker compose
APP     = $(COMPOSE) exec app

.DEFAULT_GOAL := help
.PHONY: help env up down restart logs ps shell composer npm fresh test lint stan build clamav-up

help:
	@echo "Servana - make targets"
	@echo "  make env        Create .env from .env.example if missing"
	@echo "  make up         Build images and start the dev stack (detached)"
	@echo "  make down       Stop and remove the stack"
	@echo "  make restart    Restart all services"
	@echo "  make logs       Tail logs for all services"
	@echo "  make ps         Show service status"
	@echo "  make shell      Open a bash shell in the app container"
	@echo "  make composer   Run composer in app (e.g. make composer ARGS=\"require x\")"
	@echo "  make npm        Run npm in a node container (e.g. make npm ARGS=\"run dev\")"
	@echo "  make fresh      migrate:fresh --seed against PostgreSQL 16"
	@echo "  make test       Quality gate: composer pint --test && composer stan && php artisan test --parallel"
	@echo "  make lint       Pint (check only)"
	@echo "  make stan       Larastan level 8"
	@echo "  make build      Build the SPA bundle into public/spa"
	@echo "  make clamav-up  Start the optional ClamAV service (profile: clamav)"

env:
	@if [ -f .env ]; then echo ".env already exists - leaving it untouched"; else cp .env.example .env && echo "created .env from .env.example"; fi

up:
	$(COMPOSE) up -d --build
	@echo "Stack up -> app http://localhost:8080 | Mailpit http://localhost:8025 | MinIO console http://localhost:9101"

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

logs:
	$(COMPOSE) logs -f

ps:
	$(COMPOSE) ps

shell:
	$(APP) bash

composer:
	$(APP) composer $(ARGS)

npm:
	$(COMPOSE) run --rm spa-builder npm $(ARGS)

fresh:
	$(APP) php artisan migrate:fresh --seed

test:
	$(APP) sh -lc "composer pint -- --test && composer stan && php artisan test --parallel"

lint:
	$(APP) composer pint -- --test

stan:
	$(APP) composer stan

build:
	$(COMPOSE) run --rm spa-builder sh -lc "npm ci && npm run build"

clamav-up:
	$(COMPOSE) --profile clamav up -d clamav
