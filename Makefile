COMPOSE_PROJECT_NAME ?= itkcms
HOST_UID ?= $(shell id -u)
HOST_GID ?= $(shell id -g)
ITK_CI_UID ?= $(HOST_UID)
ITK_CI_GID ?= $(HOST_GID)
HTTP_PORT ?= 18020
DB_PORT ?= 18021
MAILPIT_PORT ?= 18022
ADMIN_PORT ?= 18023
export COMPOSE_PROJECT_NAME HOST_UID HOST_GID ITK_CI_UID ITK_CI_GID HTTP_PORT DB_PORT MAILPIT_PORT ADMIN_PORT

PHP = docker compose --project-directory packages/cms -f packages/cms/docker/compose.yaml -f packages/cms/tests/docker/compose.ci.yaml
# Use the admin project's existing node_modules volume even when COMPOSE_PROJECT_NAME is set.
NODE = docker compose -f admin/compose.yaml -p itk-cms-admin

.DEFAULT_GOAL := help
.PHONY: help up down build install sh logs test lint admin-build admin-dev audit

help: ## Показать список целей
	@grep -E '^[a-z][a-z-]*:.*## ' Makefile | awk -F ':.*## ' '{printf "  %-14s %s\n", $$1, $$2}'

up: ## Запустить PostgreSQL и Mailpit и дождаться их готовности
	$(PHP) up -d --wait database mailer

down: ## Остановить стенд и админку с сохранением томов
	$(PHP) down
	$(NODE) down

build: ## Собрать образ PHP и загрузить образ Node
	$(PHP) build --pull php
	$(NODE) pull node

install: ## Установить зависимости Composer и npm
	$(PHP) run --rm --no-deps php composer install
	$(NODE) run --rm node npm ci

sh: ## Открыть оболочку в контейнере PHP
	$(PHP) run --rm --no-deps php sh

logs: ## Показать последние 200 строк логов и следить за новыми
	$(PHP) logs -f --tail=200

test: up ## Запустить тесты PHP и админки
	$(PHP) run --rm --no-deps php composer test
	$(NODE) run --rm node npm test

lint: ## Проверить Composer, код PHP и код админки
	$(PHP) run --rm --no-deps php composer validate --strict
	$(PHP) run --rm --no-deps php composer stan
	$(PHP) run --rm --no-deps php composer cs
	$(NODE) run --rm node npm run lint
	$(NODE) run --rm node npm run check:i18n

admin-build: ## Собрать админку в packages/cms/public/admin
	$(NODE) run --rm node npm run build

admin-dev: ## Запустить Vite для разработки админки
	$(NODE) up node

audit: ## Проверить зависимости npm на известные уязвимости
	$(NODE) run --rm node npm audit --include=dev
	$(NODE) run --rm node npm audit --omit=dev --audit-level=high
