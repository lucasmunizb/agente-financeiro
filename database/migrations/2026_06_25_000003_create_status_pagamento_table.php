<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * status_pagamento — tabela de referência (doc 03 §4.4).
 * Conjunto inicial de 8 códigos; lançamentos referenciam por FK (status_id).
 * Referência global (sem user_id); sem timestamps (dado estático/seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pagamento', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('codigo')->unique();
            $table->string('descricao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pagamento');
    }
};
