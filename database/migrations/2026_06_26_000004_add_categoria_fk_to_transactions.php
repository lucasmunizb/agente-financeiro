<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Adiciona a FK real transactions.categoria_id -> categories, agora que a tabela
 * existe (a coluna foi criada solta no bloco de transações). Excluir a categoria
 * não apaga o lançamento — apenas zera o vínculo (nullOnDelete), preservando
 * histórico (categorias são arquivadas, não excluídas, no uso normal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('categoria_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['categoria_id']);
        });
    }
};
