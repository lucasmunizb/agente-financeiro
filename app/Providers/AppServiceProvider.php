<?php

namespace App\Providers;

use App\Domain\IA\Custo\CalculadoraDeCustoIA;
use App\Domain\IA\Intencao;
use App\Domain\IA\Rotacao\RotacionadorDeProvedores;
use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Domain\Importacao\ExtratorDeTexto;
use App\Domain\Importacao\ExtratorDeTextoPoppler;
use App\Domain\Importacao\OcrFallback;
use App\Domain\Importacao\OcrTesseract;
use App\Domain\Importacao\ParserDeFatura;
use App\Domain\Importacao\ParserItau;
use App\Domain\Shared\OpaqueId;
use App\Domain\Telegram\ClassificadorDeComando;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\FluxoDeVinculo;
use App\Domain\Telegram\ManipuladorInerte;
use App\Domain\Telegram\ManipuladorQueEnfileira;
use App\Domain\Telegram\Resposta\RespostaAoUsuario;
use App\Domain\Telegram\Resposta\RespostaTelegram;
use App\Domain\Telegram\RoteadorDeComandos;
use App\Domain\Telegram\RoteadorDeMensagem;
use App\Domain\Telegram\Saida\ClienteTelegram;
use App\Domain\Telegram\Saida\ClienteTelegramHttp;
use App\Listeners\LogarFailoverDeIA;
use App\Listeners\PenalizarProvedorNaRotacao;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Roteador determinístico de comandos. Os slashes que já fixam a intenção e o
        // texto livre são ligados aos Blocos 4/5 via ManipuladorQueEnfileira (enfileira o
        // processamento no worker — barreira §4). Editar/cancelar/ajuda seguem inertes
        // (padrão) até ganharem extrator de IA / frontend próprios.
        $this->app->bind(RoteadorDeMensagem::class, function ($app) {
            return new RoteadorDeComandos(
                $app->make(ClassificadorDeComando::class),
                $app->make(ManipuladorInerte::class),
                manipuladores: [
                    Comando::REGISTRAR->value => new ManipuladorQueEnfileira(Intencao::REGISTRAR),
                    Comando::BUSCAR->value => new ManipuladorQueEnfileira(Intencao::CONSULTAR),
                    Comando::DESCONHECIDO->value => new ManipuladorQueEnfileira,
                ],
                // Não vinculado: fluxo de vínculo via bot (token + request_contact).
                vinculo: $app->make(FluxoDeVinculo::class),
            );
        });

        // Porta de saída do bot: entrega ao Telegram o resultado já calculado (redação
        // determinística via RedatorDoChat + envio ao chat do vínculo ativo). A RespostaInerte
        // permanece para os testes que só inspecionam o resultado de domínio.
        $this->app->bind(RespostaAoUsuario::class, RespostaTelegram::class);

        // Cliente de saída da Bot API do Telegram (envio de mensagens + pedido de
        // contato no vínculo). Nos testes é trocado pelo ClienteTelegramFake.
        $this->app->bind(ClienteTelegram::class, function ($app) {
            return new ClienteTelegramHttp((string) $app['config']->get('services.telegram.bot_token'));
        });

        // Calculadora de custo de IA carregada com a tabela de preços (centavos/Mtok).
        $this->app->bind(CalculadoraDeCustoIA::class, function ($app) {
            return new CalculadoraDeCustoIA($app['config']->get('ai_custos.tabela', []));
        });

        // Importação de fatura (spec 07): extração/OCR via binários do worker (poppler +
        // Tesseract); parser do Itaú (a regra de identificar os lançamentos está pendente).
        $this->app->bind(ExtratorDeTexto::class, ExtratorDeTextoPoppler::class);
        $this->app->bind(OcrFallback::class, OcrTesseract::class);
        $this->app->bind(ParserDeFatura::class, ParserItau::class);

        // Relógio injetável para TTL determinístico (rotação de IA e afins).
        $this->app->bind(Clock::class, SystemClock::class);

        // Rotação de provedores de IA (spec 04c): fila LRU + cooldown com estado no cache
        // store compartilhado (app↔worker), sob Cache::lock. Singleton por request — o
        // estado real vive no cache, não em memória do processo.
        $this->app->singleton(RotacionadorDeProvedores::class, function ($app) {
            $config = (array) $app['config']->get('ai.rotacao', []);

            return new RotacionadorDeProvedores(
                Cache::store($config['store'] ?? $app['config']->get('cache.default')),
                $app->make(Clock::class),
                $config,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registra a indisponibilidade de provedor de IA (failover nativo da SDK).
        Event::listen(AgentFailedOver::class, LogarFailoverDeIA::class);

        // Bencha na rotação (spec 04c) o provedor que caiu — complementa o log acima, só
        // age com a rotação ligada (retrocompatível quando desligada).
        Event::listen(AgentFailedOver::class, PenalizarProvedorNaRotacao::class);

        // Parâmetro {transaction} das rotas chega SEMPRE criptografado (requisito
        // inegociável — README §"Identificadores nas URLs"). Decodifica o token opaco de
        // volta no id inteiro; token forjado ou id em claro (`/lancamentos/123`) → 404. O
        // escopo por usuário continua no controller/domínio (findOrFail por user_id).
        Route::bind('transaction', fn (string $token): int => OpaqueId::decode($token) ?? abort(404));

        // Idem para {pendente} (pending_confirmation) — confirmar/rejeitar na fila (FE §7.9).
        // O escopo por usuário é aplicado no domínio (findOrFail por user_id).
        Route::bind('pendente', fn (string $token): int => OpaqueId::decode($token) ?? abort(404));

        // Idem para {parcela} (installment) — marcar como paga (FE §7.8). O escopo por
        // usuário fica no domínio (findOrFail via whereHas user_id).
        Route::bind('parcela', fn (string $token): int => OpaqueId::decode($token) ?? abort(404));

        // Chat financeiro (3ª zona do shell, spec §7.14): injeta o histórico REAL do
        // próprio usuário na coluna de chat, sempre isolado por user_id (escopo estrito).
        View::composer('components.chat.panel', function ($view) {
            $view->with('mensagens', auth()->check()
                ? ChatMessage::query()
                    ->where('user_id', auth()->id())
                    ->orderBy('created_at')->orderBy('id')
                    ->get()
                : collect());
        });
    }
}
