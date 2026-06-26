<?php

namespace App\Providers;

use App\Domain\Telegram\ClassificadorDeComando;
use App\Domain\Telegram\ManipuladorInerte;
use App\Domain\Telegram\RoteadorDeComandos;
use App\Domain\Telegram\RoteadorDeMensagem;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
