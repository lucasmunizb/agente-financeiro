<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expurgo diário do histórico de conversa com mais de 60 dias (doc 02 §3.6).
// onOneServer: o worker roda replicado no Swarm; o lock (cache_locks) garante 1 execução.
Schedule::command('ai:expurgar-conversas')->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping();

// Geração diária das ocorrências mensais das recorrências + liquidação das cobranças de
// cartão já debitadas (spec 12). Diário porque cada recorrência tem seu próprio dia-do-mês e
// cada cartão a sua data de cobrança; a UNIQUE (recurrence_id, competencia) torna a geração
// idempotente, então rodar de novo no mesmo dia não duplica nada.
Schedule::command('recorrencia:gerar')->dailyAt('06:00')
    ->onOneServer()
    ->withoutOverlapping();

// Expurgo de jobs falhos (pentest 2026-07 L4). O payload serializado na fila `database`
// carrega a mensagem crua do usuário (PII) em claro; sem prune, `failed_jobs` a retém
// indefinidamente — fora do expurgo de 60 dias, do export e da exclusão de conta.
Schedule::command('queue:prune-failed', ['--hours' => 48])->dailyAt('04:00')
    ->onOneServer();
