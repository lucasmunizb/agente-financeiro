<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Inclui "boleto" na tabela de referência de formas de pagamento (doc 03 §4.6).
 * Boleto é "fora de cartão" (vence na data da parcela/compra, como pix/débito/dinheiro).
 * Recria o CHECK de `tipo` para aceitar o novo valor. Só pgsql (sqlite não usa o CHECK).
 * Idempotente: DROP IF EXISTS antes de recriar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT IF EXISTS payment_methods_tipo_check');
        DB::statement(
            'ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_tipo_check '.
            "CHECK (tipo IN ('credito', 'debito', 'pix', 'dinheiro', 'boleto'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT IF EXISTS payment_methods_tipo_check');
        DB::statement(
            'ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_tipo_check '.
            "CHECK (tipo IN ('credito', 'debito', 'pix', 'dinheiro'))"
        );
    }
};
