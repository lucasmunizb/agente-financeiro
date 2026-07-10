<?php

declare(strict_types=1);

use App\Domain\ContasVencidas\ConsultarContasVencidas;
use App\Domain\ContasVencidas\ResultadoConsultaContasVencidas;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Models\Installment;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Spec 06b — Contas em atraso. Espelho RETROSPECTIVO de `consultar_proximas_contas`:
 * itemiza e soma as parcelas com `vencimento` ANTERIOR a hoje (fuso SP) que ainda são
 * conta a pagar, excluindo o que já não é (liquidado/§4.4). "Hoje" é INJETADO —
 * determinismo (regra 4/5). Fronteira EXCLUSIVA: hoje não está em atraso (é o limite
 * inferior que próximas contas inclui). Escopo estrito por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Instante de referência fixo (fuso SP) usado como "hoje" nas consultas. */
function hojeVenc(string $data = '2026-06-26'): CarbonImmutable
{
    return CarbonImmutable::parse($data.' 09:00:00', 'America/Sao_Paulo');
}

/**
 * Cria uma conta (parcela única) vencendo na data dada, com descrição e status.
 * Devolve a transaction.
 */
function contaVencida(
    User $user,
    int $valorCents,
    string $vencimento,
    string $descricao = 'Conta',
    string $statusCodigo = StatusPagamento::ABERTO,
): Transaction {
    $transaction = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => $valorCents,
    ]);

    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1,
        'total' => 1,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $transaction;
}

it('marca a conta vencida como recorrente quando a transação tem recurrence_id', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create();

    $recorrente = contaVencida($user, 5000, '2026-06-20', 'Netflix');
    $recorrente->update(['recurrence_id' => $rec->id]);
    contaVencida($user, 3000, '2026-06-19', 'Padaria');

    $contas = collect(app(ConsultarContasVencidas::class)->para($user->id, hojeVenc())->contas)
        ->keyBy('descricao');

    expect($contas['Netflix']['recorrente'])->toBeTrue()
        ->and($contas['Padaria']['recorrente'])->toBeFalse();
});

it('soma só as contas com vencimento anterior a hoje (C1)', function () {
    $user = User::factory()->create();

    contaVencida($user, 30000, '2026-06-20'); // 6 dias atrás → entra
    contaVencida($user, 18000, '2026-06-08'); // 18 dias atrás → entra
    contaVencida($user, 50000, '2026-06-28'); // futura → NÃO entra

    $resultado = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());

    expect($resultado)->toBeInstanceOf(ResultadoConsultaContasVencidas::class)
        ->and($resultado->totalCents)->toBe(48000);
});

it('a fronteira é exclusiva: ontem entra, hoje NÃO entra (C2)', function () {
    $user = User::factory()->create();

    contaVencida($user, 40000, '2026-06-25'); // ontem → em atraso
    contaVencida($user, 99900, '2026-06-26'); // hoje → ainda não está em atraso

    $resultado = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());

    expect($resultado->totalCents)->toBe(40000)
        ->and($resultado->contas)->toHaveCount(1)
        ->and($resultado->contas[0]['vencimento'])->toBe('2026-06-25');
});

it('exclui o que não é mais conta a pagar: pago, pendente_revisao, cancelado e estornado (C3)', function () {
    $user = User::factory()->create();

    contaVencida($user, 10000, '2026-06-20', statusCodigo: StatusPagamento::ABERTO);
    contaVencida($user, 99900, '2026-06-19', statusCodigo: StatusPagamento::PAGO);
    contaVencida($user, 88800, '2026-06-18', statusCodigo: StatusPagamento::CANCELADO);
    contaVencida($user, 77700, '2026-06-17', statusCodigo: StatusPagamento::ESTORNADO);
    contaVencida($user, 66600, '2026-06-16', statusCodigo: StatusPagamento::PENDENTE_REVISAO);

    $resultado = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());

    expect($resultado->totalCents)->toBe(10000)
        ->and($resultado->contas)->toHaveCount(1);
});

