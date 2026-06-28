# =====================================================================
# Makefile — alvos finos que encapsulam Docker. NADA roda no host além
# do make. Único pré-requisito local: Docker + make.
# =====================================================================
.DEFAULT_GOAL := help
DC := docker compose
EXEC := $(DC) exec app
EXEC_T := $(DC) exec -T app

.PHONY: help setup bootstrap up down build rebuild restart ps logs logs-app \
        logs-worker shell worker-shell test migrate fresh seed key artisan \
        composer pest pint pint-test tinker stop

help: ## Lista os alvos disponíveis
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

setup: bootstrap ## Alias de bootstrap (primeira instalação)

bootstrap: ## Primeira instalação: cria esqueleto Laravel via contêiner e sobe tudo
	@bash scripts/bootstrap.sh

up: ## Sobe os 3 contêineres (app, worker, postgres)
	$(DC) up -d

down: ## Derruba os contêineres (mantém o volume do Postgres)
	$(DC) down

stop: ## Para os contêineres sem removê-los
	$(DC) stop

build: ## Builda a imagem da aplicação
	$(DC) build

rebuild: ## Rebuild sem cache
	$(DC) build --no-cache

restart: ## Reinicia os contêineres
	$(DC) restart

ps: ## Mostra o status dos contêineres
	$(DC) ps

logs: ## Logs de todos os serviços (segue)
	$(DC) logs -f --tail=100

logs-app: ## Logs do app
	$(DC) logs -f --tail=100 app

logs-worker: ## Logs do worker
	$(DC) logs -f --tail=100 worker

shell: ## Abre bash no contêiner app
	$(EXEC) bash

worker-shell: ## Abre bash no contêiner worker
	$(DC) exec worker bash

test: ## Roda a suíte de testes (TDD) dentro do contêiner
	$(EXEC_T) php artisan test

migrate: ## Roda as migrations
	$(EXEC_T) php artisan migrate

fresh: ## Recria o banco do zero (migrate:fresh)
	$(EXEC_T) php artisan migrate:fresh

seed: ## Roda os seeders
	$(EXEC_T) php artisan db:seed

key: ## Gera a APP_KEY
	$(EXEC_T) php artisan key:generate

tinker: ## Abre o tinker
	$(EXEC) php artisan tinker

pest: ## Roda o Pest diretamente
	$(EXEC_T) ./vendor/bin/pest

pint: ## Formata o código (Laravel Pint)
	$(EXEC_T) ./vendor/bin/pint

pint-test: ## Checa o estilo sem alterar arquivos (CI)
	$(EXEC_T) ./vendor/bin/pint --test

# Uso: make artisan c="migrate:status"   |   make composer c="require pacote"
artisan: ## Executa um comando artisan (c="...")
	$(EXEC_T) php artisan $(c)

composer: ## Executa um comando composer (c="...")
	$(EXEC_T) composer $(c)
