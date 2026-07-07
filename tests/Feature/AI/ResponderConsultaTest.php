<?php

use App\Ai\Agents\AssistenteDeConsulta;
use App\Ai\Tools\ConsultarDisponivelMes;
use App\Ai\Tools\ConsultarGastos;
use App\Domain\IA\Consulta\ColetorDeConsultas;
use App\Domain\IA\Consulta\ResponderConsulta;
use App\Domain\IA\Consulta\RespostaDaConsulta;
use App\Domain\IA\Guard\GuardPosGeracao;
use App\Models\Income;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

/*
 * Orquestração do chat de consulta (Bloco 6, F8). O agente decide chamar as tools (escopo
 * por usuário); a SDK as executa e elas registram o conjunto-verdade no coletor; o guard
 * pós-geração (barreira 4) valida que TODO número/data do texto existe nesse conjunto.
 * Divergência → regenera; esgotou → fallback sem números. Determinístico via fakes da SDK.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Gasto de parcela única vencendo na data dada (alimenta consultar_gastos). */
function gastoConsulta(User $user, int $valorCents, string $vencimento): void
{
    $transaction = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
    ]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

/** Atalho: ToolCall fake de consultar_gastos para um período. */
function chamarGastos(string $id, string $periodo = '2026-06'): ToolCall
{
    return new ToolCall($id, 'ConsultarGastos', ['periodo' => $periodo]);
}

it('chama a ferramenta, redige só com números do payload e aprova', function () {
    $user = User::factory()->create();
    gastoConsulta($user, 150000, '2026-06-10');

    Ai::fakeAgent(AssistenteDeConsulta::class, [
        chamarGastos('1'),
        'Você gastou R$ 1.500,00 em junho.',
    ]);

    $resposta = app(ResponderConsulta::class)->responder($user, 'quanto gastei em junho?');

    expect($resposta)->toBeInstanceOf(RespostaDaConsulta::class)
        ->and($resposta->aprovado)->toBeTrue()
        ->and($resposta->texto)->toContain('R$ 1.500,00')
        ->and($resposta->tentativas)->toBe(1)
        ->and($resposta->fontes)->toHaveCount(1)
        ->and($resposta->fontes[0]->ferramenta)->toBe('consultar_gastos');
});

it('o guard aprova o texto contra o conjunto-verdade COMBINADO de várias tools (C7)', function () {
    // O fake da SDK executa no máximo UMA tool por turno do agente, então a combinação
    // de múltiplas tools é exercitada no seu seam real: duas tools reais, um único coletor e
    // o GuardPosGeracao real sobre o payload COMBINADO — exatamente o que ResponderConsulta
    // usa em produção (responder() valida contra coletor->payloadCombinado()).
    $user = User::factory()->create();

    // Tool A — gastos de MAIO: R$ 800,00 (valor que SÓ existe no payload de gastos).
    gastoConsulta($user, 80000, '2026-05-10');

    // Tool B — disponível de JUNHO: receitas 4000 − gastos 1500 = R$ 2.500,00
    // (valor que SÓ existe no payload do disponível, nunca no de gastos de maio).
    gastoConsulta($user, 150000, '2026-06-10');
    Income::factory()->for($user)->create(['valor_cents' => 400000, 'data' => '2026-06-05']);

    $coletor = new ColetorDeConsultas;
    (new ConsultarGastos($user, $coletor))->handle(new Request(['periodo' => '2026-05']));
    (new ConsultarDisponivelMes($user, $coletor))->handle(new Request(['mes' => '2026-06']));

    $texto = 'Em maio você gastou R$ 800,00 e em junho seu disponível é R$ 2.500,00.';
    $guard = app(GuardPosGeracao::class);

    // Aprovado SÓ porque o conjunto-verdade é a UNIÃO das duas tools.
    expect($guard->validar($texto, $coletor->payloadCombinado())->aprovado)->toBeTrue()
        ->and($coletor->fontes())->toHaveCount(2);

    $ferramentas = collect($coletor->fontes())->map(fn ($f) => $f->ferramenta)->all();
    expect($ferramentas)->toContain('consultar_gastos')->toContain('consultar_disponivel_mes');

    // Prova de que a combinação é necessária: contra o payload de UMA tool só, o texto reprova
    // — o R$ 2.500,00 da outra tool vira "número inventado".
    $coletorSoGastos = new ColetorDeConsultas;
    (new ConsultarGastos($user, $coletorSoGastos))->handle(new Request(['periodo' => '2026-05']));
    expect($guard->validar($texto, $coletorSoGastos->payloadCombinado())->aprovado)->toBeFalse();
});

