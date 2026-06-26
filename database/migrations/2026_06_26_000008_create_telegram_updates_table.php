<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * telegram_updates — deduplicação por update_id (doc 06 §2/§3). Em reentregas
 * por instabilidade, mantém a PRIMEIRA mensagem. Idempotência via UNIQUE no
 * update_id — sem Redis. Append-only: só created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('update_id')->unique();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
