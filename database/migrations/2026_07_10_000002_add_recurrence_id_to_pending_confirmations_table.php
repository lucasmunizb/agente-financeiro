<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Liga o pendente à recorrência que o produziu (spec 10). Só os produtores de recorrência
 * preenchem `recurrence_id`; os demais (chat/telegram/importação) ficam nulos. nullOnDelete:
 * apagar a recorrência não apaga a trilha do pendente. Usado na cascata "rejeitar → cancela a
 * recorrência" (decisão do usuário, C7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_confirmations', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->after('transaction_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_confirmations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_id');
        });
    }
};
