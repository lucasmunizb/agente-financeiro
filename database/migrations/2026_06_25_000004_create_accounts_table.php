<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * accounts — conta bancária para PIX/débito (doc 04 / doc 03 §4.6).
 * Isolada por user_id; soft delete (LGPD). Identidade única entre contas ativas
 * via índice parcial (WHERE deleted_at IS NULL) — válido em PostgreSQL e SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('banco');
            $table->string('descricao');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX accounts_identity_unique ON accounts '.
            '(user_id, banco, descricao) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
