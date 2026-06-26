<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * merchant_aliases — regra fixa por estabelecimento (doc 08 §1/§2).
 * "Sempre Uber = transporte". Isolado por usuário; alias único por usuário (uma
 * regra por estabelecimento). Aponta para uma categoria. Alias armazenado
 * normalizado; a correção do usuário cria ou atualiza o alias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_aliases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->timestampsTz();

            $table->unique(['user_id', 'alias']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_aliases');
    }
};
