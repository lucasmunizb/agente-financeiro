<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expurgo diário do histórico de conversa com mais de 60 dias (doc 02 §3.6).
Schedule::command('ai:expurgar-conversas')->dailyAt('03:30');

// Materialização diária das recorrências mensais vencíveis: enfileira 1 confirmação por
// recorrência no seu dia (spec 10, regra 7 — nada grava sozinho). Diário porque cada
// recorrência tem seu próprio dia-do-mês; o ponteiro `proxima_em` torna a operação idempotente.
Schedule::command('recorrencia:materializar')->dailyAt('06:00');
