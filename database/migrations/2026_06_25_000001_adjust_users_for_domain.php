<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ajustes da tabela users para o domínio (início do bloco 3 da F1):
 * - name nullable (auth não exige; minimiza dado pessoal — LGPD).
 * - timezone (preferência de fuso; base America/Sao_Paulo).
 * - aceite_lgpd_em (consentimento LGPD — regra inviolável nº 6).
 * - deleted_at (soft delete; exclusão lógica LGPD).
 * timestamptz para instantes (fuso), conforme convenção do projeto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('timezone')->default('America/Sao_Paulo')->after('email');
            $table->timestampTz('aceite_lgpd_em')->nullable()->after('password');
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->dropColumn(['timezone', 'aceite_lgpd_em', 'deleted_at']);
        });
    }
};
