<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * data_pagamento em installments (decisão do usuário 2026-07-08 / doc 03 §4.6:
 * "data de pagamento — só ao confirmar pagamento"). Registra o dia em que a
 * parcela FORA DE CARTÃO foi quitada. Nullable: vazio = em aberto. É uma DATA
 * (sem hora), coerente com `vencimento`. Pagamento é ação por parcela, durável.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->date('data_pagamento')->nullable()->after('vencimento');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn('data_pagamento');
        });
    }
};
