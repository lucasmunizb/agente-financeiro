<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * chat_messages — histórico REAL do chat financeiro na web (spec FE §7.14). Uma linha por
 * mensagem, isolada por usuário (regra: escopo estrito). Guarda o texto da conversa
 * (retenção de 60 dias, doc 09 — expurgada por ExpurgarConversas), as fontes/trace da
 * resposta da IA (barreira 5) e o veredito do guard (barreira 4, `aprovado`). NÃO guarda
 * nada do PDF (regra 6): `tem_anexo` só registra que a mensagem CARREGOU um anexo — o
 * arquivo é efêmero e nunca persistido. Dinheiro não vive aqui: valores só aparecem, já
 * formatados, dentro do `body` que a IA redigiu sobre números calculados pelo domínio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 9); // 'user' | 'assistant'
            $table->text('body');
            $table->jsonb('fontes')->nullable();   // trace das tools (só em respostas)
            $table->boolean('aprovado')->nullable(); // veredito do guard (só em respostas)
            $table->boolean('tem_anexo')->default(false);
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_role_check CHECK (role IN ('user', 'assistant'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
