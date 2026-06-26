<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * transactions — lançamento financeiro (doc 04 / doc 03 §4.6).
 * Dinheiro em centavos (BIGINT); origem auditável (manual/telegram/pdf); isolado
 * por usuário; soft delete (LGPD). Campo `moeda` preparado para o pós-MVP (BRL no MVP).
 * O valor por parcela NÃO é armazenado aqui nem em installments — é derivado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('descricao');
            $table->bigInteger('valor_total_cents');
            $table->date('data_compra');
            $table->foreignId('payment_method_id')->constrained();
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            // FK para categories será adicionada no bloco de categorias (tabela ainda não existe).
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->foreignId('status_id')->constrained('status_pagamento');
            $table->string('origem');
            $table->char('moeda', 3)->default('BRL');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'data_compra']);
            $table->index('card_id');
            $table->index('categoria_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE transactions ADD CONSTRAINT transactions_origem_check ".
                "CHECK (origem IN ('manual', 'telegram', 'pdf'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
