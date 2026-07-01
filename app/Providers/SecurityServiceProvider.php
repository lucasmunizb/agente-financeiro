<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Endurecimento de transporte em produção — "https only", fail closed no código
 * (não depende de o ops lembrar de setar o env/secret):
 *
 *  - Força o esquema https em toda URL gerada (links, redirects, form actions).
 *  - Marca o cookie de sessão como Secure (só trafega sob HTTPS).
 *
 * Em dev (local/testing) o app roda em HTTP, então nada é forçado.
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        URL::forceScheme('https');

        config([
            'session.secure' => true,
            'session.http_only' => true,
        ]);
    }
}
