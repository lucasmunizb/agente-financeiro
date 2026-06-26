<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * budgets — orçamento mensal (doc 04 / doc 08 §6).
 * MVP usa o orçamento GERAL (categoria_id NULL); por categoria é pós-MVP, mas a
 * coluna já existe. limite_cents em BIGINT. mes em "YYYY-MM". Isolado por usuário.
 * Unicidade: um orçamento geral por (user, mes) — índice parcial onde categoria é
 * nula — e um por (user, mes, categoria) quando há categoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('mes', 7);
            $table->bigInteger('limite_cents');
            $table->foreignId('categoria_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->timestampsTz();

            $table->index('user_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX budgets_geral_unique ON budgets '.
            '(user_id, mes) WHERE categoria_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX budgets_categoria_unique ON budgets '.
            '(user_id, mes, categoria_id) WHERE categoria_id IS NOT NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_limite_cents_check CHECK (limite_cents >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
