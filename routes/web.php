<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\VerificaSegredoTelegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// App (exige login). Todo o app fica atrás de autenticação de sessão: quem
// não estiver logado é redirecionado automaticamente para /login (padrão do
// Laravel → route('login')). Novas rotas da aplicação entram neste grupo.
// -------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => view('welcome'))->name('home');
});

// -------------------------------------------------------------------------
// Login (apresentação). A tela e seus estados (principal/erro/carregando)
// vivem em resources/views/auth/login.blade.php. A AUTENTICAÇÃO real
// (validação + guard, test-first) é a etapa de backend — o POST abaixo é um
// placeholder que apenas dispara o estado de erro para revisão do design.
// -------------------------------------------------------------------------
Route::get('/login', fn () => view('auth.login'))->name('login');

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    return back()
        ->withInput($request->only('email'))
        ->withErrors(['email' => 'E-mail ou senha incorretos.']);
})->name('login.attempt');

// Webhook do Telegram (doc 06 §3). Segredo no header valida a origem; CSRF é
// isento em bootstrap/app.php (o Telegram não envia token de sessão).
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware(VerificaSegredoTelegram::class)
    ->name('telegram.webhook');
