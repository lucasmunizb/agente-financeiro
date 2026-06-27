<?php

declare(strict_types=1);

use App\Ai\Agents\AssistenteDeConsulta;
use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Ai\Agents\RedatorDeResposta;
use App\Domain\IA\Consulta\ColetorDeConsultas;
use App\Listeners\LogarFailoverDeIA;
use App\Models\User;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Illuminate\Support\Facades\Log;

/*
 * Failover de provedores (doc 02 §3.6 / regra inviolável 8): a indisponibilidade de um
 * provedor cai no próximo do array (recurso nativo da Laravel AI SDK). A lista vem da
 * config; cada agente a expõe via provider(). O evento de failover é logado SEM dado
 * sensível (instabilidade — doc 09).
 */

it('a config de failover é um array e inclui o provedor padrão', function () {
    expect(config('ai.failover'))->toBeArray()
        ->and(config('ai.failover'))->toContain('anthropic');
});

it('todos os agentes expõem a lista de failover da config em provider()', function () {
    $esperado = config('ai.failover');

    expect((new ClassificadorDeIntencao)->provider())->toBe($esperado)
        ->and((new ExtratorDeGasto)->provider())->toBe($esperado)
        ->and((new RedatorDeResposta)->provider())->toBe($esperado);

    $assistente = new AssistenteDeConsulta(User::factory()->make(['id' => 1]), new ColetorDeConsultas);
    expect($assistente->provider())->toBe($esperado);
});

it('loga o failover com provedor e modelo, sem dado sensível', function () {
    $provider = Ai::textProvider('anthropic');
    $excecao = new class('provedor fora do ar') extends RuntimeException implements FailoverableException {};
    $evento = new AgentFailedOver(new ClassificadorDeIntencao, $provider, 'claude-x', $excecao);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $mensagem, array $contexto) {
            return $contexto['provedor'] === 'anthropic'
                && $contexto['modelo'] === 'claude-x'
                && ! array_key_exists('mensagem_usuario', $contexto)
                && ! str_contains(json_encode($contexto), 'provedor fora do ar');
        });

    (new LogarFailoverDeIA)->handle($evento);
});
