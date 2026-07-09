<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Inclui "recorrencia" na procedência aceita do lançamento (spec 10). Quando o usuário
 * confirma uma ocorrência de recorrência, RegistrarGastoManual grava a transaction com
 * origem 'recorrencia' — procedência honesta. Recria o CHECK de `origem`. Só pgsql.
 * Idempotente: DROP IF EXISTS antes de recriar. Precedente: allow_boleto_in_payment_methods_check.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_origem_check');
        DB::statement(
            'ALTER TABLE transactions ADD CONSTRAINT transactions_origem_check '.
            "CHECK (origem IN ('manual', 'telegram', 'pdf', 'recorrencia'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_origem_check');
        DB::statement(
            'ALTER TABLE transactions ADD CONSTRAINT transactions_origem_check '.
            "CHECK (origem IN ('manual', 'telegram', 'pdf'))"
        );
    }
};