it('inclui aberto, agendado, vencido e pago_parcial como contas ainda a pagar (C3)', function () {
    $user = User::factory()->create();

    contaVencida($user, 10000, '2026-06-20', statusCodigo: StatusPagamento::ABERTO);
    contaVencida($user, 20000, '2026-06-19', statusCodigo: StatusPagamento::AGENDADO);
    contaVencida($user, 30000, '2026-06-18', statusCodigo: StatusPagamento::VENCIDO);
    contaVencida($user, 40000, '2026-06-17', statusCodigo: StatusPagamento::PAGO_PARCIAL);

    $resultado = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());

    expect($resultado->totalCents)->toBe(100000);
});

it('com janelaDias corta pelo limite inferior; sem janelaDias traz todas (C4)', function () {
    $user = User::factory()->create();

    contaVencida($user, 30000, '2026-06-24'); // 2 dias atrás
    contaVencida($user, 40000, '2026-05-01'); // ~56 dias atrás

    $todas = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());
    $janela10 = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc(), 10);

    expect($todas->totalCents)->toBe(70000)     // ambas
        ->and($janela10->totalCents)->toBe(30000); // só a de 2 dias atrás
});

it('itemiza cada conta com descrição, vencimento e valor, ordenada por vencimento asc (C5)', function () {
    $user = User::factory()->create();

    contaVencida($user, 30000, '2026-06-20', 'Internet');
    contaVencida($user, 50000, '2026-06-05', 'Aluguel');

    $resultado = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());

    expect($resultado->contas)->toHaveCount(2)
        ->and($resultado->contas[0]['descricao'])->toBe('Aluguel')   // mais antiga primeiro
        ->and($resultado->contas[0]['vencimento'])->toBe('2026-06-05')
        ->and($resultado->contas[0]['cents'])->toBe(50000)
        ->and($resultado->contas[1]['descricao'])->toBe('Internet')
        ->and($resultado->contas[1]['vencimento'])->toBe('2026-06-20');
});

it('isola por usuário: ignora contas vencidas de outro usuário (C6)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    contaVencida($user, 30000, '2026-06-20');
    contaVencida($outro, 555500, '2026-06-20');

    $resultado = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());

    expect($resultado->totalCents)->toBe(30000);
});

it('sem nada em atraso devolve total zero e lista vazia (C7)', function () {
    $user = User::factory()->create();

    contaVencida($user, 50000, '2026-06-28'); // futura

    $resultado = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc());

    expect($resultado->totalCents)->toBe(0)
        ->and($resultado->contas)->toBe([]);
});

it('carrega um trace com a ferramenta, a janela efetiva e a contagem de contas', function () {
    $user = User::factory()->create();

    contaVencida($user, 30000, '2026-06-20');
    contaVencida($user, 20000, '2026-06-10');

    $trace = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc())->trace;

    expect($trace->ferramenta)->toBe('consultar_contas_vencidas')
        ->and($trace->filtros['ate'])->toBe('2026-06-25') // ontem (fronteira exclusiva)
        ->and($trace->registros)->toBe(2);
});

it('expõe um payload para o guard com o total e o valor de cada conta', function () {
    $user = User::factory()->create();

    contaVencida($user, 30000, '2026-06-20');
    contaVencida($user, 20000, '2026-06-10');

    $payload = app(ConsultarContasVencidas::class)->para($user->id, hojeVenc())->payload();

    expect($payload)->toBeInstanceOf(PayloadDeResposta::class)
        ->and($payload->permiteValor(50000))->toBeTrue() // total
        ->and($payload->permiteValor(30000))->toBeTrue() // conta 1
        ->and($payload->permiteValor(20000))->toBeTrue() // conta 2
        ->and($payload->permiteValor(123456))->toBeFalse();
});
