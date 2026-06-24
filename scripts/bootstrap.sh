#!/usr/bin/env bash
# =====================================================================
# bootstrap.sh — cria toda a estrutura executável do projeto.
#
# Filosofia: execução 100% em contêiner (nada no host além de Docker +
# make). O esqueleto Laravel é criado por um contêiner composer
# temporário, mas as DEPENDÊNCIAS são resolvidas dentro da NOSSA imagem
# (PHP 8.3) — senão o composer:2 (PHP 8.4) travaria o lock em pacotes
# que exigem PHP >= 8.4 (ex.: symfony/yaml v8).
#
# Idempotente: pode ser rodado mais de uma vez sem quebrar.
#
# Critério de pronto: ao final, `make up` mantém 3 contêineres no ar e
# `make test` roda a suíte com sucesso.
# =====================================================================
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
UID_GID="$(id -u):$(id -g)"
PHP_PLATFORM="8.3.31"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }

command -v docker >/dev/null 2>&1 || { echo "Docker é obrigatório (não encontrado)."; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Plugin 'docker compose' é obrigatório."; exit 1; }

# Helper: roda um comando num contêiner app efêmero (sem subir a stack).
run_app() { docker compose run --rm --no-deps -T app sh -c "$1"; }

# ---------------------------------------------------------------------
# 1. .env de desenvolvimento
# ---------------------------------------------------------------------
if [ ! -f .env ]; then
	log "Criando .env a partir de .env.example"
	cp .env.example .env
else
	log ".env já existe — mantendo"
fi

# ---------------------------------------------------------------------
# 2. Hook de pré-push (bloqueia push — regra inviolável)
# ---------------------------------------------------------------------
if [ -d .git ]; then
	log "Instalando git hook de pre-push (push bloqueado por padrão)"
	chmod +x .githooks/pre-push 2>/dev/null || true
	git config core.hooksPath .githooks || warn "Não foi possível setar core.hooksPath"
fi

# ---------------------------------------------------------------------
# 3. Esqueleto Laravel 12 — criado por contêiner, SEM instalar deps
#    (--no-install evita travar o lock no PHP 8.4 do composer:2).
# ---------------------------------------------------------------------
if [ ! -f composer.json ]; then
	log "Criando esqueleto Laravel 12 via contêiner composer (sem instalar deps)"
	rm -rf _skeleton
	docker run --rm \
		-u "$UID_GID" \
		-e COMPOSER_HOME=/tmp/composer \
		-v "$ROOT":/app -w /app \
		composer:2 create-project laravel/laravel:^12.0 _skeleton \
		--no-interaction --prefer-dist --no-install --no-scripts

	log "Movendo esqueleto para a raiz (preservando arquivos já existentes)"
	shopt -s dotglob
	for item in _skeleton/*; do
		base="$(basename "$item")"
		case "$base" in
			.git|.env|.env.example|.gitignore|.dockerignore|README.md|Makefile)
				continue ;;
		esac
		if [ -e "$ROOT/$base" ]; then
			echo "    mantendo existente, descartando do esqueleto: $base"
		else
			mv "$item" "$ROOT/$base"
		fi
	done
	shopt -u dotglob
	rm -rf _skeleton
else
	log "composer.json já existe — pulando criação do esqueleto"
fi

# ---------------------------------------------------------------------
# 4. Build da imagem da aplicação (PHP 8.3 + extensões + Tesseract)
# ---------------------------------------------------------------------
log "Buildando a imagem da aplicação"
docker compose build

# ---------------------------------------------------------------------
# 5. Dependências resolvidas DENTRO da nossa imagem (PHP 8.3)
#    Fixa a plataforma para o composer resolver symfony/* na 7.x.
# ---------------------------------------------------------------------
if [ ! -d vendor ]; then
	log "Resolvendo dependências do Composer (plataforma fixada em PHP $PHP_PLATFORM)"
	run_app "composer config platform.php $PHP_PLATFORM && composer install --no-interaction --prefer-dist"
else
	log "vendor/ já existe — pulando composer install"
fi

# ---------------------------------------------------------------------
# 6. Pest (TDD). Fallback automático para PHPUnit via 'php artisan test'.
# ---------------------------------------------------------------------
if ! run_app "composer show pestphp/pest" >/dev/null 2>&1; then
	log "Instalando Pest (TDD)"
	run_app "composer require --dev --no-interaction --with-all-dependencies pestphp/pest pestphp/pest-plugin-laravel" \
		|| warn "Falha ao instalar Pest; a suíte segue com PHPUnit."
	run_app "[ -f tests/Pest.php ] || ./vendor/bin/pest --init" || warn "pest --init não concluído."
fi

# ---------------------------------------------------------------------
# 7. APP_KEY gerada ANTES do 'up' (senão os contêineres sobem com a
#    APP_KEY vazia do env_file e os testes falham com MissingAppKey).
# ---------------------------------------------------------------------
if grep -q '^APP_KEY=$' .env; then
	log "Gerando APP_KEY"
	run_app "php artisan key:generate --force"
fi

# ---------------------------------------------------------------------
# 8. Sobe os contêineres (já com a APP_KEY correta no env_file)
# ---------------------------------------------------------------------
log "Subindo os contêineres (postgres -> app -> worker)"
docker compose up -d

# ---------------------------------------------------------------------
# 9. Migrations (cria tabelas, inclusive a de jobs da fila database)
# ---------------------------------------------------------------------
log "Rodando migrations"
docker compose exec -T app php artisan migrate --force

# ---------------------------------------------------------------------
# 10. Conferência
# ---------------------------------------------------------------------
log "Status dos contêineres"
docker compose ps

cat <<'DONE'

----------------------------------------------------------------------
Bootstrap concluído.

  Próximos passos:
    make test     # roda a suíte (deve passar)
    make logs     # acompanha os logs
    App HTTP:     http://localhost:8000  (health: /up)

  Lembrete: NUNCA git push sem ordem explícita do usuário.
----------------------------------------------------------------------
DONE
