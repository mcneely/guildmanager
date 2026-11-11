.DEFAULT_GOAL := help
SHELL := /bin/bash
CMD_DOCKER := docker
# Prefer the docker-compose v1 binary; fall back to the `docker compose` plugin.
CMD_DOCKER_COMPOSE := $(shell if command -v docker-compose >/dev/null 2>&1; then echo docker-compose; else echo $(CMD_DOCKER) compose; fi)
CMD_DOCKER_EXEC := $(CMD_DOCKER) exec guildManager

help: ## Show this help
	@grep -E '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \\033[36m%-16s\\033[0m %s\\n", $$1, $$2}'

start: ## Start the stack
	$(CMD_DOCKER_COMPOSE) build
	$(CMD_DOCKER_COMPOSE) up -d

stop: ## Stop the stack (keeps the guildmanager-db volume)
	$(CMD_DOCKER_COMPOSE) down

restart: stop start ## Restart the stack

clean: ## Remove temporary files
	$(CMD_DOCKER_EXEC) rm -rf var/coverage/* var/mutation/* var/temp/*

test: ## Run the PHPUnit suite
	$(CMD_DOCKER_EXEC) vendor/bin/phpunit --do-not-record-test-run-history --no-coverage

coverage: ## Run PHPUnit with coverage (requires Xdebug or pcov)
	$(CMD_DOCKER_EXEC) mkdir -p var/coverage
	$(CMD_DOCKER) exec -e XDEBUG_MODE=coverage guildManager vendor/bin/phpunit --do-not-record-test-run-history

mutation: ## Run Mutation testing
	$(CMD_DOCKER_EXEC) mkdir -p var/mutation
	$(CMD_DOCKER_EXEC) vendor/bin/infection --show-mutations=max --coverage=var/coverage

static: ## Run static analysis
	$(CMD_DOCKER_EXEC) vendor/bin/phpstan analyze -l max

fixer: ## Run PHP code fixer
	$(CMD_DOCKER_EXEC) vendor/bin/php-cs-fixer fix

rector: ## Rector via Docker
	$(CMD_DOCKER_EXEC) vendor/bin/rector process

rector-dry: ## Dry-run Rector via Docker
	$(CMD_DOCKER_EXEC) vendor/bin/rector process --dry-run

composer-install:
	$(CMD_DOCKER_EXEC) composer install

composer-update:
	$(CMD_DOCKER_EXEC) composer update

agent-test: fixer static coverage mutation

.PHONY: help start stop restart test coverage mutation static fixer clean rector rector-dry composer-install composer-update