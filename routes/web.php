<?php

use App\Http\Controllers\AtualizacoesController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CartaoController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConfiguracoesController;
use App\Http\Controllers\ConfirmacaoPendenteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\LancamentoController;
use App\Http\Controllers\LancamentoFormController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\ReceitaController;
use App\Http\Controllers\RecorrenciaController;
use App\Http\Controllers\TelegramLinkController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\ExigeConsentimentoLgpd;
use App\Http\Middleware\VerificaSegredoTelegram;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// Onboarding + logout: exigem login, mas NÃO o consentimento (é aqui que ele
// é dado; logout precisa funcionar sempre).
// -------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    // Onboarding + consentimento LGPD. O usuário chega aqui já autenticado
    // (logo após criar a conta); persistir o aceite (aceite_lgpd_em) é backend.
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.consent');

    Route::post('/logout', LogoutController::class)->name('logout');
});

// -------------------------------------------------------------------------
// App (exige login E consentimento LGPD — auditoria P1-7). Todo o app fica
// atrás de autenticação de sessão: quem não estiver logado é redirecionado
// automaticamente para /login (padrão do Laravel → route('login')); quem não
// consentiu ainda vai para o onboarding. Novas rotas entram neste grupo.
// -------------------------------------------------------------------------
Route::middleware(['auth', ExigeConsentimentoLgpd::class])->group(function () {
    // Dashboard ("Visão Geral") — destino do login. Apresentação com DADOS FAKE
    // (regra 3); a integração com o backend (spec-06) vem depois. O estado da tela
    // (pronto | vazio | carregando) hoje sai da query (?estado=…) só para revisar
    // as três telas; quando o backend existir, o estado passa a vir dos dados reais.
    // Dashboard "Visão Geral" (spec 06). Compõe os números já calculados pelo
    // domínio e apenas formata para a tela; carrega também cartões/categorias do
    // usuário para o modal "Registrar gasto".
    Route::get('/', [DashboardController::class, 'index'])->name('home');

    // Assinatura de atualizações (polling): dashboard e extrato consultam para
    // saber se a tela ficou desatualizada por uma confirmação vinda de outro canal
    // (ex.: o chat do Telegram) e então recarregam. Devolve só um hash opaco do
    // estado do usuário (regra 4 — nenhum número calculado sai por aqui).
    // throttle (pentest 2026-07 L5): o polling é chamado em loop por cada aba aberta e
    // faz query de estado; sem teto, um usuário autenticado martela a rota sem limite.
    Route::get('/atualizacoes', [AtualizacoesController::class, 'assinatura'])
        ->middleware('throttle:60,1')
        ->name('atualizacoes');

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

    // Marcar como paga uma OCORRÊNCIA de recorrência (spec 12): muda o status da própria
    // ocorrência (PagarOcorrencia) — não materializa lançamento algum. Só fora de cartão
    // (cartão liquida sozinho, D3). Idempotente. {ocorrencia} opaco; escopo no domínio.
    Route::post('/lancamentos/recorrencia/{ocorrencia}/pagar', [LancamentoController::class, 'pagarRecorrencia'])->name('lancamentos.recorrencia.pagar');

    // Cancelar "esta e as próximas" (FE §7.8): marca o lançamento e as parcelas ainda não
    // finalizadas como 'cancelado', preservando as já pagas (CancelarGastoManual). Mantém a
    // linha (histórico). {transaction} opaco; escopo por usuário no domínio.
    Route::post('/lancamentos/{transaction}/cancelar', [LancamentoController::class, 'cancelar'])->name('lancamentos.cancelar');

    // Detalhe de um lançamento (FE §7.8): metadados + parcelas com status derivado por data
    // (ConsultarLancamentoDetalhe). Leitura apenas; a UI nunca calcula (regra 4). A edição
    // acontece por modal na própria tela (?editar=1 abre já aberto). {transaction} opaco.
    Route::get('/lancamentos/{transaction}', [LancamentoController::class, 'show'])->name('lancamentos.show');

    // Cadastro de gasto manual (modal §7.7b). Dois passos (regra 7): a prévia
    // calcula sem gravar; o store persiste após a confirmação.
    Route::post('/gastos/previa', [GastoController::class, 'previa'])->name('gastos.previa');
    Route::post('/gastos', [GastoController::class, 'store'])->name('gastos.store');

    // Fila de confirmações pendentes (FE §7.9): base comum que recorrência e importação de PDF
    // alimentam. Confirmar grava (RegistrarGastoManual); rejeitar descarta — nada sem o "sim"
    // (regra 7). {pendente} opaco; escopo por usuário no domínio.
    Route::get('/confirmacoes', [ConfirmacaoPendenteController::class, 'index'])->name('confirmacoes');
    Route::post('/confirmacoes/{pendente}/confirmar', [ConfirmacaoPendenteController::class, 'confirmar'])->name('confirmacoes.confirmar');
    Route::post('/confirmacoes/{pendente}/rejeitar', [ConfirmacaoPendenteController::class, 'rejeitar'])->name('confirmacoes.rejeitar');

    // Receitas (FE §7.10): listar (por competência + filtro de tipo) com o total já somado pelo
    // domínio (ReceitasDoMes) e adicionar em dois passos (regra 7 — o store sem `confirmado`
    // mostra o resumo; com `confirmado` grava via RegistrarReceita).
    Route::get('/receitas', [ReceitaController::class, 'index'])->name('receitas');
    Route::post('/receitas', [ReceitaController::class, 'store'])->name('receitas.store');
    // Editar e excluir (cancelamento lógico — soft delete). {receita} opaco; escopo no domínio.
    Route::put('/receitas/{receita}', [ReceitaController::class, 'update'])->name('receitas.update');
    Route::delete('/receitas/{receita}', [ReceitaController::class, 'destroy'])->name('receitas.destroy');

    // Cartões & faturas (FE §7.13): listar cartões, ver a fatura do selecionado por competência
    // (total/extrato de ConsultarFaturaCartao + ciclo de CicloDaFatura) e adicionar cartão
    // (CriarCartao). Cartão selecionado por token opaco (?cartao=); mês em claro (?mes=).
    Route::get('/cartoes', [CartaoController::class, 'index'])->name('cartoes');
    Route::post('/cartoes', [CartaoController::class, 'store'])->name('cartoes.store');
    // Editar e remover (cancelamento lógico — soft delete). {cartao} opaco; escopo no domínio.
    Route::put('/cartoes/{cartao}', [CartaoController::class, 'update'])->name('cartoes.update');
    Route::delete('/cartoes/{cartao}', [CartaoController::class, 'destroy'])->name('cartoes.destroy');

    // Orçamento do mês (FE §7.11): ver limite/consumo (por competência) e definir o limite
    // geral (DefinirOrcamento, updateOrCreate). Leitura já avaliada pelo domínio; a UI não
    // calcula (regra 4). Mês em claro na URL (?mes=YYYY-MM), não é id.
    Route::get('/orcamento', [OrcamentoController::class, 'index'])->name('orcamento');
    Route::post('/orcamento', [OrcamentoController::class, 'definir'])->name('orcamento.definir');

    // Categorias (FE §7.12): listar com a contagem de uso já calculada (ListarCategorias; a UI
    // nunca calcula, regra 4) e criar/editar/arquivar (CriarCategoria/EditarCategoria/
    // ArquivarCategoria). Arquivar é lógico — não apaga o histórico. {categoria} opaco; escopo
    // no domínio. Palavras-chave e apelidos alimentam o lookup determinístico (doc 08).
    Route::get('/categorias', [CategoriaController::class, 'index'])->name('categorias');
    Route::post('/categorias', [CategoriaController::class, 'store'])->name('categorias.store');
    Route::put('/categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
    Route::post('/categorias/{categoria}/arquivar', [CategoriaController::class, 'arquivar'])->name('categorias.arquivar');

    // Gerenciar recorrências (spec 10): listar as ativas e cancelar. Cancelar reusa o domínio
    // (CancelarRecorrencia); {recorrencia} opaco; escopo por usuário no domínio (404 alheio).
    Route::get('/recorrencias', [RecorrenciaController::class, 'index'])->name('recorrencias');
    Route::post('/recorrencias/{recorrencia}/cancelar', [RecorrenciaController::class, 'cancelar'])->name('recorrencias.cancelar');

    // Chat financeiro (spec §7.14). Reusa o motor do Telegram (ResponderConsulta):
    // index devolve o histórico do próprio usuário; store envia a pergunta (ou um PDF,
    // validado por MIME real e descartado — regra 6) e devolve a resposta com fontes.
    Route::get('/chat/mensagens', [ChatController::class, 'index'])->name('chat.index');
    // throttle:ia-web (auditoria P1-2): teto por usuário — cada mensagem pode custar
    // chamadas de IA; sem limite, um loop esgota as cotas dos provedores.
    Route::post('/chat/mensagens', [ChatController::class, 'store'])
        ->middleware('throttle:ia-web')
        ->name('chat.store');

    // Vínculo com o Telegram (doc 06 §1). show exibe o código de uso único;
    // gerar emite um novo; desconectar revoga o vínculo ativo. O consumo do
    // token e a ativação acontecem pelo bot (FluxoDeVinculo).
    Route::get('/telegram', [TelegramLinkController::class, 'show'])->name('telegram');
    Route::get('/telegram/status', [TelegramLinkController::class, 'status'])->name('telegram.status');
    Route::post('/telegram/gerar', [TelegramLinkController::class, 'gerar'])->name('telegram.gerar');
    Route::post('/telegram/desconectar', [TelegramLinkController::class, 'desconectar'])->name('telegram.desconectar');

    Route::get('/configuracoes', [ConfiguracoesController::class, 'show'])->name('configuracoes');
    Route::put('/configuracoes/perfil', [ConfiguracoesController::class, 'atualizarPerfil'])->name('configuracoes.perfil');
    Route::put('/configuracoes/senha', [ConfiguracoesController::class, 'alterarSenha'])->name('configuracoes.senha');
    Route::get('/configuracoes/exportar', [ConfiguracoesController::class, 'exportar'])->name('configuracoes.exportar');
    Route::delete('/configuracoes/conta', [ConfiguracoesController::class, 'excluirConta'])->name('configuracoes.excluir');
});

// -------------------------------------------------------------------------
// Autenticação (backend real). Rotas 'guest': quem já está logado não vê login
// nem cadastro (é redirecionado). Login/registro validam na borda (Form
// Request), autenticam pelo guard de sessão e regeneram a sessão (anti
// fixation). A apresentação (telas) é etapa separada.
// -------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    // throttle:auth (P3-8): limite POR IP contra password spraying — complementa o
    // throttle por (email, IP) do LoginRequest.
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('login.attempt');

    Route::get('/criar-conta', [RegisterController::class, 'create'])->name('register');
    // throttle:auth (P3-7): dificulta enumeração de contas pela mensagem de e-mail já usado.
    Route::post('/criar-conta', [RegisterController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('register.attempt');
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
