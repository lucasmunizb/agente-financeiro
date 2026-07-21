<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * recurrences.card_id (spec 12, D3). Recorrência em cartão de crédito passa a ser PERMITIDA
 * (assinaturas quase sempre caem no cartão): o molde precisa saber em qual cartão, para a
 * ocorrência calcular o vencimento pela fatura ({@see CalculadoraDeVencimento::cartao}).
 *
 * Nullable: fora de cartão continua sem cartão. A obrigatoriedade quando a forma é `credito`
 * fica no DOMÍNIO — o id do método `credito` vem de tabela de referência, não é constante, e
 * um CHECK não conseguiria expressá-la.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurrences', function (Blueprint $table) {
            $table->foreignId('card_id')->nullable()->after('payment_method_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('recurrences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_id');
        });
    }
};
