<?php

use App\Domain\Lancamentos\ConsultarLancamentoDetalhe;
use App\Domain\Lancamentos\DetalheDoLancamento;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Consulta do DETALHE de um lançamento (FE §7.8) — leitura determinística de UMA transação
 * do usuário com suas parcelas, metadados e status POR PARCELA. O status de exibição é
 * derivado por DATA (reusa StatusDaParcela: futuro → agendado, hoje → aberto, passado →
 * vencido), com pago/pago_parcial → pago e cancelado/estornado → cancelado. O valor por
 * parcela é DERIVADO (Money::allocate, nunca persistido). A UI nunca calcula (regra 4);
 * escopo ESTRITO por usuário; "hoje" é INJETADO.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function hojeDetalhe(string $data = '2026-06-15'): CarbonImmutable
{
    return CarbonImmutable::parse($data.' 09:00:00', 'America/Sao_Paulo');
}

/**
 * Cria um lançamento com N parcelas de vencimentos/status controlados.
 *
 * @param  list<array{numero:int,total:int,vencimento:string,status:string}>  $parcelas
 */
function lancamentoDetalhe(
    User $user,
    int $valorCents,
    array $parcelas,
    string $descricao = 'Mercado do mês',
    ?Category $categoria = null,
    ?Card $cartao = null,
    ?string $forma = null,
    string $origem = 'manual',
    string $dataCompra = '2026-06-10',
): Transaction {
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
        'descricao' => $descricao,
        'categoria_id' => $categoria?->id,
        'card_id' => $cartao?->id,
        'payment_method_id' => PaymentMethod::idFor($forma ?? PaymentMethod::PIX),
        'origem' => $origem,
        'data_compra' => $dataCompra,
    ]);

    foreach ($parcelas as $p) {
        Installment::factory()->for($tx, 'transaction')->create([
            'numero' => $p['numero'],
            'total' => $p['total'],
            'vencimento' => $p['vencimento'],
            'status_id' => StatusPagamento::idFor($p['status']),
        ]);
    }

    return $tx;
}

it('devolve um DTO com a transação, valor total e origem', function () {
    $user = User::factory()->create();
    $tx = lancamentoDetalhe($user, 45000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::AGENDADO],
    ], descricao: 'Aluguel', origem: 'manual');

    $detalhe = app(ConsultarLancamentoDetalhe::class)->para($user->id, $tx->id, hojeDetalhe());

    expect($detalhe)->toBeInstanceOf(DetalheDoLancamento::class)
        ->and($detalhe->descricao)->toBe('Aluguel')
        ->and($detalhe->valorTotalCents)->toBe(45000)
        ->and($detalhe->origem)->toBe('manual')
        ->and($detalhe->parcelas)->toHaveCount(1);
});

it('deriva o status de cada parcela por data (pago, aberto, agendado, vencido)', function () {
    $user = User::factory()->create();
    // hoje = 15/06: 1/4 pago (mesmo vencido); 2/4 vence antes de hoje → vencido;
    // 3/4 vence hoje → aberto; 4/4 vence depois → agendado.
    $tx = lancamentoDetalhe($user, 40000, [
        ['numero' => 1, 'total' => 4, 'vencimento' => '2026-05-10', 'status' => StatusPagamento::PAGO],
        ['numero' => 2, 'total' => 4, 'vencimento' => '2026-06-10', 'status' => StatusPagamento::ABERTO],
        ['numero' => 3, 'total' => 4, 'vencimento' => '2026-06-15', 'status' => StatusPagamento::ABERTO],
        ['numero' => 4, 'total' => 4, 'vencimento' => '2026-07-10', 'status' => StatusPagamento::AGENDADO],
    ]);

    $detalhe = app(ConsultarLancamentoDetalhe::class)->para($user->id, $tx->id, hojeDetalhe('2026-06-15'));

    $porNumero = collect($detalhe->parcelas)->keyBy('numero');
    expect($porNumero[1]['status'])->toBe('pago')
        ->and($porNumero[2]['status'])->toBe('vencido')
        ->and($porNumero[3]['status'])->toBe('aberto')
        ->and($porNumero[4]['status'])->toBe('agendado');
});

it('trata cancelado/estornado como cancelado, ignorando a data', function () {
    $user = User::factory()->create();
    $tx = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 2, 'vencimento' => '2026-05-01', 'status' => StatusPagamento::CANCELADO],
        ['numero' => 2, 'total' => 2, 'vencimento' => '2026-07-01', 'status' => StatusPagamento::ESTORNADO],
    ]);

    $detalhe = app(ConsultarLancamentoDetalhe::class)->para($user->id, $tx->id, hojeDetalhe());

    expect(collect($detalhe->parcelas)->pluck('status')->all())
        ->toBe(['cancelado', 'cancelado']);
});

