<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * recurrences — assinaturas/contas fixas (doc 03 §4.6 / doc 04 / spec 10). Entidade PRÓPRIA
 * com ciclo de vida `ativo`/`cancelado`. Só FORA DE CARTÃO (crédito usa parcelas) e só MENSAL
 * no MVP da feature. Dinheiro em centavos (BIGINT, regra 5). `proxima_em` é o ponteiro da
 * próxima ocorrência a materializar (nulo quando cancelado). Isolada por user_id; soft delete
 * (LGPD). A materialização NÃO grava lançamento — enfileira em pending_confirmations (regra 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('descricao');
            $table->bigInteger('valor_cents');
            $table->foreignId('payment_method_id')->constrained();
            // FK para categories será adicionada no bloco de categorias (como em transactions).
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->string('periodicidade')->default('mensal');
            $table->unsignedSmallInteger('dia');            // dia-do-mês (1..31, clampado na borda do mês)
            $table->string('status')->default('ativo');     // ativo | cancelado
            $table->date('proxima_em')->nullable();         // ponteiro da próxima ocorrência (nulo se cancelado)
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'proxima_em']);        // varredura do materializador
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE recurrences ADD CONSTRAINT recurrences_status_check '.
                "CHECK (status IN ('ativo', 'cancelado'))"
            );
            DB::statement(
                'ALTER TABLE recurrences ADD CONSTRAINT recurrences_periodicidade_check '.
                "CHECK (periodicidade IN ('mensal'))"
            );
            DB::statement('ALTER TABLE recurrences ADD CONSTRAINT recurrences_dia_check CHECK (dia >= 1 AND dia <= 31)');
            DB::statement('ALTER TABLE recurrences ADD CONSTRAINT recurrences_valor_cents_check CHECK (valor_cents >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrences');
    }
};
