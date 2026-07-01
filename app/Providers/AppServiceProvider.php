<?php

namespace App\Providers;

use App\Domain\IA\Custo\CalculadoraDeCustoIA;
use App\Domain\IA\Intencao;
use App\Domain\Importacao\ExtratorDeTexto;
use App\Domain\Importacao\ExtratorDeTextoPoppler;
use App\Domain\Importacao\OcrFallback;
use App\Domain\Importacao\OcrTesseract;
use App\Domain\Importacao\ParserDeFatura;
use App\Domain\Importacao\ParserItau;
use App\Domain\Telegram\ClassificadorDeComando;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\FluxoDeVinculo;
use App\Domain\Telegram\ManipuladorInerte;
use App\Domain\Telegram\ManipuladorQueEnfileira;
use App\Domain\Telegram\Resposta\RespostaAoUsuario;
use App\Domain\Telegram\Resposta\RespostaInerte;
use App\Domain\Telegram\RoteadorDeComandos;
use App\Domain\Telegram\Saida\ClienteTelegram;
use App\Domain\Telegram\Saida\ClienteTelegramHttp;
use App\Domain\Telegram\RoteadorDeMensagem;
use App\Listeners\LogarFailoverDeIA;
use Illuminate\Support\Facades\Event;
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

        // Porta de saída do bot: inerte por ora — a redação/envio das mensagens ao
        // Telegram é frontend (regra 3), etapa separada e posterior.
        $this->app->bind(RespostaAoUsuario::class, RespostaInerte::class);

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registra a indisponibilidade de provedor de IA (failover nativo da SDK).
        Event::listen(AgentFailedOver::class, LogarFailoverDeIA::class);
    }
}
