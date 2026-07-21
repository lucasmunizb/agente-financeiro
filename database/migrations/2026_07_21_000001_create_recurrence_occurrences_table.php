<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * recurrence_occurrences — a OCORRÊNCIA MENSAL de uma recorrência (spec 12). Passa a ser a
 * ÚNICA representação de uma conta fixa num mês: recorrência nunca mais gera linha em
 * transactions/installments, então a anti-dupla-contagem vira uma CONSTRAINT do banco
 * (UNIQUE recurrence_id + competencia) em vez de um guard de calendário sobre `proxima_em`.
 *
 * `competencia` (YYYY-MM) é o mês de VENCIMENTO (§4.5) — para cartão, o mês da FATURA em que
 * a cobrança cai, que pode ser posterior ao mês da `data_cobranca`. As duas datas coexistem:
 * `data_cobranca` é quando o dinheiro sai (dia do molde) e dispara a liquidação automática de
 * cartão (D3); `vencimento` é quando a conta vence. Fora de cartão as duas são iguais.
 *
 * A ocorrência é auto-contida: guarda o SNAPSHOT de descrição/valor/categoria/forma/cartão, para
 * editar o molde ("este e os próximos") não reescrever o passado. Dinheiro em centavos (regra 5).
 * Isolada por user_id (coluna própria, não só via recurrence_id); soft delete (LGPD).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrence_occurrences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurrence_id')->constrained('recurrences')->cascadeOnDelete();
            $table->char('competencia', 7);                 // YYYY-MM (mês de vencimento)
            $table->string('descricao');
            $table->bigInteger('valor_cents');
            $table->date('data_cobranca');                  // dia do molde — quando sai do bolso
            $table->date('vencimento');                     // fora de cartão = data_cobranca; cartão = venc. da fatura
            $table->foreignId('payment_method_id')->constrained();
            $table->foreignId('card_id')->nullable()->constrained();
            // FK para categories segue o padrão de transactions/recurrences (coluna solta).
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->foreignId('status_id')->constrained('status_pagamento');
            $table->timestampTz('data_pagamento')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'vencimento']);
            $table->index(['user_id', 'status_id']);
            $table->index(['recurrence_id', 'competencia']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Uma ocorrência VIVA por recorrência por competência: a idempotência da geração
            // (rodar o agendador duas vezes no mesmo dia) é garantida aqui, não no código.
            DB::statement(
                'CREATE UNIQUE INDEX recurrence_occurrences_unica '.
                'ON recurrence_occurrences (recurrence_id, competencia) WHERE deleted_at IS NULL'
            );
            DB::statement(
                'ALTER TABLE recurrence_occurrences ADD CONSTRAINT recurrence_occurrences_valor_cents_check '.
                'CHECK (valor_cents >= 0)'
            );
            DB::statement(
                'ALTER TABLE recurrence_occurrences ADD CONSTRAINT recurrence_occurrences_competencia_check '.
                "CHECK (competencia ~ '^[0-9]{4}-[0-9]{2}$')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrence_occurrences');
    }
};
