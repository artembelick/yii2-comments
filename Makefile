# Всё запускается в контейнерах: на хосте нужны только Docker и make.
#
# Dev-стенд:      make up / make down / make migrate
# Проверки:       make check = lint → unit → админ для smoke → smoke → regression → e2e, первый красный шаг останавливает цепочку.
#                 Интеграционные наборы идут на отдельном тестовом стенде (compose-проект comments-test),
#                 который make check поднимает и после прогона сносит.
# Повтор порядка: SEED=<число> make test-unit (seed печатают Codeception и Vitest в начале прогона).
# Шарды CI:       SHARD=<i>/<n> make test-regression-parallel и т. п. — только для яруса parallel.

SHELL := /bin/bash
.SHELLFLAGS := -eu -o pipefail -c
.DEFAULT_GOAL := help

DEV := docker compose
STAND := docker compose -p comments-test --env-file .env.test -f docker-compose.yml -f docker-compose.test.yml

# Без стенда: линтеры и сборка.
PHP_RUN := $(STAND) run --rm --no-deps -T php
NODE_RUN := $(STAND) run --rm --no-deps -T node
# На поднятом стенде.
PHP_STAND := $(STAND) exec -T php
# php-fpm пишет от www-data, а /app — bind mount с владельцем с хоста. На Linux (CI) каталоги из git
# приходят с 755 и чужим владельцем, и AssetManager отвечает 500 «directory is not writable».
# Docker Desktop на macOS права в bind mount не проверяет, поэтому локально это не видно.
# Как yii init в шаблонах Yii: web/assets открыт на запись после подъёма стенда.
CHMOD_WEB_ASSETS := chmod 0777 frontend/web/assets backend/web/assets
# Проект Playwright без тестов — это сломанный testDir, и прогон падает. Пустой шард CI не в счёт:
# Playwright считает тесты до разбиения на шарды.
PLAYWRIGHT := $(STAND) run --rm -T playwright npx playwright test

CODECEPT_SEED := $(if $(SEED),--seed $(SEED),)
CODECEPT_SHARD := $(if $(SHARD),--shard $(SHARD),)
PLAYWRIGHT_SHARD := $(if $(SHARD),--shard=$(SHARD),)

.PHONY: help up down install migrate admin lint lint-php lint-js lint-docker lint-checks lint-php-files lint-js-files lint-js-types fix \
	stand-up stand-down stand-logs test-unit test-unit-php test-unit-js test-smoke test-regression \
	test-regression-pristine test-regression-parallel test-regression-exclusive \
	test-regression-ws-parallel test-regression-ws-exclusive test-e2e test-e2e-parallel test-e2e-exclusive \
	coverage check

help: ## Список целей
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-30s %s\n", $$1, $$2}'

.env:
	cp .env.example .env

# --- Dev-стенд ---------------------------------------------------------------

up: .env ## Поднять dev-стенд и применить миграции
	$(DEV) up -d --build --wait
	$(DEV) exec -T php $(CHMOD_WEB_ASSETS)
	$(DEV) exec -T php ./yii migrate --interactive=0

down: ## Остановить dev-стенд
	$(DEV) down

install: .env ## Поставить зависимости PHP и JS
	$(PHP_RUN) composer install --no-interaction --no-progress
	$(NODE_RUN) npm ci --no-audit --no-fund
	$(STAND) run --rm --no-deps -T playwright npm ci --no-audit --no-fund

migrate: ## Применить миграции на dev-стенде
	$(DEV) exec -T php ./yii migrate --interactive=0

admin: ## Создать админа для smoke на тестовом стенде (логин и пароль из .env.test). Пароль идёт через STDIN
	$(PHP_STAND) sh -c 'printf "%s\n" "$$SMOKE_ADMIN_PASSWORD" | ./yii admin/create "$$SMOKE_ADMIN_LOGIN"'

# --- Линтеры -----------------------------------------------------------------

lint: lint-php lint-js lint-docker lint-checks ## Все линтеры

