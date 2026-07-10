<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * telegram_pending_confirmations ganha um discriminador `tipo`. A MESMA fila (uma linha por
 * usuário — §6.b) passa a guardar dois estados mutuamente exclusivos entre mensagens do bot:
 *   - 'confirmacao'   → payload é o DadosGastoManual normalizado, aguardando "sim"/"não" (04b);
 *   - 'esclarecimento'→ payload é a extração CRUA parcial (GastoParcial), aguardando o
 *                        usuário completar os campos que faltam (slot-filling multi-turno, 04 §3.3).
 * Existentes viram 'confirmacao' (default), preservando o comportamento atual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_pending_confirmations', function (Blueprint $table) {
            $table->string('tipo')->default('confirmacao')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_pending_confirmations', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
