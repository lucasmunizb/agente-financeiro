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
        webhook-up webhook-down ci-local ci-test ci-build ci-scan \
        stan stan-debt stan-debt-resumo stan-baseline stan-domain coverage

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

# ---------------------------------------------------------------------
# Qualidade — análise estática (PHPStan/Larastan) e cobertura (PCOV).
# Ambos rodam DENTRO do contêiner (regra 9). Artefatos vão para build/,
# que é gitignored.
# ---------------------------------------------------------------------
stan: ## Análise estática (level 6) — PORTÃO: reprova só por erro NOVO
	$(EXEC_T) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

# A LISTA DE TRABALHO. Mesma análise do portão, sem o baseline: mostra os 147
# erros que `make stan` silencia. É o alvo para usar ao sentar para corrigir.
stan-debt: ## Lista a dívida congelada do level 6 (os erros que o portão ignora)
	$(EXEC_T) vendor/bin/phpstan analyse -c phpstan-debt.neon --memory-limit=1G --no-progress

# Resumo por TIPO de erro, do mais frequente ao menos — para escolher por onde
# começar (um tipo repetido 60x costuma ser uma correção só, aplicada em massa).
stan-debt-resumo: ## Agrupa a dívida do level 6 por tipo de erro, com contagem
	@$(EXEC_T) sh -lc 'vendor/bin/phpstan analyse -c phpstan-debt.neon --memory-limit=1G \
	  --no-progress --error-format=json 2>/dev/null | php -r "\
	    \$$j = json_decode(stream_get_contents(STDIN), true); \
	    \$$b = []; \$$ex = []; \
	    foreach (\$$j[\"files\"] as \$$f => \$$d) { foreach (\$$d[\"messages\"] as \$$m) { \
	      \$$id = \$$m[\"identifier\"] ?? \"(sem identifier)\"; \
	      \$$b[\$$id] = (\$$b[\$$id] ?? 0) + 1; \$$ex[\$$id] = \$$ex[\$$id] ?? \$$m[\"message\"]; } } \
	    arsort(\$$b); \
	    printf(\"%-38s %6s   %s\n\", \"TIPO\", \"QTD\", \"EXEMPLO\"); \
	    foreach (\$$b as \$$id => \$$n) printf(\"%-38s %6d   %s\n\", \$$id, \$$n, substr(\$$ex[\$$id], 0, 70)); \
	    printf(\"%-38s %6d\n\", \"TOTAL\", \$$j[\"totals\"][\"file_errors\"]);"'

# Regenera a linha de base. Use ao CORRIGIR erros (o baseline encolhe). Se usar
# para absorver erro novo, confira o diff antes de commitar: cada entrada
# acrescentada é dívida que o portão deixará de pegar.
stan-baseline: ## Regenera phpstan-baseline.neon (a dívida congelada do level 6)
	$(EXEC_T) vendor/bin/phpstan analyse --memory-limit=1G --no-progress --generate-baseline

stan-domain: ## Análise estática (level 9) só no núcleo: app/Domain + app/Ai
	$(EXEC_T) vendor/bin/phpstan analyse -c phpstan-domain.neon --memory-limit=1G --no-progress

# PCOV vem instalado no stage `base` mas DESATIVADO (pcov.enabled=0), para não
# onerar `make test`. Aqui ligamos só nesta invocação. O memory_limit maior é
# necessário: o driver mantém o mapa de cobertura de ~5,4k statements em memória.
coverage: db-test ## Roda a suíte com cobertura (PCOV) e gera build/coverage/clover.xml
	$(EXEC_TEST) php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/pest --coverage

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

# ---------------------------------------------------------------------
# CI local — reproduz os estágios do pipeline que NÃO tocam produção
# (test → build → scan), contra uma árvore LIMPA do git (espelha o
# checkout do runner). Pega falhas antes do push. Ver scripts/ci-local.sh.
# Deploy + smoke tocam produção e são promovidos só por você via git push.
# ---------------------------------------------------------------------
# Espelha o job `test` do deploy.yml, na mesma ordem e com a mesma severidade:
#   stan (level 6)  → PORTÃO   (tem baseline; só reprova por erro NOVO)
#   coverage        → informativo (|| true) enquanto não houver --min decidido
# Quando fixar o limiar de cobertura, tire o `|| true` daqui E o
# continue-on-error de lá — nos DOIS lugares, senão o CI local para de espelhar
# o remoto e você descobre a quebra só depois do push.
ci-local: ## Roda o pipeline local inteiro (stan + coverage + test + build + scan)
	@$(MAKE) --no-print-directory stan
	@$(MAKE) --no-print-directory stan-debt-resumo || true
	@$(MAKE) --no-print-directory coverage || true
	@bash scripts/ci-local.sh all

ci-test: ## CI local: só o gate de teste (contra árvore limpa do git)
	@bash scripts/ci-local.sh test

ci-build: ## CI local: só o build do target de produção
	@bash scripts/ci-local.sh build

ci-scan: ## CI local: só o scan Trivy (HIGH/CRITICAL) da última imagem
	@bash scripts/ci-local.sh scan
