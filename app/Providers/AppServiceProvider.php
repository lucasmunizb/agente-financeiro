<?php

namespace App\Providers;

use App\Domain\IA\Custo\CalculadoraDeCustoIA;
use App\Domain\Telegram\ClassificadorDeComando;
use App\Domain\Telegram\ManipuladorInerte;
use App\Domain\Telegram\RoteadorDeComandos;
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
        // Roteador determinístico de comandos. Cada intenção ainda usa o manipulador
        // inerte (padrão); os concretos (execução + IA no Bloco 4, mensagens do bot no
        // frontend) substituem o mapa abaixo nas etapas posteriores.
        $this->app->bind(RoteadorDeMensagem::class, function ($app) {
            return new RoteadorDeComandos(
                $app->make(ClassificadorDeComando::class),
                $app->make(ManipuladorInerte::class),
                manipuladores: [],
            );
        });

        // Calculadora de custo de IA carregada com a tabela de preços (centavos/Mtok).
        $this->app->bind(CalculadoraDeCustoIA::class, function ($app) {
            return new CalculadoraDeCustoIA($app['config']->get('ai_custos.tabela', []));
        });
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
