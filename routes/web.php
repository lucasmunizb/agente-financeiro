<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\VerificaSegredoTelegram;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Webhook do Telegram (doc 06 §3). Segredo no header valida a origem; CSRF é
// isento em bootstrap/app.php (o Telegram não envia token de sessão).
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware(VerificaSegredoTelegram::class)
    ->name('telegram.webhook');
