<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * payment_methods — tabela de referência (doc 04 / doc 03 §4.6).
 * Conjunto fixo: credito, debito, pix, dinheiro. Referência global (sem user_id),
 * apontada por FK nos lançamentos. Sem timestamps (dado estático/seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tipo')->unique();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_tipo_check ".
                "CHECK (tipo IN ('credito', 'debito', 'pix', 'dinheiro'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
