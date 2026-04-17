# Snipto Makefile

# Variables
-include config.mk
PROFILE_FLAG = $(if $(COMPOSE_PROFILES),--profile $(COMPOSE_PROFILES),)
DC = docker compose $(PROFILE_FLAG)
EXEC = $(DC) exec app
ARTISAN = $(EXEC) php artisan

# Targets
.PHONY: up down build restart artisan composer npm shell test grumphp fix logs fresh

up:
	$(DC) up -d

down:
	$(DC) down

build:
	$(DC) build

restart:
	$(DC) down
	$(DC) up -d

# Usage: make artisan cmd="migrate" or make artisan migrate
artisan:
	$(ARTISAN) $(if $(cmd),$(cmd),$(filter-out $@,$(MAKECMDGOALS)))

composer:
	$(EXEC) composer $(if $(cmd),$(cmd),$(filter-out $@,$(MAKECMDGOALS)))

npm:
	$(EXEC) npm $(if $(cmd),$(cmd),$(filter-out $@,$(MAKECMDGOALS)))

shell:
	$(EXEC) bash

test:
	$(ARTISAN) test

grumphp:
	$(EXEC) ./vendor/bin/grumphp run

fix:
	$(EXEC) ./vendor/bin/pint

logs:
	$(DC) logs -f app

fresh:
	$(ARTISAN) migrate:fresh --seed

# Prevent make from throwing error on artisan arguments
%:
	@:
