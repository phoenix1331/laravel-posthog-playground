.DEFAULT_GOAL := help

EXEC = docker compose exec app

.PHONY: help up down build restart shell migrate seed fresh test test-js logs queue-logs queue-restart scheduler-logs schedule-list simulate simulate-unstitched sync-groups assets vite

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers (app on APP_PORT, default 8000)
	@docker compose up -d

down: ## Stop all containers
	@docker compose down

build: ## Build the app image
	@docker compose build

restart: ## Restart all containers
	@docker compose restart

shell: ## Open a shell in the app container
	@$(EXEC) bash

migrate: ## Run database migrations
	@$(EXEC) php artisan migrate

seed: ## Seed the database
	@$(EXEC) php artisan db:seed

fresh: ## Drop all tables, re-run migrations and seed
	@$(EXEC) php artisan migrate:fresh --seed

test: ## Run the test suite
	@$(EXEC) php artisan test

test-js: ## Run JavaScript unit tests with node --test (runs on the host)
	@npm test

logs: ## Tail app container logs
	@docker compose logs -f app

queue-logs: ## Tail queue worker logs
	@docker compose logs -f queue

queue-restart: ## Restart the queue worker and scheduler to pick up code changes
	@docker compose restart queue scheduler

scheduler-logs: ## Tail scheduler logs
	@docker compose logs -f scheduler

schedule-list: ## List scheduled tasks
	@$(EXEC) php artisan schedule:list

USERS ?= 500
DAYS ?= 30

simulate: ## Generate backdated PostHog traffic (USERS=500 DAYS=30, e.g. make simulate USERS=50)
	@$(EXEC) php artisan matchday:simulate --users=$(USERS) --days=$(DAYS)

simulate-unstitched: ## Same traffic without identity stitching, to show the broken funnel
	@$(EXEC) php artisan matchday:simulate --users=$(USERS) --days=$(DAYS) --no-stitching

sync-groups: ## Send every supporters club to PostHog as a group
	@$(EXEC) php artisan matchday:sync-groups

assets: ## Build front-end assets with Vite (runs on the host)
	@npm run build

vite: ## Start the Vite dev server with hot reload (runs on the host)
	@npm run dev
