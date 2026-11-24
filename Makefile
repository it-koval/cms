COMPOSE_PROJECT_NAME ?= itkcms
ifndef HOST_UID
HOST_UID := $(shell id -u)
endif
ifndef HOST_GID
HOST_GID := $(shell id -g)
endif
ITK_CI_UID ?= $(HOST_UID)
ITK_CI_GID ?= $(HOST_GID)
HTTP_PORT ?= 18020
DB_PORT ?= 18021
MAILPIT_PORT ?= 18022
ADMIN_PORT ?= 18023
SEEDS ?= 30301 30302
DEPENDENCIES ?= stable
SITE ?= .ci-site
export COMPOSE_PROJECT_NAME HOST_UID HOST_GID ITK_CI_UID ITK_CI_GID HTTP_PORT DB_PORT MAILPIT_PORT ADMIN_PORT

PHP = docker compose --project-directory packages/cms -f packages/cms/docker/compose.yaml -f packages/cms/tests/docker/compose.ci.yaml
# Use the admin project's existing node_modules volume even when COMPOSE_PROJECT_NAME is set.
NODE = docker compose -f admin/compose.yaml -p itk-cms-admin

.DEFAULT_GOAL := help
.PHONY: help up down build build-php install admin-install sh logs
.PHONY: test test-php test-random test-admin schema validate lint lint-php lint-admin
.PHONY: admin-build admin-build-check admin-dev admin-mock audit audit-prod audit-report
.PHONY: ci-deps test-max smoke-dev no-node site-clean smoke-prod

help: ## Показать список целей
	@grep -E '^[a-z][a-z-]*:.*## ' Makefile | awk -F ':.*## ' '{printf "  %-14s %s\n", $$1, $$2}'

up: ## Запустить PostgreSQL и Mailpit и дождаться их готовности
	$(PHP) up -d --wait database mailer

down: ## Остановить стенд и админку с сохранением томов
	$(PHP) down --remove-orphans
	$(NODE) down --remove-orphans

build: build-php ## Собрать образ PHP и загрузить образ Node
	$(NODE) pull node

build-php: ## Собрать образ PHP с обновлением базовых образов
	$(PHP) build --pull php

install: ## Установить зависимости Composer и npm
	$(PHP) run --rm --no-deps php composer install
	$(MAKE) admin-install

admin-install: ## Установить зависимости админки из npm lock-файла
	$(NODE) run --rm --no-deps node npm ci

sh: ## Открыть оболочку в контейнере PHP
	$(PHP) run --rm --no-deps php sh

logs: ## Показать последние 200 строк логов и следить за новыми
	$(PHP) logs -f --tail=200

test: test-php test-admin ## Запустить тесты PHP и админки

test-php: up ## Запустить тесты PHP
	$(PHP) run --rm --no-deps php composer test

test-random: up ## Запустить тесты PHP в случайном порядке с заданными SEEDS
	set -e; for seed in $(SEEDS); do \
		$(PHP) run --rm --no-deps php composer test:prepare; \
		$(PHP) run --rm --no-deps php composer test:run -- --order-by=random --random-order-seed="$$seed"; \
	done

test-admin: ## Запустить тесты админки
	$(NODE) run --rm --no-deps node npm test

schema: ## Сверить схему подготовленной тестовой базы с маппингом Doctrine
	$(PHP) run --rm --no-deps php php tests/bin/console doctrine:schema:validate --env=test

validate: ## Проверить метаданные Composer и SQL
	$(PHP) run --rm --no-deps php composer validate --strict
	$(PHP) run --rm --no-deps php composer lint:sql

lint: validate lint-php lint-admin ## Проверить Composer, SQL, код PHP и код админки

lint-php: ## Запустить PHPStan и проверить стиль PHP
	$(PHP) run --rm --no-deps php composer stan
	$(PHP) run --rm --no-deps php composer cs

lint-admin: ## Проверить код и каталоги переводов админки
	$(NODE) run --rm --no-deps node npm run lint
	$(NODE) run --rm --no-deps node npm run check:i18n

admin-build: ## Собрать админку в packages/cms/public/admin
	$(NODE) run --rm node npm run build

admin-build-check: admin-build ## Сверить сборку админки с файлами в Git
	git add --intent-to-add packages/cms/public/admin
	git diff --exit-code packages/cms/public/admin

admin-dev: ## Запустить Vite для разработки админки
	$(NODE) up node

admin-mock: ## Запустить админку на выдуманных данных без PHP
	$(NODE) run --rm --service-ports node npm run dev:mock -- --host 0.0.0.0

audit: audit-prod ## Проверить зависимости npm на известные уязвимости
	-$(NODE) run --rm --no-deps node npm audit --include=dev

audit-prod: ## Проверить рабочие зависимости npm на уязвимости уровня high и выше
	$(NODE) run --rm --no-deps node npm audit --omit=dev --audit-level=high

audit-report: ## Сохранить полный отчёт npm audit в admin/npm-audit.json
	$(NODE) run -T --rm --no-deps node npm audit --include=dev --json > admin/npm-audit.json

ci-deps: ## Установить совместимые зависимости Composer для матрицы CI
	@if [ -z "$$CI" ]; then \
		printf '%s\n' 'Цель только для CI: она меняет composer.json и vendor. Запустите в отдельной копии репозитория с CI=1.' >&2; \
		exit 1; \
	fi
	$(PHP) run --rm --no-deps php sh tests/docker/ci-deps.sh "$(DEPENDENCIES)"

test-max: ## Проверить опциональный бандл MAX в CI
	@if [ -z "$$CI" ]; then \
		printf '%s\n' 'Цель только для CI: она меняет composer.json и vendor. Запустите в отдельной копии репозитория с CI=1.' >&2; \
		exit 1; \
	fi
	$(PHP) run --rm --no-deps php composer require --dev --no-progress --no-interaction yuri-kovalev/max-bot-symfony-bundle
	$(PHP) run --rm --no-deps php composer test:prepare
	$(PHP) run --rm --no-deps -e ITK_TEST_MAX_BUNDLE=1 php php vendor/bin/phpunit --fail-on-skipped --filter MaxOptionalBundleTest

smoke-dev: ## Собрать и проверить образ разработки с Node и Chromium
	$(PHP) build php
	$(PHP) run --rm --no-deps php bash tests/docker/smoke.sh
	$(PHP) run --rm --no-deps -e SKIP_ENTRYPOINT=1 php php-fpm -t

no-node: ## Собрать образ разработки и проверить, что в нём нет Node
	$(PHP) build php
	$(PHP) run --rm --no-deps php sh -ec '! command -v node'

site-clean: ## Подготовить чистый сайт Symfony из снимка пакета в Git
	@if [ -d "$(SITE)" ] && { ! contents=$$(ls -Ab "$(SITE)/.") || [ -n "$$contents" ]; }; then \
		printf 'Каталог %s уже есть. Удалите его и запустите снова.\n' "$(SITE)" >&2; \
		exit 1; \
	fi
	$(PHP) run --rm --no-deps php composer create-project 'symfony/skeleton:7.4.*' "/workspace/$(SITE)" --no-interaction --no-progress
	git archive --prefix=source/ HEAD:packages/cms | tar -xf - -C "$(SITE)"
	$(PHP) run --rm --no-deps -e DEPLOY_PORT="$(DEPLOY_PORT)" -w "/workspace/$(SITE)" php bash /workspace/packages/cms/tests/docker/prepare-site.sh

smoke-prod: ## Собрать и проверить production-комплект на подготовленном сайте
	bash packages/cms/tests/docker/prod-smoke.sh "$(SITE)"
