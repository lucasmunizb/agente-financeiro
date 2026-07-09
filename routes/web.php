<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\LancamentoController;
use App\Http\Controllers\LancamentoFormController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\TelegramLinkController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\VerificaSegredoTelegram;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// App (exige login). Todo o app fica atrás de autenticação de sessão: quem
// não estiver logado é redirecionado automaticamente para /login (padrão do
// Laravel → route('login')). Novas rotas da aplicação entram neste grupo.
// -------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    // Dashboard ("Visão Geral") — destino do login. Apresentação com DADOS FAKE
    // (regra 3); a integração com o backend (spec-06) vem depois. O estado da tela
    // (pronto | vazio | carregando) hoje sai da query (?estado=…) só para revisar
    // as três telas; quando o backend existir, o estado passa a vir dos dados reais.
    // Dashboard "Visão Geral" (spec 06). Compõe os números já calculados pelo
    // domínio e apenas formata para a tela; carrega também cartões/categorias do
    // usuário para o modal "Registrar gasto".
    Route::get('/', [DashboardController::class, 'index'])->name('home');

    // Lançamentos — lista/extrato (FE §7.6). Borda fina sobre o domínio determinístico
    // (ConsultarLancamentos): lista as parcelas do mês agrupadas por dia, com filtros e o
    // "total exibido" já somado. Leitura apenas; a UI nunca calcula dinheiro (regra 4).
    Route::get('/lancamentos', [LancamentoController::class, 'index'])->name('lancamentos');

    // Criar/editar lançamento como PÁGINA cheia (FE §7.7). Reaproveita o formulário do
    // modal rápido (§7.7b) via <x-gasto.form>. Criar usa os endpoints do modal
    // (gastos.previa/store); editar tem os seus (prévia + update), que regeneram as
    // parcelas pelo domínio (EditarGastoManual) e travam se houver parcela paga (regra 7).
    //
    // {transaction} chega SEMPRE criptografado (token opaco — README §"Identificadores nas
    // URLs"): o Route::bind (AppServiceProvider) decodifica → id, e 404 para token
    // inválido/id em claro. Por isso NÃO há mais whereNumber aqui.
    Route::get('/lancamentos/novo', [LancamentoFormController::class, 'create'])->name('lancamentos.create');
    Route::get('/lancamentos/{transaction}/editar', [LancamentoFormController::class, 'edit'])->name('lancamentos.edit');
    Route::post('/lancamentos/{transaction}/previa', [LancamentoFormController::class, 'previa'])->name('lancamentos.previa');
    Route::put('/lancamentos/{transaction}', [LancamentoFormController::class, 'update'])->name('lancamentos.update');

    // Marcar UMA parcela como paga (FE §7.8, fora de cartão). POST server-rendered da tela
    // de detalhe: grava status 'pago' + data na parcela alvo (RegistrarPagamentoParcela),
    // sem tocar nas irmãs. {parcela} opaco; escopo por usuário no domínio.
    Route::post('/lancamentos/parcela/{parcela}/pagar', [LancamentoController::class, 'pagarParcela'])->name('lancamentos.parcela.pagar');

    // Detalhe de um lançamento (FE §7.8): metadados + parcelas com status derivado por data
    // (ConsultarLancamentoDetalhe). Leitura apenas; a UI nunca calcula (regra 4). A edição
    // acontece por modal na própria tela (?editar=1 abre já aberto). {transaction} opaco.
    Route::get('/lancamentos/{transaction}', [LancamentoController::class, 'show'])->name('lancamentos.show');

    // Cadastro de gasto manual (modal §7.7b). Dois passos (regra 7): a prévia
    // calcula sem gravar; o store persiste após a confirmação.
    Route::post('/gastos/previa', [GastoController::class, 'previa'])->name('gastos.previa');
    Route::post('/gastos', [GastoController::class, 'store'])->name('gastos.store');

    // Chat financeiro (spec §7.14). Reusa o motor do Telegram (ResponderConsulta):
    // index devolve o histórico do próprio usuário; store envia a pergunta (ou um PDF,
    // validado por MIME real e descartado — regra 6) e devolve a resposta com fontes.
    Route::get('/chat/mensagens', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat/mensagens', [ChatController::class, 'store'])->name('chat.store');

    // Onboarding + consentimento LGPD. O usuário chega aqui já autenticado
    // (logo após criar a conta); persistir o aceite (aceite_lgpd_em) é backend.
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.consent');

    // Vínculo com o Telegram (doc 06 §1). show exibe o código de uso único;
    // gerar emite um novo; desconectar revoga o vínculo ativo. O consumo do
    // token e a ativação acontecem pelo bot (FluxoDeVinculo).
    Route::get('/telegram', [TelegramLinkController::class, 'show'])->name('telegram');
    Route::get('/telegram/status', [TelegramLinkController::class, 'status'])->name('telegram.status');
    Route::post('/telegram/gerar', [TelegramLinkController::class, 'gerar'])->name('telegram.gerar');
    Route::post('/telegram/desconectar', [TelegramLinkController::class, 'desconectar'])->name('telegram.desconectar');

    Route::post('/logout', LogoutController::class)->name('logout');
});

// -------------------------------------------------------------------------
// Autenticação (backend real). Rotas 'guest': quem já está logado não vê login
// nem cadastro (é redirecionado). Login/registro validam na borda (Form
// Request), autenticam pelo guard de sessão e regeneram a sessão (anti
// fixation). A apresentação (telas) é etapa separada.
// -------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.attempt');

    Route::get('/criar-conta', [RegisterController::class, 'create'])->name('register');
    Route::post('/criar-conta', [RegisterController::class, 'store'])->name('register.attempt');
});

// -------------------------------------------------------------------------
// Documentos legais (apresentação). Públicos e indexáveis. Conteúdo estático
// (LGPD/doc 09): Termos de Uso e Política de Privacidade, linkados no aceite
// da tela de cadastro e no consentimento do onboarding.
// -------------------------------------------------------------------------
Route::view('/termos', 'legal.termos')->name('terms');
Route::view('/politica-de-privacidade', 'legal.privacidade')->name('privacy');

// -------------------------------------------------------------------------
// SEO técnico (só rotas públicas). Servidos dinamicamente para que a URL
// absoluta acompanhe o ambiente (APP_URL: dev vs. produção). O webhook do
// Telegram e o onboarding (consentimento por usuário) ficam fora do índice.
// -------------------------------------------------------------------------
Route::get('/robots.txt', function () {
    $linhas = [
        'User-agent: *',
        'Disallow: /onboarding',
        'Disallow: /telegram',
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
