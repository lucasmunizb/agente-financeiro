<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * cards — cartão identificado por 4 dígitos finais + descrição (doc 03 §4.6).
 * Limite opcional em centavos (BIGINT). Isolado por user_id; soft delete (LGPD).
 * Identidade única (user_id, final_4, descricao) entre cartões ativos via índice
 * parcial. CHECK de faixa dos dias (1..31) apenas no PostgreSQL (produção).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('descricao');
            $table->string('final_4', 4);
            $table->bigInteger('limite_cents')->nullable();
            $table->unsignedSmallInteger('dia_fechamento');
            $table->unsignedSmallInteger('dia_vencimento');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX cards_identity_unique ON cards '.
            '(user_id, final_4, descricao) WHERE deleted_at IS NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cards ADD CONSTRAINT cards_dia_fechamento_check CHECK (dia_fechamento BETWEEN 1 AND 31)');
            DB::statement('ALTER TABLE cards ADD CONSTRAINT cards_dia_vencimento_check CHECK (dia_vencimento BETWEEN 1 AND 31)');
            DB::statement('ALTER TABLE cards ADD CONSTRAINT cards_limite_cents_check CHECK (limite_cents IS NULL OR limite_cents >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
