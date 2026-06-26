<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * audit_log — auditoria (doc 04). Histórico de criação/edição/cancelamento/
 * importação SEM dados sensíveis. Append-only (imutável): só created_at.
 * `entidade_id` (acrescentado ao doc 04) liga a trilha ao registro específico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entidade');
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->string('acao');
            $table->jsonb('antes')->nullable();
            $table->jsonb('depois')->nullable();
            $table->string('origem');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['entidade', 'entidade_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
