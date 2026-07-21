<?php

declare(strict_types=1);

use App\Domain\IA\Rotacao\RotacionadorDeProvedores;
use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
 * Rotação de provedores (spec 04c): fila LRU + cooldown, estado no cache compartilhado
 * (aqui, store 'array') sob Cache::lock. Determinístico: o "agora" vem de um Clock
 * injetado (SystemClock respeita Carbon::setTestNow) e o TTL de cooldown segue o relógio.
 * NUNCA chama provedor real — só reordena a lista que a SDK consome (regra 8).
 */

uses(TestCase::class);

/** Constrói o rotacionador amarrado ao store 'array', com o pool/cooldown do teste. */
function rotacionador(array $overrides = []): RotacionadorDeProvedores
{
    $config = array_merge([
        'enabled' => true,
        'pool' => ['groq', 'gemini', 'anthropic', 'openai'],
        'cooldown' => 60,
        'store' => 'array',
        'lock_ttl' => 5,
    ], $overrides);

    return new RotacionadorDeProvedores(Cache::store('array'), app(Clock::class), $config);
}

/** Cabeças sucessivas de N chamadas de ordenar() no MESMO rotacionador (avança a fila). */
function cabecas(RotacionadorDeProvedores $r, int $n): array
{
    return array_map(fn () => $r->ordenar()[0], range(1, $n));
}

beforeEach(function () {
    app()->bind(Clock::class, SystemClock::class);
    Cache::store('array')->clear();
    Carbon::setTestNow(CarbonImmutable::create(2026, 7, 9, 12, 0, 0, 'America/Sao_Paulo'));

    // Todos os provedores do pool com chave presente (elegíveis por padrão).
    foreach (['groq', 'gemini', 'anthropic', 'openai'] as $p) {
        config()->set("ai.providers.$p.key", "chave-$p");
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

it('C1: rotaciona a cabeça em fila FIFO e volta ao início no 5º pick', function () {
    $r = rotacionador();

    expect(cabecas($r, 5))->toBe(['groq', 'gemini', 'anthropic', 'openai', 'groq']);
});

it('C2: devolve a lista inteira de disponíveis, não só a cabeça', function () {
    $r = rotacionador();

    expect($r->ordenar())->toBe(['groq', 'gemini', 'anthropic', 'openai']);
});

it('C3: provedor em cooldown some dos disponíveis (nem cabeça nem cauda)', function () {
    $r = rotacionador();
    $r->penalizar('groq');

    $ordem = $r->ordenar();

    expect($ordem)->not->toContain('groq')
        ->and($ordem[0])->toBe('gemini');
});

it('C4: cooldown expira quando o relógio avança além do TTL', function () {
    $r = rotacionador(['cooldown' => 60]);
    $r->penalizar('groq');

    expect($r->emCooldown('groq'))->toBeTrue();

    Carbon::setTestNow(CarbonImmutable::create(2026, 7, 9, 12, 1, 1, 'America/Sao_Paulo'));

    expect($r->emCooldown('groq'))->toBeFalse()
        ->and($r->ordenar())->toContain('groq');
});

it('C5: provedor sem chave é omitido do pool', function () {
    config()->set('ai.providers.openai.key', null);
    $r = rotacionador();

    expect($r->ordenar())->toBe(['groq', 'gemini', 'anthropic'])
        ->and($r->ordenar())->not->toContain('openai');
});

it('C6: todos em cooldown → devolve o pool completo (com chave) e loga warning', function () {
    Log::spy();
    $r = rotacionador();
    foreach (['groq', 'gemini', 'anthropic', 'openai'] as $p) {
        $r->penalizar($p);
    }

    expect($r->ordenar())->toBe(['groq', 'gemini', 'anthropic', 'openai']);

    Log::shouldHaveReceived('warning')->once();
});

it('penalizar grava cooldown pelo TTL configurado e emCooldown reflete o clock', function () {
    $r = rotacionador(['cooldown' => 30]);

    expect($r->emCooldown('gemini'))->toBeFalse();

    $r->penalizar('gemini', 'RuntimeException');

    expect($r->emCooldown('gemini'))->toBeTrue();

    Carbon::setTestNow(CarbonImmutable::create(2026, 7, 9, 12, 0, 31, 'America/Sao_Paulo'));

    expect($r->emCooldown('gemini'))->toBeFalse();
});

it('avança exatamente uma posição por chamada (fila persistida no store)', function () {
    $r = rotacionador();

    expect($r->ordenar()[0])->toBe('groq');
    expect($r->ordenar()[0])->toBe('gemini');
    expect(Cache::store('array')->get('ai:rotacao:fila'))
        ->toBe(['anthropic', 'openai', 'groq', 'gemini']);
});
