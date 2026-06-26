<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * installments — parcelas (doc 04 / doc 03 §4.1).
 * Registra a estrutura N/total + vencimento + status. NÃO tem coluna de valor:
 * o valor de cada parcela é DERIVADO do valor_total_cents da transaction
 * (Money::allocate). A parcela vigente é calculada na exibição, nunca fixada.
 * Parcelas são filhas da transaction — cascade no delete efetivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->unsignedSmallInteger('total');
            $table->date('vencimento');
            $table->foreignId('status_id')->constrained('status_pagamento');
            $table->timestampsTz();

            $table->unique(['transaction_id', 'numero']);
            $table->index(['status_id', 'vencimento']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE installments ADD CONSTRAINT installments_numero_check CHECK (numero >= 1 AND numero <= total)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
