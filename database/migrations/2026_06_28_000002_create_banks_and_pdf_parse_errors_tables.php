<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * banks + pdf_parse_errors + junção N:N (doc 04 / spec 07 §5).
 *
 * Quando o parser de fatura não reconhece um trecho, registramos uma entrada de erro
 * (descrição NÃO sensível — nunca o trecho do PDF) para evoluir o parser. A relação
 * banco↔erro é N:N: o mesmo padrão de erro pode ocorrer em mais de um banco.
 *
 * `banks` é tabela de referência (codigo único). Sem dado sensível em nenhuma tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('codigo')->unique();
            $table->string('nome');
            $table->timestampsTz();
        });

        Schema::create('pdf_parse_errors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('descricao_erro');
            $table->timestampsTz();
        });

        Schema::create('bank_pdf_parse_error', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();
            $table->foreignId('pdf_parse_error_id')->constrained('pdf_parse_errors')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['bank_id', 'pdf_parse_error_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_pdf_parse_error');
        Schema::dropIfExists('pdf_parse_errors');
        Schema::dropIfExists('banks');
    }
};
