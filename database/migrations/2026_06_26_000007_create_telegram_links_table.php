<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * telegram_links — vínculo seguro Telegram (doc 06 §1). Fluxo: web gera token
 * (status `pendente`, só o HASH do token + expiração curta) → bot valida e captura
 * telegram_user_id + telefone → vínculo `ativo`, token consumido (hash nulo).
 *
 * Unicidade: um telegram_user_id por conta (entre vínculos ativos) e UM vínculo
 * `ativo` por user_id (índices parciais). Sem dados sensíveis além do telefone
 * verificado (necessário ao vínculo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('telefone')->nullable();
            $table->string('token_hash', 64)->nullable();
            $table->timestampTz('token_expira_em')->nullable();
            $table->string('status')->default('pendente');
            $table->timestampTz('vinculado_em')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });

        // Um vínculo ativo por conta; um telegram_user_id por vínculo ativo.
        DB::statement(
            'CREATE UNIQUE INDEX telegram_links_user_ativo_unique ON telegram_links '.
            "(user_id) WHERE status = 'ativo'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX telegram_links_tg_user_ativo_unique ON telegram_links '.
            "(telegram_user_id) WHERE status = 'ativo'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX telegram_links_token_hash_unique ON telegram_links '.
            '(token_hash) WHERE token_hash IS NOT NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE telegram_links ADD CONSTRAINT telegram_links_status_check '.
                "CHECK (status IN ('pendente', 'ativo', 'revogado'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_links');
    }
};
