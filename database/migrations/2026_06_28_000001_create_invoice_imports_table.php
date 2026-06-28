<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * invoice_imports — controle da pré-importação de fatura (doc 04 / spec 07 §5).
 *
 * SÓ metadados: o hash do NOME do arquivo (não do conteúdo) para deduplicação, o
 * cartão alvo (opcional) e o status próprio do fluxo de importação. O PDF e o texto
 * extraído NUNCA são persistidos (regra inviolável 6); nenhuma coluna sensível.
 *
 * Dedupe (C1) é por (user_id, hash_arquivo_nome) — mas é AVISO, não bloqueio: o
 * usuário pode reprocessar mediante confirmação explícita, então usamos ÍNDICE
 * (não UNIQUE), permitindo mais de uma tentativa do mesmo arquivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hash_arquivo_nome', 64);
            $table->string('status')->default('pendente_revisao');
            $table->timestampsTz();

            $table->index(['user_id', 'hash_arquivo_nome']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE invoice_imports ADD CONSTRAINT invoice_imports_status_check '.
                "CHECK (status IN ('pendente_revisao', 'confirmada', 'parcial', 'cancelada', 'erro'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_imports');
    }
};
