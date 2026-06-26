<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * category_keywords — palavras-chave do lookup determinístico (doc 08 §1).
 * Ex.: "hotel"/"passagem" => viagem. A palavra é única por categoria; removida em
 * cascata ao excluir a categoria. Armazenada normalizada (caixa/acentos/espaços).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_keywords', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('palavra_chave');
            $table->timestampsTz();

            $table->unique(['category_id', 'palavra_chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_keywords');
    }
};
