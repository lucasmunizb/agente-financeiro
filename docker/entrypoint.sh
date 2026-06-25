#!/usr/bin/env sh
# =====================================================================
# Entrypoint compartilhado (app e worker).
#
# Suporta os dois modos de configuração do projeto:
#   - DEV: variáveis vêm do .env (Docker Compose).
#   - PRODUÇÃO (Swarm): SEM .env. Segredos montados em /run/secrets via
#     variáveis *_FILE. Este script lê cada VAR_FILE e exporta VAR antes
#     do boot, evitando segredos embutidos na imagem ou em config:cache.
# =====================================================================
set -eu

# Resolve qualquer variável no padrão "<NOME>_FILE" -> exporta <NOME> com o
# conteúdo do arquivo (Docker Secrets em /run/secrets/<nome>).
for var in $(env | grep '_FILE=' | cut -d= -f1 || true); do
	base="${var%_FILE}"
	file_path="$(printenv "$var")"
	if [ -n "$file_path" ] && [ -f "$file_path" ]; then
		val="$(cat "$file_path")"
		export "$base"="$val"
	fi
done

# Garante diretórios graváveis (bind-mount em dev pode vir sem eles).
if [ -d /app ]; then
	mkdir -p /app/storage/framework/cache \
		/app/storage/framework/sessions \
		/app/storage/framework/views \
		/app/storage/logs \
		/app/bootstrap/cache 2>/dev/null || true
fi

exec "$@"
