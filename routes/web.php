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

// -------------------------------------------------------------------------
// Criar conta (apresentação). Público. A criação real do usuário (validação
// server-side + persistência + login, test-first) é a etapa de backend — o
// POST abaixo só valida o formato e encaminha para o onboarding.
// -------------------------------------------------------------------------
Route::get('/criar-conta', fn () => view('auth.register'))->name('register');

Route::post('/criar-conta', function (Request $request) {
    $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
        'terms' => ['accepted'],
    ], [
        'email.email' => 'Use um e-mail válido.',
        'password.confirmed' => 'As senhas não conferem.',
        'terms.accepted' => 'Aceite os termos para continuar.',
    ]);

    return redirect()->route('onboarding');
})->name('register.attempt');

// -------------------------------------------------------------------------
// Documentos legais (apresentação). Públicos e indexáveis. Conteúdo estático
// (LGPD/doc 09): Termos de Uso e Política de Privacidade, linkados no aceite
// da tela de cadastro e no consentimento do onboarding.
// -------------------------------------------------------------------------
Route::view('/termos', 'legal.termos')->name('terms');
Route::view('/politica-de-privacidade', 'legal.privacidade')->name('privacy');

// -------------------------------------------------------------------------
// Onboarding e consentimento (apresentação). Público por ora; passará a
// exigir o usuário recém-criado quando o backend de auth existir. Persistir
// o aceite (aceite_lgpd_em) + efetivar o login é a etapa de backend.
// -------------------------------------------------------------------------
Route::get('/onboarding', fn () => view('onboarding'))->name('onboarding');

Route::post('/onboarding', function (Request $request) {
    $request->validate(
        ['consent' => ['accepted']],
        ['consent.accepted' => 'É preciso concordar para continuar.'],
    );

    return redirect('/');
})->name('onboarding.consent');

// -------------------------------------------------------------------------
// SEO técnico (só rotas públicas). Servidos dinamicamente para que a URL
// absoluta acompanhe o ambiente (APP_URL: dev vs. produção). O webhook do
// Telegram e o onboarding (consentimento por usuário) ficam fora do índice.
// -------------------------------------------------------------------------
Route::get('/robots.txt', function () {
    $linhas = [
        'User-agent: *',
        'Disallow: /onboarding',
        'Disallow: /telegram/',
        '',
        'Sitemap: '.url('/sitemap.xml'),
        '',
    ];

    return response(implode("\n", $linhas))->header('Content-Type', 'text/plain');
})->name('robots');

Route::get('/sitemap.xml', function () {
    // Apenas páginas públicas e indexáveis (login, criar conta, legais).
    $rotas = ['login', 'register', 'terms', 'privacy'];

    $urls = collect($rotas)->map(fn ($nome) => '  <url><loc>'.e(route($nome)).'</loc></url>')->implode("\n");

    $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        {$urls}
        </urlset>
        XML;

    return response($xml)->header('Content-Type', 'application/xml');
})->name('sitemap');

// Webhook do Telegram (doc 06 §3). Segredo no header valida a origem; CSRF é
// isento em bootstrap/app.php (o Telegram não envia token de sessão).
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware(VerificaSegredoTelegram::class)
    ->name('telegram.webhook');
