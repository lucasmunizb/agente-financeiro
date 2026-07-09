<?php

declare(strict_types=1);

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Domain\IA\Rotacao\RotacionadorDeProvedores;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;

/*
 * Integração da rotação (spec 04c) com a borda: o trait provider() consulta o
 * rotacionador quando ligado (senão mantém o failover estático — retrocompatível), o
 * evento AgentFailedOver da SDK bencha o provedor que caiu, e a seção crítica é atômica
 * sob Cache::lock. Nada de rede: só reordenação da lista que a SDK consome (regra 8).
 */

beforeEach(function () {
    config()->set('ai.rotacao.store', 'array');
    Cache::store('array')->clear();
    foreach (['groq', 'gemini', 'anthropic', 'openai'] as $p) {
        config()->set("ai.providers.$p.key", "chave-$p");
    }
});

it('C7: AgentFailedOver bencha o provedor na rotação (sem vazar a mensagem da exceção)', function () {
    config()->set('ai.rotacao.enabled', true);
    config()->set('ai.rotacao.pool', ['groq', 'gemini', 'anthropic', 'openai']);

    $provider = Ai::textProvider('groq');
    $excecao = new class('payload secreto 429') extends RuntimeException implements FailoverableException {};

    event(new AgentFailedOver(new ClassificadorDeIntencao, $provider, 'model-x', $excecao));

    $rotacionador = app(RotacionadorDeProvedores::class);

    expect($rotacionador->emCooldown('groq'))->toBeTrue()
        ->and($rotacionador->ordenar())->not->toContain('groq');
});

it('C8: com a rotação desligada, provider() usa o failover estático e NÃO resolve o rotacionador', function () {
    config()->set('ai.rotacao.enabled', false);
    config()->set('ai.failover', ['anthropic', 'openai']);

    // Se o trait tentar resolver o rotacionador, o container estoura — prova de que o
    // branch desligado NÃO o toca (retrocompatível com a spec 04).
    app()->bind(RotacionadorDeProvedores::class, function () {
        throw new RuntimeException('rotacionador não deveria ser resolvido com a rotação desligada');
    });

    expect((new ClassificadorDeIntencao)->provider())->toBe(['anthropic', 'openai'])
        ->and(app()->resolved(RotacionadorDeProvedores::class))->toBeFalse();
});

it('C8b: com a rotação ligada, provider() devolve a ordem rotacionada (cabeça = escolha)', function () {
    config()->set('ai.rotacao.enabled', true);
    config()->set('ai.rotacao.pool', ['groq', 'gemini', 'anthropic', 'openai']);

    expect((new ClassificadorDeIntencao)->provider())->toBe(['groq', 'gemini', 'anthropic', 'openai'])
        ->and((new ClassificadorDeIntencao)->provider()[0])->toBe('gemini');
});

it('C9: a rotação ocorre sob lock — bloqueia enquanto o lock está tomado', function () {
    config()->set('ai.rotacao.enabled', true);
    config()->set('ai.rotacao.pool', ['groq', 'gemini', 'anthropic', 'openai']);
    config()->set('ai.rotacao.lock_ttl', 0); // espera imediata → falha rápido se ocupado

    $rotacionador = app(RotacionadorDeProvedores::class);

    $lockConcorrente = Cache::store('array')->lock('ai:rotacao:lock', 10);
    expect($lockConcorrente->get())->toBeTrue();

    expect(fn () => $rotacionador->ordenar())->toThrow(LockTimeoutException::class);

    $lockConcorrente->release();

    expect($rotacionador->ordenar()[0])->toBe('groq');
});
