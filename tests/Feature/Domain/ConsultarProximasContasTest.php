<?php

declare(strict_types=1);

use App\Domain\IA\Guard\PayloadDeResposta;
use App\Domain\ProximasContas\ConsultarProximasContas;
use App\Domain\ProximasContas\ResultadoConsultaProximasContas;
use App\Models\Card;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Camada de consulta `consultar_proximas_contas` (doc 02 §3.2). Tool com escopo por
 * usuário: itemiza e soma as parcelas A VENCER dentro de uma janela rolante a partir
 * de "hoje" (fuso SP), excluindo o que já não é conta a pagar (liquidado/§4.4). "Hoje"
 * é INJETADO — determinismo (regra 4/5). A IA não calcula; só redige sobre estes números.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Instante de referência fixo (fuso SP) usado como "hoje" nas consultas. */
function hoje(string $data = '2026-06-26'): CarbonImmutable
{
    return CarbonImmutable::parse($data.' 09:00:00', 'America/Sao_Paulo');
}

/**
 * Cria uma conta (parcela única) a vencer na data dada, com descrição e status.
 * Devolve a transaction.
 */
function contaAVencer(
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

it('nunca marca uma parcela como recorrente: recorrência não vive em transactions (spec 12)', function () {
    $user = User::factory()->create();

    contaAVencer($user, 5000, '2026-06-28', 'Netflix');
    contaAVencer($user, 3000, '2026-06-29', 'Padaria');

    $contas = collect(app(ConsultarProximasContas::class)->para($user->id, hoje(), 30)->contas)
        ->keyBy('descricao');

    // A conta fixa entra pelo quadro do ResumoDoMes, via ocorrência — não por esta consulta.
    expect($contas['Netflix']['recorrente'])->toBeFalse()
        ->and($contas['Padaria']['recorrente'])->toBeFalse();
});

it('soma as contas a vencer dentro da janela em dias a partir de hoje', function () {
    $user = User::factory()->create();

    contaAVencer($user, 50000, '2026-06-26'); // hoje (limite inferior, incluso)
    contaAVencer($user, 30000, '2026-07-03'); // +7 dias
    contaAVencer($user, 20000, '2026-07-06'); // +10 dias (último dia da janela)
    contaAVencer($user, 99900, '2026-07-07'); // +11 dias, fora da janela de 10

    $resultado = app(ConsultarProximasContas::class)->para($user->id, hoje(), 10);

    expect($resultado)->toBeInstanceOf(ResultadoConsultaProximasContas::class)
        ->and($resultado->totalCents)->toBe(100000);
});

it('ignora contas já vencidas (antes de hoje): a janela é só para frente', function () {
    $user = User::factory()->create();

    contaAVencer($user, 77700, '2026-06-20'); // ontem-ish, fora (atrás de hoje)
    contaAVencer($user, 30000, '2026-06-28'); // dentro

    $resultado = app(ConsultarProximasContas::class)->para($user->id, hoje(), 30);

    expect($resultado->totalCents)->toBe(30000);
});

it('itemiza cada conta com descrição, vencimento e valor, ordenada por vencimento asc', function () {
    $user = User::factory()->create();

    contaAVencer($user, 30000, '2026-07-05', 'Internet');
    contaAVencer($user, 50000, '2026-06-30', 'Aluguel');

    $resultado = app(ConsultarProximasContas::class)->para($user->id, hoje(), 30);

    expect($resultado->contas)->toHaveCount(2)
        ->and($resultado->contas[0]['descricao'])->toBe('Aluguel')
        ->and($resultado->contas[0]['vencimento'])->toBe('2026-06-30')
        ->and($resultado->contas[0]['cents'])->toBe(50000)
        ->and($resultado->contas[1]['descricao'])->toBe('Internet')
        ->and($resultado->contas[1]['vencimento'])->toBe('2026-07-05');
});

it('exclui o que não é conta a pagar: pago, pendente_revisao, cancelado e estornado', function () {
    $user = User::factory()->create();

    contaAVencer($user, 10000, '2026-06-28', statusCodigo: StatusPagamento::ABERTO);
    contaAVencer($user, 99900, '2026-06-29', statusCodigo: StatusPagamento::PAGO);
    contaAVencer($user, 88800, '2026-06-30', statusCodigo: StatusPagamento::CANCELADO);
    contaAVencer($user, 77700, '2026-07-01', statusCodigo: StatusPagamento::ESTORNADO);
    contaAVencer($user, 66600, '2026-07-02', statusCodigo: StatusPagamento::PENDENTE_REVISAO);

    $resultado = app(ConsultarProximasContas::class)->para($user->id, hoje(), 30);

    expect($resultado->totalCents)->toBe(10000)
        ->and($resultado->contas)->toHaveCount(1);
});

it('inclui agendado e pago_parcial como contas ainda a pagar', function () {
    $user = User::factory()->create();

    contaAVencer($user, 40000, '2026-06-28', statusCodigo: StatusPagamento::AGENDADO);
    contaAVencer($user, 25000, '2026-06-29', statusCodigo: StatusPagamento::PAGO_PARCIAL);

    $resultado = app(ConsultarProximasContas::class)->para($user->id, hoje(), 30);

    expect($resultado->totalCents)->toBe(65000);
});

it('isola por usuário: ignora contas de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    contaAVencer($user, 30000, '2026-06-28');
    contaAVencer($outro, 555500, '2026-06-28');

    $resultado = app(ConsultarProximasContas::class)->para($user->id, hoje(), 30);

    expect($resultado->totalCents)->toBe(30000);
});

it('carrega um trace com a ferramenta, a janela efetiva e a contagem de contas', function () {
    $user = User::factory()->create();

    contaAVencer($user, 30000, '2026-06-28');
    contaAVencer($user, 20000, '2026-07-02');

    $trace = app(ConsultarProximasContas::class)->para($user->id, hoje(), 30)->trace;

    expect($trace->ferramenta)->toBe('consultar_proximas_contas')
        ->and($trace->filtros['janela_dias'])->toBe(30)
        ->and($trace->filtros['de'])->toBe('2026-06-26')
        ->and($trace->filtros['ate'])->toBe('2026-07-26')
        ->and($trace->registros)->toBe(2);
});

it('expõe um payload para o guard com o total e o valor de cada conta', function () {
    $user = User::factory()->create();

    contaAVencer($user, 30000, '2026-06-28');
    contaAVencer($user, 20000, '2026-07-02');

    $payload = app(ConsultarProximasContas::class)->para($user->id, hoje(), 30)->payload();

    expect($payload)->toBeInstanceOf(PayloadDeResposta::class)
        ->and($payload->permiteValor(50000))->toBeTrue() // total
        ->and($payload->permiteValor(30000))->toBeTrue() // conta 1
        ->and($payload->permiteValor(20000))->toBeTrue() // conta 2
        ->and($payload->permiteValor(123456))->toBeFalse();
});

it('expõe o cartão da conta, para o quadro poder somar a fatura numa linha só', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    contaAVencer($user, 30000, '2026-06-30', 'Mercado')->update(['card_id' => $card->id]);
    contaAVencer($user, 15000, '2026-07-02', 'Aluguel'); // fora de cartão

    $contas = collect(app(ConsultarProximasContas::class)->para($user->id, hoje(), 30)->contas)
        ->keyBy('descricao');

    expect($contas['Mercado']['cartaoId'])->toBe($card->id)
        ->and($contas['Mercado']['cartaoDescricao'])->toBe('Nubank')
        ->and($contas['Aluguel']['cartaoId'])->toBeNull()
        ->and($contas['Aluguel']['cartaoDescricao'])->toBeNull();
});
