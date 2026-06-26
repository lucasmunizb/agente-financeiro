<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * incomes — receitas, base do "disponível do mês" (doc 04 / doc 03 §4.5).
 * Dinheiro em centavos (BIGINT). tipo fixa/variável. Isolada por user_id; soft
 * delete (LGPD). Recorrências (entidade própria) ficam para etapa posterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('descricao');
            $table->bigInteger('valor_cents');
            $table->date('data');
            $table->string('tipo');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'data']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE incomes ADD CONSTRAINT incomes_tipo_check ".
                "CHECK (tipo IN ('fixa', 'variavel'))"
            );
            DB::statement('ALTER TABLE incomes ADD CONSTRAINT incomes_valor_cents_check CHECK (valor_cents >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
