<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * pending_confirmations — FILA de confirmações pendentes por usuário (FE §7.9). Diferente do
 * telegram_pending_confirmations (1 por usuário, conversacional, efêmero), aqui podem existir
 * N itens por usuário, revisáveis 1 a 1 na tela web. É a BASE comum que a recorrência mensal e
 * a importação de PDF vão alimentar — nada nasce gravado sem o "sim" do usuário (regra 7,
 * sem auto-save no MVP).
 *
 * `payload` guarda o DadosGastoManual já normalizado (centavos, regra 5) para reusar
 * RegistrarGastoManual::confirmar() no "sim" — a IA/consumidor nunca recalcula (regra 4).
 * `origem` = de onde veio o pendente; `status` = ciclo de vida; `transaction_id` liga ao
 * lançamento criado no "sim" (rastreabilidade); `expira_em` é TTL OPCIONAL (null = não expira).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_confirmations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('origem');                 // chat | telegram | recorrencia | importacao
            $table->string('tipo')->default('gasto'); // o que confirmar (futuro: receita, etc.)
            $table->jsonb('payload');                 // DadosGastoManual normalizado (regra 5)
            $table->string('status')->default('pendente'); // pendente | confirmado | rejeitado | expirado
            // No "sim", o lançamento criado (RegistrarGastoManual). nullOnDelete: apagar o
            // lançamento não apaga a trilha do pendente.
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('expira_em')->nullable();    // TTL opcional (null = não expira)
            $table->timestampTz('resolvido_em')->nullable(); // quando foi confirmado/rejeitado
            $table->timestampsTz();

            // Listagem da tela: pendentes de um usuário.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_confirmations');
    }
};
