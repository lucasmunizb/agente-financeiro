<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Conversão do histórico legado (spec 12, D4). Antes desta spec, uma recorrência materializava
 * um LANÇAMENTO por mês (`transactions.recurrence_id`), o que fazia a mesma conta aparecer em
 * duas fontes. Agora a conta fixa de um mês é uma linha em `recurrence_occurrences` — então
 * cada transaction recorrente VIVA vira a ocorrência equivalente e é removida junto com as
 * suas parcelas.
 *
 * Regras da conversão (totais do mês têm de ficar INALTERADOS):
 *  - recorrência é sempre 1×, então a ocorrência sai da PRIMEIRA parcela;
 *  - a competência é a do `vencimento` dessa parcela (mesma atribuição por mês do §4.5);
 *  - `status_id` e `data_pagamento` são preservados (uma conta paga continua paga);
 *  - `data_cobranca` recebe o próprio vencimento — no legado só havia recorrência fora de
 *    cartão, onde as duas datas coincidem;
 *  - conflito com uma ocorrência já existente ⇒ ignora (idempotente: rodar de novo não duplica);
 *  - transaction soft-deleted não entra (linha já excluída não vira cobrança nova).
 *
 * A remoção é física — a linha não representa mais nada e o `audit_log` preserva o rastro.
 * Escrita em SQL puro de propósito: uma migration de dados não pode depender de models, que
 * mudam com o tempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Primeira parcela de cada transaction recorrente viva (installments não tem soft
        // delete — a exclusão lógica mora na transaction).
        $primeirasParcelas = <<<'SQL'
            SELECT DISTINCT ON (i.transaction_id)
                   i.transaction_id, i.vencimento, i.status_id, i.data_pagamento
              FROM installments i
              JOIN transactions t ON t.id = i.transaction_id
             WHERE t.recurrence_id IS NOT NULL
               AND t.deleted_at IS NULL
             ORDER BY i.transaction_id, i.numero, i.id
        SQL;

        DB::statement(<<<SQL
            INSERT INTO recurrence_occurrences
                (user_id, recurrence_id, competencia, descricao, valor_cents, data_cobranca,
                 vencimento, payment_method_id, card_id, categoria_id, status_id, data_pagamento,
                 created_at, updated_at)
            SELECT t.user_id,
                   t.recurrence_id,
                   to_char(p.vencimento, 'YYYY-MM'),
                   t.descricao,
                   t.valor_total_cents,
                   p.vencimento,
                   p.vencimento,
                   t.payment_method_id,
                   t.card_id,
                   t.categoria_id,
                   p.status_id,
                   CASE WHEN p.data_pagamento IS NULL THEN NULL
                        ELSE (p.data_pagamento::timestamp AT TIME ZONE 'America/Sao_Paulo')
                   END,
                   now(),
                   now()
              FROM transactions t
              JOIN ({$primeirasParcelas}) p ON p.transaction_id = t.id
             WHERE t.recurrence_id IS NOT NULL
               AND t.deleted_at IS NULL
            ON CONFLICT (recurrence_id, competencia) WHERE deleted_at IS NULL DO NOTHING
        SQL);

        DB::statement(<<<'SQL'
            DELETE FROM installments
             WHERE transaction_id IN (
                   SELECT id FROM transactions
                    WHERE recurrence_id IS NOT NULL AND deleted_at IS NULL)
        SQL);

        DB::statement('DELETE FROM transactions WHERE recurrence_id IS NOT NULL AND deleted_at IS NULL');
    }

    /**
     * Irreversível por natureza: os lançamentos originais deixaram de existir e recriá-los
     * ressuscitaria justamente a dupla contagem que a spec 12 elimina. O `down()` é no-op
     * consciente — o rollback do schema fica na migration do drop da coluna.
     */
    public function down(): void {}
};
