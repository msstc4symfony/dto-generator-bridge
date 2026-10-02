TOOLS := tools/vendor/bin
# ~/PhpstormProjects is mounted: vendor/msstc4php/dto-generator links to ../../msstc4php/dto-generator.
PHP74 := docker run --rm --user "$$(id -u):$$(id -g)" -v "$(CURDIR)/../..:/work" -w /work/msstc4symfony/$(notdir $(CURDIR)) php:7.4-cli

install: ## Install package and tool dependencies
	composer install
	composer install --working-dir=tools

check: ## Static checks (incl. PHP 7.4 syntax lint)
	$(TOOLS)/phpstan analyse --memory-limit=512M -c phpstan.dist.neon
	$(TOOLS)/php-cs-fixer check
	composer validate --strict --no-check-publish
	$(TOOLS)/rector process -n
	$(TOOLS)/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered
	$(MAKE) lint-74

lint-74: ## Lint sources with the PHP 7.4 parser
	$(PHP74) sh -c "find src tests -name '*.php' -print0 | xargs -0 -r -n1 php -l > /dev/null"

test: ## Run tests on the local PHP
	vendor/bin/phpunit

test-74: ## Run tests on PHP 7.4
	$(PHP74) vendor/bin/phpunit

verify: check test test-74 ## Full gate: static checks + tests on local PHP and PHP 7.4

infection: ## Mutation testing
	XDEBUG_MODE=coverage $(TOOLS)/infection --threads=$(shell nproc) --no-interaction

fix: ## Apply code style and Rector
	$(TOOLS)/rector process
	$(TOOLS)/php-cs-fixer fix

help: ## List commands
	@grep -E '^[a-zA-Z_0-9-]+:.*?## ' Makefile | awk 'BEGIN {FS = ":.*?## "}; {printf "%-12s %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
.PHONY: install check lint-74 test test-74 verify infection fix help
