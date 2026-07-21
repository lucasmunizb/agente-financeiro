#!/usr/bin/env sh
# =====================================================================
# Job de migration do deploy (usado pelo serviço `migrate` do Swarm).
#
# Por que um script e não um `sh -c "migrate && seed"` no stack:
# precisamos REVERTER o lote se a migration falhar no meio. O Postgres
# roda cada arquivo de migration numa transação (DDL transacional), então
# o arquivo que falhou se desfaz sozinho — mas os arquivos ANTERIORES do
# mesmo lote já commitaram. Sem este rollback, o banco fica num schema
# intermediário que não corresponde a nenhuma versão da imagem.
#
# Guarda contra o rollback destrutivo: só reverte se este run REALMENTE
# aplicou alguma coisa (pendentes diminuíram). Se o migrate falhou antes
# de aplicar qualquer coisa (banco fora do ar, secret ausente), NÃO se
# mexe no banco — senão um `--step=1` derrubaria o lote do deploy ANTERIOR,
# que está de pé e correto.
#
# MIGRATE_AUTO_ROLLBACK=0 desliga a reversão automática (deixa o schema
# como está para inspeção manual). O deploy falha do mesmo jeito.
# =====================================================================
set -eu

pendentes() {
	# Uma linha por migration não aplicada. `|| true` porque o grep -c sai
	# com 1 quando não acha nada, e o `set -e` mataria o script.
	php artisan migrate:status --no-ansi 2>/dev/null | grep -c 'Pending' || true
}

antes=$(pendentes)
echo "[migrate] pendentes antes: $antes"

if php artisan migrate --force; then
	# Tabelas de referência: idempotente (firstOrCreate), roda a cada deploy.
	# Só depois do migrate OK — nunca semeia sobre schema quebrado.
	php artisan db:seed --force --class=ReferenciaSeeder
	echo "[migrate] concluido com sucesso."
	exit 0
fi

echo "[migrate] FALHOU."
depois=$(pendentes)
echo "[migrate] pendentes depois: $depois"

if [ "${MIGRATE_AUTO_ROLLBACK:-1}" = "0" ]; then
	echo "[migrate] MIGRATE_AUTO_ROLLBACK=0 — schema deixado como esta para inspecao."
	exit 1
fi

if [ "$depois" -lt "$antes" ]; then
	echo "[migrate] aplicou parcialmente ($antes -> $depois) — revertendo o lote."
	if php artisan migrate:rollback --force --step=1; then
		echo "[migrate] lote revertido: banco de volta ao schema anterior."
	else
		echo "[migrate] ROLLBACK FALHOU — banco em estado intermediario."
		echo "[migrate] INTERVENCAO MANUAL: restaure o dump antes de novo deploy."
	fi
else
	echo "[migrate] nada foi aplicado — nada a reverter, schema intacto."
fi

exit 1
