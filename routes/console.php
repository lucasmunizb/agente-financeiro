<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expurgo diário do histórico de conversa com mais de 60 dias (doc 02 §3.6).
Schedule::command('ai:expurgar-conversas')->dailyAt('03:30');
