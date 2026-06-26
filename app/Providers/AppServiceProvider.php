<?php

namespace App\Providers;

use App\Domain\Telegram\RoteadorDeMensagem;
use App\Domain\Telegram\RoteadorInerte;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Roteador default inerte até o roteamento de comandos (TODO Bloco 3).
        $this->app->bind(RoteadorDeMensagem::class, RoteadorInerte::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