it('deriva o valor de cada parcela sem perder centavos (allocate)', function () {
    $user = User::factory()->create();
    // 100,01 em 3 → o resto (1c) é espalhado nas primeiras parcelas: 33,34 + 33,34 + 33,33.
    $tx = lancamentoDetalhe($user, 10001, [
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::AGENDADO],
        ['numero' => 2, 'total' => 3, 'vencimento' => '2026-07-20', 'status' => StatusPagamento::AGENDADO],
        ['numero' => 3, 'total' => 3, 'vencimento' => '2026-08-20', 'status' => StatusPagamento::AGENDADO],
    ]);

    $detalhe = app(ConsultarLancamentoDetalhe::class)->para($user->id, $tx->id, hojeDetalhe());

    $cents = collect($detalhe->parcelas)->pluck('cents');
    expect($cents->sum())->toBe(10001)
        ->and($cents->all())->toBe([3334, 3334, 3333]);
});

it('ordena as parcelas por número', function () {
    $user = User::factory()->create();
    $tx = lancamentoDetalhe($user, 30000, [
        ['numero' => 3, 'total' => 3, 'vencimento' => '2026-08-20', 'status' => StatusPagamento::AGENDADO],
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::AGENDADO],
        ['numero' => 2, 'total' => 3, 'vencimento' => '2026-07-20', 'status' => StatusPagamento::AGENDADO],
    ]);

    $detalhe = app(ConsultarLancamentoDetalhe::class)->para($user->id, $tx->id, hojeDetalhe());

    expect(collect($detalhe->parcelas)->pluck('numero')->all())->toBe([1, 2, 3]);
});

it('marca temParcelaPaga quando há parcela paga ou paga parcial', function () {
    $user = User::factory()->create();
    $comPaga = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 2, 'vencimento' => '2026-05-20', 'status' => StatusPagamento::PAGO],
        ['numero' => 2, 'total' => 2, 'vencimento' => '2026-07-20', 'status' => StatusPagamento::AGENDADO],
    ]);
    $semPaga = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
    ]);

    expect(app(ConsultarLancamentoDetalhe::class)->para($user->id, $comPaga->id, hojeDetalhe())->temParcelaPaga)->toBeTrue()
        ->and(app(ConsultarLancamentoDetalhe::class)->para($user->id, $semPaga->id, hojeDetalhe())->temParcelaPaga)->toBeFalse();
});

it('deriva o status geral do cabeçalho por precedência (vencido > aberto > agendado > pago)', function () {
    $user = User::factory()->create();

    $vencido = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 2, 'vencimento' => '2026-05-01', 'status' => StatusPagamento::ABERTO], // vencido
        ['numero' => 2, 'total' => 2, 'vencimento' => '2026-07-01', 'status' => StatusPagamento::AGENDADO],
    ]);
    $aberto = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 2, 'vencimento' => '2026-06-15', 'status' => StatusPagamento::ABERTO], // hoje → aberto
        ['numero' => 2, 'total' => 2, 'vencimento' => '2026-07-01', 'status' => StatusPagamento::AGENDADO],
    ]);
    $agendado = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-07-01', 'status' => StatusPagamento::AGENDADO],
    ]);
    $pago = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-05-01', 'status' => StatusPagamento::PAGO],
    ]);
    $cancelado = lancamentoDetalhe($user, 20000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-05-01', 'status' => StatusPagamento::CANCELADO],
    ]);

    $consulta = app(ConsultarLancamentoDetalhe::class);
    expect($consulta->para($user->id, $vencido->id, hojeDetalhe())->status)->toBe('vencido')
        ->and($consulta->para($user->id, $aberto->id, hojeDetalhe())->status)->toBe('aberto')
        ->and($consulta->para($user->id, $agendado->id, hojeDetalhe())->status)->toBe('agendado')
        ->and($consulta->para($user->id, $pago->id, hojeDetalhe())->status)->toBe('pago')
        ->and($consulta->para($user->id, $cancelado->id, hojeDetalhe())->status)->toBe('cancelado');
});

it('expõe forma, cartão (só no crédito) e a data da compra', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    $credito = lancamentoDetalhe($user, 45000, [
        ['numero' => 1, 'total' => 3, 'vencimento' => '2026-08-05', 'status' => StatusPagamento::AGENDADO],
    ], cartao: $cartao, forma: PaymentMethod::CREDITO, dataCompra: '2026-07-05');

    $detalhe = app(ConsultarLancamentoDetalhe::class)->para($user->id, $credito->id, hojeDetalhe());

    expect($detalhe->forma)->toBe(PaymentMethod::CREDITO)
        ->and($detalhe->cartaoDescricao)->toBe('Nubank')
        ->and($detalhe->cartaoFinal4)->toBe('1234')
        ->and($detalhe->dataCompra->toDateString())->toBe('2026-07-05')
        ->and($detalhe->ehCredito)->toBeTrue();

    $pix = lancamentoDetalhe($user, 5000, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
    ], forma: PaymentMethod::PIX);
    $detalhePix = app(ConsultarLancamentoDetalhe::class)->para($user->id, $pix->id, hojeDetalhe());
    expect($detalhePix->ehCredito)->toBeFalse()
        ->and($detalhePix->cartaoDescricao)->toBeNull();
});

it('é isolado por usuário: transação de outro dono não é encontrada', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $txAlheia = lancamentoDetalhe($outro, 99900, [
        ['numero' => 1, 'total' => 1, 'vencimento' => '2026-06-20', 'status' => StatusPagamento::ABERTO],
    ]);

    app(ConsultarLancamentoDetalhe::class)->para($user->id, $txAlheia->id, hojeDetalhe());
})->throws(ModelNotFoundException::class);
