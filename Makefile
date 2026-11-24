# Всё запускается в контейнерах: на хосте нужны только Docker и make.
#
# Dev-стенд:      make up / make down / make migrate
# Проверки:       make check = lint → unit → админ для smoke → smoke → regression → e2e, первый красный шаг останавливает цепочку.
#                 Интеграционные наборы идут на отдельном тестовом стенде (compose-проект comments-test),
#                 который make check поднимает и после прогона сносит.
# Повтор порядка: SEED=<число> make test-unit (seed печатают Codeception и Vitest в начале прогона).
# Повтор падения: TEST_RUN_ID=<id> SEED=<число> make check — id прогона и seed из отчёта, на свежем стенде.
# Повтор на стенде: make test-rerun — тесты стенда дважды на одном стенде (ИЗ-1).
# Шарды CI:       SHARD=<i>/<n> make test-regression-parallel и т. п. — только для яруса parallel.

SHELL := /bin/bash
.SHELLFLAGS := -eu -o pipefail -c
.DEFAULT_GOAL := help

DEV := docker compose
STAND := docker compose -p comments-test --env-file .env.test -f docker-compose.yml -f docker-compose.test.yml

# Id прогона (ИЗ-1, ДТ-2): начало каждой метки тестовых данных. Один на весь прогон, вложенные make получают его
# через окружение. Без него повторный прогон на том же стенде повторил бы метки прошлого.
RANDOM_RUN_ID := od -An -N4 -tu1 /dev/urandom | awk '{ for (i = 1; i <= NF; i++) printf "%s", substr("abcdefghijklmnopqrstuvwxyz0123456789", $$i % 36 + 1, 1) }'
ifndef TEST_RUN_ID
TEST_RUN_ID := $(shell $(RANDOM_RUN_ID))
ifneq ($(filter check test-%,$(MAKECMDGOALS)),)
$(info [make] TEST_RUN_ID=$(TEST_RUN_ID). Повторить на свежем стенде: TEST_RUN_ID=$(TEST_RUN_ID) SEED=<seed из отчёта> make $(MAKECMDGOALS))
endif
endif
export TEST_RUN_ID

# Без стенда: линтеры и сборка.
PHP_RUN := $(STAND) run --rm --no-deps -T php
NODE_RUN := $(STAND) run --rm --no-deps -T node
# На поднятом стенде.
PHP_STAND := $(STAND) exec -T -e TEST_RUN_ID=$(TEST_RUN_ID) php
PSQL_STAND := $(STAND) exec -T postgres sh -c
# php-fpm пишет от www-data, а /app — bind mount с владельцем с хоста. На Linux (CI) каталоги из git
# приходят с 755 и чужим владельцем, и AssetManager отвечает 500 «directory is not writable».
# Docker Desktop на macOS права в bind mount не проверяет, поэтому локально это не видно.
# Как yii init в шаблонах Yii: web/assets открыт на запись после подъёма стенда.
CHMOD_WEB_ASSETS := chmod 0777 frontend/web/assets backend/web/assets
# Проект Playwright без тестов — это сломанный testDir, и прогон падает. Пустой шард CI не в счёт:
# Playwright считает тесты до разбиения на шарды.
PLAYWRIGHT := $(STAND) run --rm -T -e TEST_RUN_ID=$(TEST_RUN_ID) playwright npx playwright test

CODECEPT_SEED := $(if $(SEED),--seed $(SEED),)
CODECEPT_SHARD := $(if $(SHARD),--shard $(SHARD),)
PLAYWRIGHT_SHARD := $(if $(SHARD),--shard=$(SHARD),)

.PHONY: help up down install migrate admin lint lint-php lint-js lint-docker lint-checks lint-php-files lint-js-files lint-js-types fix \
	stand-up stand-down stand-logs test-unit test-unit-php test-unit-js test-smoke test-regression \
	test-regression-pristine test-regression-parallel test-regression-exclusive \
	test-regression-ws-parallel test-regression-ws-exclusive test-e2e test-e2e-parallel test-e2e-exclusive \
	coverage check test-rerun

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

# Идемпотентна: если админ уже есть, ничего не делает и пароль не меняет (smoke работает и на стенде, где данные трогать нельзя).
admin: ## Создать админа для smoke на тестовом стенде (логин и пароль из .env.test), если его ещё нет. Пароль идёт через STDIN
	@exists=$$($(PSQL_STAND) 'echo "SELECT count(*) FROM \"user\" WHERE username = :'\''login'\''" \
		| psql -tAq -v ON_ERROR_STOP=1 -v login="$$SMOKE_ADMIN_LOGIN" -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'); \
	if [ "$$exists" = "1" ]; then \
		echo "Админ для smoke уже есть: ничего не меняю"; \
	else \
		$(PHP_STAND) sh -c 'printf "%s\n" "$$SMOKE_ADMIN_PASSWORD" | ./yii admin/create "$$SMOKE_ADMIN_LOGIN"'; \
	fi

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

# Ярусу нужна пустая БД, поэтому он сам пересоздаёт БД стенда: так он проходит и на стенде после прошлых прогонов.
# Исключение из ИЗ-1 (docs/test-plans/rules.md): повторный проход make test-rerun его не включает.
test-regression-pristine:
	$(PSQL_STAND) 'psql -q -v ON_ERROR_STOP=1 -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"'
	$(PHP_STAND) ./yii migrate --interactive=0
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

test-regression-ws-exclusive:
	$(PLAYWRIGHT) --project=regression-ws-exclusive

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

# ИЗ-1: повторный прогон на том же стенде. Первый проход — как в make check без lint и unit, второй — всё, кроме pristine,
# без stand-down, с другим TEST_RUN_ID и другим seed порядка.
test-rerun: ## smoke → regression → e2e дважды на одном стенде (второй раз без pristine), после прогона стенд сносится
	$(MAKE) stand-down
	trap '$(MAKE) stand-down' EXIT; \
		first_seed=$(if $(SEED),$(SEED),$$(od -An -N4 -tu4 /dev/urandom | awk 'NF { print $$1 % 2147483647; exit }')); \
		second_seed=$$(( first_seed % 2147483646 + 1 )); \
		second_run_id=$(TEST_RUN_ID); \
		while [ "$$second_run_id" = "$(TEST_RUN_ID)" ]; do second_run_id=$$($(RANDOM_RUN_ID)); done; \
		echo "[test-rerun] проход 1: TEST_RUN_ID=$(TEST_RUN_ID) SEED=$$first_seed"; \
		$(MAKE) stand-up && $(MAKE) admin && $(MAKE) test-smoke && $(MAKE) test-regression SEED=$$first_seed && $(MAKE) test-e2e && \
		echo "[test-rerun] проход 2 на том же стенде: TEST_RUN_ID=$$second_run_id SEED=$$second_seed" && \
		$(MAKE) admin TEST_RUN_ID=$$second_run_id && $(MAKE) test-smoke TEST_RUN_ID=$$second_run_id && \
		$(MAKE) test-regression-parallel test-regression-exclusive test-regression-ws-parallel test-regression-ws-exclusive \
			TEST_RUN_ID=$$second_run_id SEED=$$second_seed && \
		$(MAKE) test-e2e TEST_RUN_ID=$$second_run_id
