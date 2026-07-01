# =====================================================================
# Makefile — alvos finos que encapsulam Docker. NADA roda no host além
# do make. Único pré-requisito local: Docker + make.
# =====================================================================
.DEFAULT_GOAL := help
DC := docker compose
EXEC := $(DC) exec app
EXEC_T := $(DC) exec -T app
# Execução de testes: força APP_ENV=testing e DB_DATABASE=financeiro_test. O
# contêiner do app carrega o .env (APP_ENV=local, DB_DATABASE=financeiro) como
# variáveis REAIS do SO, que venceriam os <env> do phpunit.xml (mesmo com
# force="true") — deixando a suíte rodar em ambiente "local" E, pior, contra o
# banco de DEV: RefreshDatabase faz migrate:fresh e apagaria seus dados. Passar
# -e no exec fixa ambiente e banco corretos na borda do processo. O banco de
# teste é criado por `make db-test` (dependência de test/pest).
EXEC_TEST := $(DC) exec -T -e APP_ENV=testing -e DB_DATABASE=financeiro_test app
# Serviço de build de assets (profile tools): sobe sob demanda e some ao fim.
RUN_NODE := $(DC) run --rm node
# Caminho do webhook do Telegram (doc 06 §3), anexado à URL pública do túnel.
WEBHOOK_PATH := /telegram/webhook

.PHONY: help setup bootstrap up down build rebuild restart ps logs logs-app \
        logs-worker shell worker-shell test migrate fresh seed key artisan \
        composer pest pint pint-test tinker stop npm assets vite db-test \
        webhook-up webhook-down

help: ## Lista os alvos disponíveis
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

setup: bootstrap ## Alias de bootstrap (primeira instalação)

bootstrap: ## Primeira instalação: cria esqueleto Laravel via contêiner e sobe tudo
	@bash scripts/bootstrap.sh

up: ## Sobe os 3 contêineres (app, worker, postgres) + webhook do Telegram
	$(DC) up -d
	@$(MAKE) --no-print-directory webhook-up

down: ## Derruba os contêineres (mantém o volume do Postgres) + remove webhook
	@$(MAKE) --no-print-directory webhook-down
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

db-test: ## Cria o banco de teste dedicado (financeiro_test) se não existir
	@$(DC) exec -T postgres sh -lc "psql -U $${POSTGRES_USER:-financeiro} -d $${POSTGRES_DB:-financeiro} -tc \"SELECT 1 FROM pg_database WHERE datname='financeiro_test'\" | grep -q 1 || createdb -U $${POSTGRES_USER:-financeiro} financeiro_test"

test: db-test ## Roda a suíte de testes (TDD) dentro do contêiner
	$(EXEC_TEST) php artisan test

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

pest: db-test ## Roda o Pest diretamente
	$(EXEC_TEST) ./vendor/bin/pest

pint: ## Formata o código (Laravel Pint)
	$(EXEC_T) ./vendor/bin/pint

pint-test: ## Checa o estilo sem alterar arquivos (CI)
	$(EXEC_T) ./vendor/bin/pint --test

# Uso: make artisan c="migrate:status"   |   make composer c="require pacote"
artisan: ## Executa um comando artisan (c="...")
	$(EXEC_T) php artisan $(c)

composer: ## Executa um comando composer (c="...")
	$(EXEC_T) composer $(c)

# ---------------------------------------------------------------------
# Frontend (assets) — Node roda em contêiner sob demanda (regra 9).
# ---------------------------------------------------------------------
npm: ## Executa um comando npm no contêiner node (c="install")
	$(RUN_NODE) npm $(c)

assets: ## Instala deps e builda os assets (Vite/Tailwind) para produção
	$(RUN_NODE) sh -lc "npm install && npm run build"

vite: ## Sobe o Vite dev server (HMR) em contêiner
	$(DC) run --rm --service-ports node sh -lc "npm install && npm run dev -- --host 0.0.0.0"

# ---------------------------------------------------------------------
# Bot do Telegram (DEV) — túnel HTTPS público + webhook. Tudo em contêiner
# (cloudflared no profile tools; regra 9). Guardado por TELEGRAM_BOT_TOKEN:
# sem token no .env, `up`/`down` se comportam como antes (no-op silencioso).
# Chamados automaticamente por `make up` / `make down`.
# ---------------------------------------------------------------------
webhook-up: ## Sobe o túnel e (re)registra o webhook do Telegram (se houver token)
	@if ! grep -qE '^TELEGRAM_BOT_TOKEN=.+' .env 2>/dev/null; then \
	  echo "› Telegram: sem TELEGRAM_BOT_TOKEN no .env — pulando webhook."; exit 0; fi; \
	echo "› Telegram: (re)subindo o túnel cloudflared…"; \
	$(DC) --profile tools rm -sf cloudflared >/dev/null 2>&1 || true; \
	$(DC) --profile tools up -d cloudflared >/dev/null; \
	url=""; \
	for i in $$(seq 1 20); do \
	  url=$$($(DC) logs cloudflared 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | head -1); \
	  [ -n "$$url" ] && break; sleep 1; \
	done; \
	if [ -z "$$url" ]; then \
	  echo "✗ Telegram: não obtive a URL do túnel. Veja: $(DC) logs cloudflared"; exit 0; fi; \
	echo "› Telegram: túnel em $$url — aguardando ficar acessível…"; \
	for i in $$(seq 1 30); do \
	  $(EXEC_T) sh -lc "curl -fsS -o /dev/null $$url/up" >/dev/null 2>&1 && break; sleep 2; \
	done; \
	$(EXEC_T) php artisan telegram:webhook --delete >/dev/null 2>&1 || true; \
	for i in $$(seq 1 4); do \
	  if $(EXEC_T) php artisan telegram:webhook "$$url$(WEBHOOK_PATH)" >/dev/null 2>&1; then \
	    echo "✓ Telegram: webhook registrado em $$url$(WEBHOOK_PATH)"; exit 0; fi; \
	  echo "  … Telegram ainda resolvendo o DNS do túnel; nova tentativa em 15s ($$i/4)"; sleep 15; \
	done; \
	echo "✗ Telegram: webhook não registrou. Rode 'make artisan c=\"telegram:webhook --info\"' para ver o erro."

webhook-down: ## Remove o webhook do Telegram e derruba o túnel
	@if grep -qE '^TELEGRAM_BOT_TOKEN=.+' .env 2>/dev/null; then \
	  echo "› Telegram: removendo o webhook…"; \
	  $(EXEC_T) php artisan telegram:webhook --delete >/dev/null 2>&1 || true; \
	fi; \
	$(DC) --profile tools rm -sf cloudflared >/dev/null 2>&1 || true
