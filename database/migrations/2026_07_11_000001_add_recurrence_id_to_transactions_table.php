<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Liga o lançamento à recorrência que o gerou (spec 10). Antes o elo morria em
 * `pending_confirmations.recurrence_id`; agora chega até a `transactions` — é a VERDADE do
 * "este lançamento é recorrente" (não mais só a `origem`/procedência). Só lançamentos nascidos
 * de recorrência preenchem; os demais (manual/telegram/pdf) ficam nulos. nullOnDelete: apagar a
 * recorrência não apaga o lançamento (histórico preservado — LGPD). Indexada para navegar do
 * lançamento para a regra e vice-versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->after('account_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_id');
        });
    }
};
