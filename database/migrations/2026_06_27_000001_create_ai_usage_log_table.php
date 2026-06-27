<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ai_usage_log — custo de IA por chamada (doc 02 §3.6 / doc 09 NFR-LGPD). Modelo, tokens
 * in/out, custo estimado (BIGINT centavos — regra 5), latência, tipo e usuário.
 * SEM dados sensíveis: nenhum conteúdo de prompt/resposta é retido. Append-only (imutável):
 * só created_at, como audit_log. Consultável por SQL/admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_saida')->default(0);
            $table->bigInteger('custo_estimado_cents')->default(0);
            $table->unsignedInteger('latencia_ms')->nullable();
            $table->string('tipo');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['tipo', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_usage_log ADD CONSTRAINT ai_usage_log_custo_check CHECK (custo_estimado_cents >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_log');
    }
};
