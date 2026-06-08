# Snipto Makefile

# Variables
-include config.mk
PROFILE_FLAG = $(if $(COMPOSE_PROFILES),--profile $(COMPOSE_PROFILES),)

# Deployment mode: development (default) or production
MODE ?= development

ifeq ($(MODE),production)
    DC = docker compose -f docker-compose.prod.yml $(PROFILE_FLAG)
    EXEC = $(DC) exec app
else
    DC = docker compose $(PROFILE_FLAG)
    EXEC = $(DC) exec app
endif

ARTISAN = $(EXEC) php artisan

# Targets
.PHONY: up down build restart prod-up prod-down prod-build prod-deploy crowdsec-setup artisan composer npm shell test grumphp fix lint logs fresh

# =============================================================================
# Development targets (default mode)
# =============================================================================
up:
	$(DC) up -d

down:
	$(DC) down

build:
	$(DC) build

restart:
	$(DC) down
	$(DC) up -d

# =============================================================================
# Production targets (explicitly use prod compose file)
# =============================================================================
prod-up:
	docker compose -f docker-compose.prod.yml $(PROFILE_FLAG) up -d

prod-down:
	docker compose -f docker-compose.prod.yml $(PROFILE_FLAG) down

prod-build:
	docker compose -f docker-compose.prod.yml $(PROFILE_FLAG) build

# =============================================================================
# CrowdSec initial setup — one-time machine registration and API key generation
# Run this once after first deployment to register the agent with LAPI
# =============================================================================
crowdsec-setup:
	@./docker/crowdsec-setup.sh

# =============================================================================
# Production deployment — generates fresh Cloudflare IP config then starts containers
# =============================================================================
prod-deploy:
	@echo "Checking CrowdSec bouncer configuration..."
	@if [ ! -f docker/crowdsec/crowdsec-openresty-bouncer.conf ]; then \
		echo "Missing CrowdSec bouncer config. Starting crowdsec to generate it..."; \
		docker compose -f docker-compose.prod.yml $(PROFILE_FLAG) up -d crowdsec; \
		sleep 3; \
		./docker/crowdsec-setup.sh; \
	fi
	@echo "Generating fresh Cloudflare IP ranges..."
	@./docker/generate-cloudflare-nginx-config.sh
	@echo ""
	docker compose -f docker-compose.prod.yml $(PROFILE_FLAG) up -d

# =============================================================================
# Utility targets — accept MODE parameter for production context
# Usage: make artisan cmd="migrate" or make artisan cmd="migrate" MODE=production
# =============================================================================
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
	$(EXEC) ./vendor/bin/php-cs-fixer --config=.php-cs-fixer.php fix

lint:
	$(EXEC) ./vendor/bin/php-cs-fixer --config=.php-cs-fixer.php fix --dry-run --diff

logs:
	$(DC) logs -f app

fresh:
	$(ARTISAN) migrate:fresh --seed

# Prevent make from throwing error on artisan arguments
%:
	@:
