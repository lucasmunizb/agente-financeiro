<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Remove `transactions.recurrence_id` (spec 12, D4). A coluna era a "verdade" de que um
 * lançamento nasceu de uma recorrência — e, com ela, a possibilidade de a mesma conta existir
 * como lançamento E como recorrência no mesmo mês. Recorrência agora vive apenas em
 * `recurrence_occurrences`, e a anti-duplicação é a UNIQUE `(recurrence_id, competencia)`.
 *
 * Roda DEPOIS da conversão do histórico (migration 2026_07_21_000003), que já esvaziou a
 * coluna. Mantê-la seria manter aberta a porta que a spec 12 fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->after('account_id')
                ->constrained('recurrences')->nullOnDelete();
        });
    }
};
