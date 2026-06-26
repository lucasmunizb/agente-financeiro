<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * categories — categoria do gasto (doc 08 / doc 04).
 * Categoria única por gasto; sem subcategoria no MVP. Tem cor e ícone; pode ser
 * arquivada sem perder histórico. Isolada por user_id; soft delete (LGPD).
 * Nome único por usuário entre categorias não excluídas (índice parcial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nome');
            $table->string('cor', 7)->nullable();
            $table->string('icone')->nullable();
            $table->boolean('arquivada')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX categories_nome_unique ON categories '.
            '(user_id, nome) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