lint-php:
	$(PHP_RUN) composer validate --strict
	$(PHP_RUN) vendor/bin/codecept build --quiet
	$(PHP_RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	$(PHP_RUN) vendor/bin/php-cs-fixer check --diff
	$(PHP_RUN) vendor/bin/rector process --dry-run --no-progress-bar
	$(PHP_RUN) vendor/bin/phpcs

lint-js: lint-js-types
	$(NODE_RUN) npm run --silent lint:ox
	$(NODE_RUN) npm run --silent lint:fmt

lint-js-types:
	$(NODE_RUN) npm run --silent lint:types

lint-docker:
	$(STAND) run --rm --no-deps -T hadolint hadolint docker/php/Dockerfile

lint-checks:
	$(NODE_RUN) node tools/checks/bin.js

# Для lefthook pre-commit: линтеры только по изменённым файлам (FILES — список через пробел).
lint-php-files:
	$(PHP_RUN) vendor/bin/codecept build --quiet
	$(PHP_RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=1G $(FILES)
	$(PHP_RUN) vendor/bin/php-cs-fixer check --diff --path-mode=intersection $(FILES)
	$(PHP_RUN) vendor/bin/rector process --dry-run --no-progress-bar $(FILES)
	$(PHP_RUN) vendor/bin/phpcs $(FILES)

lint-js-files:
	$(NODE_RUN) npx oxlint --type-aware --deny-warnings $(FILES)
	$(NODE_RUN) npx oxfmt --check $(FILES)

fix: ## Автоисправление стиля
	$(PHP_RUN) vendor/bin/rector process --no-progress-bar
	$(PHP_RUN) vendor/bin/php-cs-fixer fix
	$(NODE_RUN) npm run --silent fix

# --- Тестовый стенд ------------------------------------------------------------

stand-up: ## Поднять тестовый стенд и применить миграции
	$(STAND) up -d --build --wait php ws nginx postgres redis
	$(PHP_STAND) $(CHMOD_WEB_ASSETS)
	$(PHP_STAND) ./yii migrate --interactive=0

stand-down: ## Снести тестовый стенд вместе с данными
	$(STAND) down -v --remove-orphans

stand-logs: ## Логи контейнеров тестового стенда (CI печатает их при падении)
	$(STAND) logs --no-color --timestamps php ws nginx

# --- Тесты -------------------------------------------------------------------

test-unit: test-unit-php test-unit-js ## Unit PHP и JS с порогом покрытия 100%

# Unit-тесты репозиториев ходят в тестовую Postgres, поэтому PHP-unit идёт на поднятом стенде.
test-unit-php: stand-up
	rm -rf tests/_output/coverage-php.xml tests/_output/junit-php.xml
	$(PHP_STAND) php -d pcov.enabled=1 vendor/bin/codecept run '*::unit' $(CODECEPT_SEED) \
		--xml junit-php.xml --coverage-xml coverage-php.xml --coverage-cobertura cobertura-php.xml
	$(NODE_RUN) node tools/checks/bin.js junit tests/_output/junit-php.xml
	$(NODE_RUN) node tools/checks/bin.js coverage tests/_output/coverage-php.xml --strip=/app/

test-unit-js:
	$(STAND) run --rm --no-deps -T $(if $(SEED),-e VITEST_SEED=$(SEED),) node npm run --silent test

test-smoke: ## Smoke (Playwright)
	$(PLAYWRIGHT) --project=smoke

test-regression: test-regression-pristine test-regression-parallel test-regression-exclusive \
	test-regression-ws-parallel test-regression-ws-exclusive ## REST-regression по ярусам, затем REG-WS

test-regression-pristine:
	$(PHP_STAND) vendor/bin/codecept run '*::regression' -g pristine $(CODECEPT_SEED) --xml junit-regression-pristine.xml
	$(NODE_RUN) node tools/checks/bin.js junit tests/_output/junit-regression-pristine.xml

test-regression-parallel:
	$(PHP_STAND) vendor/bin/codecept run '*::regression' -g parallel $(CODECEPT_SEED) $(CODECEPT_SHARD) --xml junit-regression-parallel.xml
	$(NODE_RUN) node tools/checks/bin.js junit tests/_output/junit-regression-parallel.xml

test-regression-exclusive:
	$(PHP_STAND) vendor/bin/codecept run '*::regression' -g exclusive $(CODECEPT_SEED) --xml junit-regression-exclusive.xml
	$(NODE_RUN) node tools/checks/bin.js junit tests/_output/junit-regression-exclusive.xml

test-regression-ws-parallel:
	$(PLAYWRIGHT) --project=regression-ws-parallel $(PLAYWRIGHT_SHARD)

# Единственное оставшееся послабление: ярус exclusive у REG-WS (WS-06, WS-07) заполняет срез 05, он же убирает флаг.
test-regression-ws-exclusive:
	$(PLAYWRIGHT) --project=regression-ws-exclusive --pass-with-no-tests

test-e2e: test-e2e-parallel test-e2e-exclusive ## E2E (Playwright): сначала parallel, затем exclusive

test-e2e-parallel:
	$(PLAYWRIGHT) --project=e2e-parallel $(PLAYWRIGHT_SHARD)

test-e2e-exclusive:
	$(PLAYWRIGHT) --project=e2e-exclusive

coverage: test-unit ## Покрытие: clover и cobertura PHP в tests/_output, lcov JS в tests/_output/coverage-js
	@echo "PHP: tests/_output/coverage-php.xml, tests/_output/cobertura-php.xml"
	@echo "JS:  tests/_output/coverage-js/lcov.info"

check: ## lint → unit → админ для smoke → smoke → regression → e2e на свежем тестовом стенде, после прогона стенд сносится
	$(MAKE) lint
	$(MAKE) stand-down
	trap '$(MAKE) stand-down' EXIT; \
		$(MAKE) test-unit && $(MAKE) admin && $(MAKE) test-smoke && $(MAKE) test-regression && $(MAKE) test-e2e
