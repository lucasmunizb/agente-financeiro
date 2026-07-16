<?php

declare(strict_types=1);

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Domain\Interacao\ProcessarInteracao;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

/*
 * Recorrência via bot/chat (spec 10c) — ponta a ponta pelo ProcessarInteracao, o núcleo
 * compartilhado entre Telegram e chat web.
 *
 * Nasce do incidente de produção de 2026-07-16: "registra uma recorrencia no pix para pagar
 * todo dia 10 520 reias ingles carol categoria estudos" respondeu "me diga também a data e a
 * forma de pagamento" — dois campos que a mensagem continha. A recorrência simplesmente não
 * existia no pipeline de IA: "todo dia 10" caía no campo `data` e não parseava.
 *
 * Regra 7 continua valendo: nada é gravado sem o "sim". Regra 4: a IA devolve o dia como
 * TEXTO cru; quem valida e resolve `proxima_em` é o domínio.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-16 12:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Dirige uma mensagem de texto livre pelo motor, como o bot faz. */
function falarComOBot(User $user, string $texto)
{
    return app(ProcessarInteracao::class)->processar(
        $user,
        new ComandoRecebido(Comando::DESCONHECIDO, '', $texto),
    );
}

/** Fake do classificador + extrator para uma mensagem de registro. */
function fakeRegistro(array $extracao): void
{
    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'registrar']]);
    Ai::fakeAgent(ExtratorDeGasto::class, [$extracao]);
}

/** A extração que o modelo DEVERIA produzir para a mensagem do incidente. */
function extracaoDoIncidente(array $over = []): array
{
    return array_merge([
        'descricao' => 'ingles carol',
        'valor' => '520',
        'forma_pagamento' => 'pix',
        'categoria' => 'estudos',
        'data' => null,
        'parcelas' => null,
        'recorrencia_dia' => '10',
    ], $over);
}

const MSG_INCIDENTE = 'registra uma recorrencia no pix para pagar todo dia 10 520 reias ingles carol categoria estudos';

it('não pede data nem forma de pagamento na mensagem do incidente (C1)', function () {
    $user = User::factory()->create();
    fakeRegistro(extracaoDoIncidente());

    $resultado = falarComOBot($user, MSG_INCIDENTE);

    // O bug era exatamente este: pedir "a data e a forma de pagamento" que já foram ditas.
    expect($resultado->registro->esclarecimentos)->toBe([])
        ->and($resultado->registro->confirmavel())->toBeTrue()
        ->and($resultado->registro->recorrencia->dia)->toBe(10)
        ->and($resultado->registro->recorrencia->valorCents)->toBe(52000);
});

it('não grava nada antes do "sim" (regra 7)', function () {
    $user = User::factory()->create();
    fakeRegistro(extracaoDoIncidente());

    falarComOBot($user, MSG_INCIDENTE);

    expect(Recurrence::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

it('grava a recorrência no "sim" e nenhum lançamento (C10)', function () {
    $user = User::factory()->create();
    fakeRegistro(extracaoDoIncidente());
    falarComOBot($user, MSG_INCIDENTE);

    falarComOBot($user, 'sim');

    $recorrencia = Recurrence::sole();

    expect($recorrencia->user_id)->toBe($user->id)
        ->and($recorrencia->descricao)->toBe('ingles carol')
        ->and($recorrencia->valor_cents)->toBe(52000)
        ->and($recorrencia->dia)->toBe(10)
        ->and($recorrencia->status)->toBe(Recurrence::STATUS_ATIVO)
        ->and($recorrencia->payment_method_id)->toBe(PaymentMethod::idFor('pix'))
        // Hoje é 16/07 e o dia 10 já passou: a próxima ocorrência é 10/08 (OcorrenciaMensal).
        ->and($recorrencia->proxima_em->format('Y-m-d'))->toBe('2026-08-10')
        // O lançamento só nasce quando o materializador enfileirar e o usuário confirmar (spec 10).
        ->and(Transaction::count())->toBe(0);
});

it('descarta a recorrência no "não" (C11)', function () {
    $user = User::factory()->create();
    fakeRegistro(extracaoDoIncidente());
    falarComOBot($user, MSG_INCIDENTE);

    falarComOBot($user, 'não');

    expect(Recurrence::count())->toBe(0);
});

it('preserva o dia entre turnos de esclarecimento (C12)', function () {
    $user = User::factory()->create();

    // 1º turno: só o que repete e quando — falta valor e forma.
    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'registrar']]);
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'ingles carol', 'valor' => null, 'forma_pagamento' => null,
        'categoria' => 'estudos', 'data' => null, 'parcelas' => null, 'recorrencia_dia' => '10',
    ]]);

    $primeiro = falarComOBot($user, 'pago o ingles da carol todo dia 10');

    expect($primeiro->registro->esclarecimentos)
        ->toContain('valor')
        ->toContain('forma_pagamento')
        ->not->toContain('data');

    // 2º turno: o usuário completa; o extrator NÃO repete o dia.
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => null, 'valor' => '520', 'forma_pagamento' => 'pix',
        'categoria' => null, 'data' => null, 'parcelas' => null, 'recorrencia_dia' => null,
    ]]);

    $segundo = falarComOBot($user, '520 no pix');

    expect($segundo->registro->confirmavel())->toBeTrue()
        ->and($segundo->registro->recorrencia->dia)->toBe(10);
});

it('isola a recorrência pelo usuário que falou (C14)', function () {
    $autor = User::factory()->create();
    $outro = User::factory()->create();
    fakeRegistro(extracaoDoIncidente());
    falarComOBot($autor, MSG_INCIDENTE);

    falarComOBot($autor, 'sim');

    expect(Recurrence::sole()->user_id)->toBe($autor->id)
        ->and(Recurrence::where('user_id', $outro->id)->count())->toBe(0);
});

it('segue registrando gasto avulso quando não há recorrência (C13 — regressão zero)', function () {
    $user = User::factory()->create();
    fakeRegistro([
        'descricao' => 'mercado', 'valor' => '90', 'forma_pagamento' => 'pix',
        'categoria' => null, 'data' => 'hoje', 'parcelas' => null, 'recorrencia_dia' => null,
    ]);
    falarComOBot($user, 'gastei 90 no mercado hoje no pix');

    falarComOBot($user, 'sim');

    expect(Transaction::count())->toBe(1)
        ->and(Recurrence::count())->toBe(0);
});
