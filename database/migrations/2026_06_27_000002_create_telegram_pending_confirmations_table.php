<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * telegram_pending_confirmations — estado da confirmação de gasto pendente entre mensagens
 * do bot (spec 04b §5). Guarda o `DadosGastoManual` já normalizado (payload em centavos,
 * regra 5) para gravar no "sim", com TTL (expira_em — §6.a) e UM pendente ativo por usuário
 * (unique user_id — §6.b: o novo substitui o anterior). Nada sensível além do necessário
 * (regra 6); efêmero por construção (expira/é descartado). O gasto em si nasce pelo
 * RegistrarGastoManual — aqui não há dinheiro em coluna própria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_pending_confirmations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->jsonb('payload');
            $table->timestampTz('expira_em');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_pending_confirmations');
    }
};
