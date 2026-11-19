.DEFAULT_GOAL := help
.PHONY: help install migrate demo test test-unit test-integration test-mysql worker reconcile lint clean

PHP ?= php

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

install: ## Install dev dependencies (PHPUnit). The engine itself needs none.
	composer install

migrate: ## Apply pending migrations
	$(PHP) bin/migrate.php

demo: ## Run every demonstration scenario
	$(PHP) bin/demo.php

test: ## Run the full suite
	$(PHP) vendor/bin/phpunit

test-unit: ## Domain rules and pure helpers; no I/O
	$(PHP) vendor/bin/phpunit --testsuite unit

test-integration: ## Real SQL, real transactions, against SQLite
	$(PHP) vendor/bin/phpunit --testsuite integration

test-mysql: ## The same suite against MySQL, where SKIP LOCKED is real
	docker compose up -d --wait
	DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_NAME=relay DB_USER=relay DB_PASSWORD=relay \
		$(PHP) vendor/bin/phpunit

worker: ## Run a worker in a loop (start several; the claim is atomic)
	$(PHP) bin/worker.php --interval=5

reconcile: ## Sweep dead letters against the system of record
	$(PHP) bin/reconcile.php

lint: ## Check syntax across the whole codebase
	@find src bin tests -name '*.php' -print0 \
		| xargs -0 -n1 $(PHP) -l \
		| grep -v 'No syntax errors' || echo "No syntax errors detected."

clean: ## Remove generated databases and caches
	rm -rf var .phpunit.cache .phpunit.result.cache
