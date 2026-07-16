#!/usr/bin/env bash
# =====================================================================
# Reproduz LOCALMENTE os estágios do pipeline (.github/workflows/deploy.yml)
# que NÃO tocam produção, para pegar falhas ANTES do push:
#
#   test (gate TDD)  →  build (target prod)  →  scan (Trivy HIGH/CRITICAL)
#
# NÃO faz push, deploy nem smoke — esses tocam a produção do usuário e são
# promovidos só pelo próprio usuário via git push (regra inviolável 1).
#
# FIDELIDADE: o CI roda `actions/checkout` = árvore LIMPA (sem public/build,
# vendor, node_modules — todos gitignored). Testar contra o $PWD mascararia
# bugs que só aparecem no checkout limpo (foi o caso do "Vite manifest not
# found"). Por isso exportamos a árvore rastreada com `git archive` para um
# diretório temporário e rodamos TUDO contra ela — incluindo suas mudanças
# em arquivos rastreados ainda não commitadas (via `git stash create`), mas
# NUNCA os arquivos ignorados. É o que o runner veria no push.
#
# Uso:
#   scripts/ci-local.sh            # pipeline inteiro: test + build + scan
#   scripts/ci-local.sh test       # só o gate de teste
#   scripts/ci-local.sh build      # só o build da imagem de produção
#   scripts/ci-local.sh scan       # só o scan Trivy (usa a última imagem build)
#
# Requisito: Docker + git no host (nada mais — regra 9).
# =====================================================================
set -euo pipefail

STAGE="${1:-all}"

# Roda a partir do root do repositório. Também é onde extraímos a árvore limpa:
# o daemon do Docker (Docker Desktop/WSL2 roda numa VM à parte) só enxerga
# bind-mounts de caminhos COMPARTILHADOS — o diretório do projeto é um deles,
# /tmp não é. Extrair em /tmp faria o mount chegar VAZIO no contêiner.
REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

# Imagem do Trivy. O trivy-action embute um binário próprio; aqui usamos a
# imagem oficial com os MESMOS flags do workflow. A versão pode divergir um
# pouco da que a action embute — sobrescreva com TRIVY_IMAGE se precisar casar.
TRIVY_IMAGE="${TRIVY_IMAGE:-aquasec/trivy:0.58.0}"

# Nomes isolados p/ não colidir com o ambiente de dev (compose) nem entre runs.
CI_IMG_BASE="financeiro-ci-base"
CI_IMG_PROD="financeiro-ci-prod"
CI_PG="financeiro-ci-pg"
CI_NET="financeiro-ci-net"
# Diretório da árvore limpa: DENTRO do repo (caminho compartilhado com o daemon).
# Gitignored (.ci-local.*) para não sujar o `git status`; como o export usa um
# ref do git, esse diretório nunca entra na própria árvore exportada.
CLEAN_DIR="$(mktemp -d "$REPO_ROOT/.ci-local.XXXXXX")"

log()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

cleanup() {
  docker rm -f "$CI_PG" >/dev/null 2>&1 || true
  docker network rm "$CI_NET" >/dev/null 2>&1 || true
  # composer/test rodam como root no contêiner e escrevem vendor/ no diretório
  # montado com dono root — um rm no host falharia. Se o rm normal não der conta,
  # apaga via contêiner root montando o repo.
  if [ -n "${CLEAN_DIR:-}" ] && [ -d "$CLEAN_DIR" ]; then
    rm -rf "$CLEAN_DIR" 2>/dev/null || \
      docker run --rm -v "$REPO_ROOT":/repo alpine \
        rm -rf "/repo/$(basename "$CLEAN_DIR")" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# --- Exporta a árvore LIMPA (rastreada) para $CLEAN_DIR --------------------
# `git stash create` faz um commit-objeto das mudanças atuais em arquivos
# rastreados (sem tocar seu working tree) e imprime o SHA; vazio = nada a
# empacotar, então caímos em HEAD. `git archive` só inclui rastreados →
# public/build/vendor/node_modules (ignorados) ficam de fora, igual ao CI.
export_clean_tree() {
  log "Exportando árvore limpa do git (espelha o checkout do CI)"
  local ref
  ref="$(git stash create 2>/dev/null || true)"
  ref="${ref:-HEAD}"
  git archive --format=tar "$ref" | tar -x -C "$CLEAN_DIR"
  ok "Árvore exportada para $CLEAN_DIR (sem arquivos gitignored)"
}

# --- Estágio: test (espelha o job `test`) ---------------------------------
run_test() {
  log "[test] build do stage base (PHP + extensões, com dev deps)"
  docker build -f "$CLEAN_DIR/docker/Dockerfile" --target base -t "$CI_IMG_BASE" "$CLEAN_DIR"

  log "[test] subindo Postgres 16 efêmero (financeiro_test)"
  docker network create "$CI_NET" >/dev/null 2>&1 || true
  docker rm -f "$CI_PG" >/dev/null 2>&1 || true
  docker run -d --name "$CI_PG" --network "$CI_NET" \
    -e POSTGRES_DB=financeiro_test \
    -e POSTGRES_USER=financeiro \
    -e POSTGRES_PASSWORD=secret \
    postgres:16-alpine >/dev/null

  printf '  aguardando o Postgres'
  for _ in $(seq 1 30); do
    if docker exec "$CI_PG" pg_isready -U financeiro >/dev/null 2>&1; then break; fi
    printf '.'; sleep 1
  done
  echo

  log "[test] composer install + php artisan test (gate TDD)"
  local app_key
  app_key="base64:$(openssl rand -base64 32)"
  docker run --rm --network "$CI_NET" -v "$CLEAN_DIR":/app -w /app \
    -e APP_ENV=testing -e APP_KEY="$app_key" \
    -e DB_CONNECTION=pgsql -e DB_HOST="$CI_PG" -e DB_PORT=5432 \
    -e DB_DATABASE=financeiro_test \
    -e DB_USERNAME=financeiro -e DB_PASSWORD=secret \
    "$CI_IMG_BASE" sh -c 'composer install --no-interaction --prefer-dist && php artisan test'
  ok "[test] suíte verde"
}

# --- Estágio: build (espelha o job `build-scan-push`, sem push) -----------
run_build() {
  log "[build] build do stage prod (copia código, otimiza autoload, compila Vite)"
  docker build -f "$CLEAN_DIR/docker/Dockerfile" --target prod -t "$CI_IMG_PROD:local" "$CLEAN_DIR"
  ok "[build] imagem $CI_IMG_PROD:local criada"
}

# --- Estágio: scan (mesmos flags do passo Trivy) --------------------------
run_scan() {
  log "[scan] Trivy — falha em HIGH/CRITICAL com fix disponível"
  docker run --rm \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v financeiro-trivy-cache:/root/.cache/ \
    "$TRIVY_IMAGE" image \
      --severity HIGH,CRITICAL \
      --ignore-unfixed \
      --exit-code 1 \
      "$CI_IMG_PROD:local"
  ok "[scan] sem CVE HIGH/CRITICAL corrigível"
}

# --- Orquestração ---------------------------------------------------------
export_clean_tree
case "$STAGE" in
  test)  run_test ;;
  build) run_build ;;
  scan)  run_scan ;;
  all)   run_test; run_build; run_scan ;;
  *) echo "Estágio inválido: $STAGE (use: all|test|build|scan)"; exit 2 ;;
esac

log "Pipeline local ($STAGE) concluído SEM tocar produção."
echo "  Faltam só deploy + smoke — promovidos por VOCÊ via git push."