it('encaminha a pergunta do usuário, íntegra, ao agente', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, ['Tudo certo por aqui.']);

    app(ResponderConsulta::class)->responder($user, 'quanto gastei em junho?');

    Ai::assertAgentWasPrompted(
        AssistenteDeConsulta::class,
        fn ($prompt) => $prompt->prompt === 'quanto gastei em junho?',
    );
});

it('bloqueia número fora do payload e cai no fallback após esgotar as tentativas', function () {
    $user = User::factory()->create();
    gastoConsulta($user, 150000, '2026-06-10');

    Ai::fakeAgent(AssistenteDeConsulta::class, [
        chamarGastos('1'),
        'Você gastou R$ 9.999,99 em junho.',
        chamarGastos('2'),
        'Você gastou R$ 9.999,99 em junho.',
    ]);

    $resposta = app(ResponderConsulta::class)->responder($user, 'quanto gastei?');

    expect($resposta->aprovado)->toBeFalse()
        ->and($resposta->texto)->not->toContain('9.999')
        ->and($resposta->tentativas)->toBe(2);
});

it('regenera: descarta a resposta divergente e devolve a confiável', function () {
    $user = User::factory()->create();
    gastoConsulta($user, 150000, '2026-06-10');

    Ai::fakeAgent(AssistenteDeConsulta::class, [
        chamarGastos('1'),
        'Total inventado de R$ 9.999,99.',
        chamarGastos('2'),
        'Você gastou R$ 1.500,00 em junho.',
    ]);

    $resposta = app(ResponderConsulta::class)->responder($user, 'quanto gastei?');

    expect($resposta->aprovado)->toBeTrue()
        ->and($resposta->texto)->toContain('R$ 1.500,00')
        ->and($resposta->texto)->not->toContain('9.999')
        ->and($resposta->tentativas)->toBe(2);
});

it('aprova resposta sem números mesmo quando nenhuma ferramenta é chamada', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, ['Olá! Posso ajudar com suas finanças.']);

    $resposta = app(ResponderConsulta::class)->responder($user, 'oi');

    expect($resposta->aprovado)->toBeTrue()
        ->and($resposta->texto)->toContain('Olá')
        ->and($resposta->fontes)->toBe([]);
});

it('bloqueia número inventado quando nenhuma ferramenta foi chamada (payload vazio)', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, [
        'Você tem R$ 5,00 disponível.',
        'Você tem R$ 5,00 disponível.',
    ]);

    $resposta = app(ResponderConsulta::class)->responder($user, 'quanto tenho disponível?');

    expect($resposta->aprovado)->toBeFalse()
        ->and($resposta->tentativas)->toBe(2);
});

it('cai no fallback seguro quando o provedor lança exceção em todas as tentativas', function () {
    $user = User::factory()->create();
    gastoConsulta($user, 150000, '2026-06-10');

    // Todos os provedores indisponíveis: o failover da SDK esgota e a exceção escapa.
    Ai::fakeAgent(AssistenteDeConsulta::class, [
        fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout'),
        fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout'),
    ]);

    $resposta = app(ResponderConsulta::class)->responder($user, 'quanto gastei?');

    expect($resposta->aprovado)->toBeFalse()
        ->and($resposta->texto)->not->toContain('R$')
        ->and($resposta->tentativas)->toBe(2);
});

it('expõe as 4 ferramentas de consulta, amarradas ao usuário e ao coletor', function () {
    $agente = new AssistenteDeConsulta(User::factory()->create(), new ColetorDeConsultas);

    $nomes = collect(iterator_to_array($agente->tools()))
        ->map(fn ($tool) => class_basename($tool))
        ->all();

    expect($nomes)->toHaveCount(4)
        ->and($nomes)->toContain('ConsultarGastos')
        ->and($nomes)->toContain('ConsultarDisponivelMes')
        ->and($nomes)->toContain('ConsultarProximasContas')
        ->and($nomes)->toContain('ConsultarFaturaCartao');
});
