<?php

use App\Domain\Gasto\CancelarGastoManual;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Gasto\RegistrarPagamentoParcela;
use App\Domain\Gasto\ReverterPagamentoParcela;
use App\Domain\Gasto\StatusDaParcela;
use App\Models\AuditLog;
use App\Models\Card;
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
 * DESMARCAR o pagamento de uma parcela (decisão do usuário 2026-07-21): o clique errado
 * em "marcar como pago" precisa ter conserto pela interface — sem isso o valor fica
 * errado no Disponível do mês e só o banco resolve.
 *
 * É o inverso exato de {@see RegistrarPagamentoParcela}: apaga a `data_pagamento`, devolve
 * a parcela ao status que a DATA manda ({@see StatusDaParcela} — agendado/aberto/vencido,
 * nunca 'aberto' cravado) e reavalia o status agregado da transação, sem tocar nas irmãs.
 * Mesmas barreiras do pagamento: só FORA DE CARTÃO (a fatura é quem quita — §4.3), nunca
 * reabre cancelado, escopo estrito por usuário, idempotente, e registra auditoria.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function gastoPixParceladoParaReverter(User $user, CarbonImmutable $hoje, int $parcelas = 3): Transaction
{
    return (new RegistrarGastoManual)->confirmar(new DadosGastoManual(
        userId: $user->id,
        descricao: 'Combinado com o João',
        valorTotalCents: 30000,
        dataCompra: CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: $parcelas,
    ), $hoje);
}

it('apaga a data de pagamento e devolve a parcela ao status derivado da data', function () {
    // Parcela 1 venceu em 10/06 e "hoje" é 25/06: desmarcar a devolve para VENCIDO,
    // não para 'aberto' — senão uma conta atrasada voltaria como se estivesse em dia.
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    $parcela = $tx->installments()->where('numero', 1)->first();

    (new RegistrarPagamentoParcela)->confirmar($parcela->id, $user->id, $hoje);
    (new ReverterPagamentoParcela)->reverter($parcela->id, $user->id, $hoje);

    $parcela->refresh();
    expect($parcela->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::VENCIDO))
        ->and($parcela->data_pagamento)->toBeNull();
});

it('devolve a parcela de vencimento futuro para agendado', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    // Parcela 3 vence em 10/08 — ainda no futuro.
    $futura = $tx->installments()->where('numero', 3)->first();

    (new RegistrarPagamentoParcela)->confirmar($futura->id, $user->id, $hoje);
    (new ReverterPagamentoParcela)->reverter($futura->id, $user->id, $hoje);

    expect($futura->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::AGENDADO));
});

it('não altera as parcelas irmãs ao desmarcar', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    $pagar = new RegistrarPagamentoParcela;

    foreach ($tx->installments()->orderBy('numero')->get() as $p) {
        $pagar->confirmar($p->id, $user->id, $hoje);
    }

    $alvo = $tx->installments()->where('numero', 2)->first();
    (new ReverterPagamentoParcela)->reverter($alvo->id, $user->id, $hoje);

    $irmas = $tx->installments()->where('numero', '!=', 2)->get();
    expect($irmas)->toHaveCount(2);
    foreach ($irmas as $irma) {
        expect($irma->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
            ->and($irma->data_pagamento)->not->toBeNull();
    }
});

it('reagrega a transação como pago_parcial quando ainda resta parcela paga', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    $pagar = new RegistrarPagamentoParcela;

    foreach ($tx->installments()->orderBy('numero')->get() as $p) {
        $pagar->confirmar($p->id, $user->id, $hoje);
    }
    expect($tx->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));

    (new ReverterPagamentoParcela)->reverter($tx->installments()->where('numero', 3)->first()->id, $user->id, $hoje);

    expect($tx->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO_PARCIAL));
});

it('reagrega a transação como ABERTO quando nenhuma parcela sobra paga', function () {
    // Nenhuma parcela paga NÃO é "pago_parcial" — a derivação atual só olhava
    // "todas ou algumas" e devolvia pago_parcial no zero, deixando o lançamento
    // com um status que mente sobre o que foi pago.
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    $parcela = $tx->installments()->where('numero', 1)->first();

    (new RegistrarPagamentoParcela)->confirmar($parcela->id, $user->id, $hoje);
    (new ReverterPagamentoParcela)->reverter($parcela->id, $user->id, $hoje);

    expect($tx->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('é idempotente: desmarcar uma parcela que nunca foi paga não muda nada', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    $parcela = $tx->installments()->where('numero', 1)->first();
    $antes = $parcela->status_id;

    $primeira = (new ReverterPagamentoParcela)->reverter($parcela->id, $user->id, $hoje);

    expect($primeira->status_id)->toBe($antes)
        ->and($primeira->data_pagamento)->toBeNull()
        // Nada aconteceu ⇒ nada a auditar.
        ->and(AuditLog::where('entidade', 'installment')->where('acao', AuditLog::ACAO_DESMARCAR_PAGAMENTO)->count())
        ->toBe(0);
});

it('recusa desmarcar parcela de lançamento em cartão', function () {
    // Cartão é quitado pela FATURA (§4.3): a parcela nunca foi paga individualmente,
    // então desmarcá-la é uma operação sem sentido — e abriria caminho para divergir
    // do que a fatura diz.
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $tx = Transaction::factory()->for($user)->create([
        'card_id' => $card->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
    ]);
    $parcela = Installment::factory()->for($tx, 'transaction')->create(['numero' => 1, 'total' => 1]);

    (new ReverterPagamentoParcela)->reverter(
        $parcela->id,
        $user->id,
        CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'),
    );
})->throws(PagamentoNaoPermitidoException::class);

it('recusa desmarcar parcela de lançamento cancelado', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    (new CancelarGastoManual)->confirmar($tx->id, $user->id);
    $parcela = $tx->installments()->where('numero', 1)->first();

    expect(fn () => (new ReverterPagamentoParcela)->reverter($parcela->id, $user->id, $hoje))
        ->toThrow(PagamentoNaoPermitidoException::class);

    expect($parcela->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::CANCELADO));
});

it('não desmarca parcela de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    $parcela = $tx->installments()->first();

    (new ReverterPagamentoParcela)->reverter($parcela->id, $outro->id, $hoje);
})->throws(ModelNotFoundException::class);

it('registra auditoria do estorno da marcação', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');
    $tx = gastoPixParceladoParaReverter($user, $hoje);
    $parcela = $tx->installments()->where('numero', 1)->first();

    (new RegistrarPagamentoParcela)->confirmar($parcela->id, $user->id, $hoje);
    (new ReverterPagamentoParcela)->reverter($parcela->id, $user->id, $hoje);

    $log = AuditLog::where('entidade', 'installment')->where('entidade_id', $parcela->id)
        ->where('acao', AuditLog::ACAO_DESMARCAR_PAGAMENTO)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->antes['status_id'])->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($log->depois['status_id'])->toBe(StatusPagamento::idFor(StatusPagamento::VENCIDO))
        ->and($log->depois['data_pagamento'])->toBeNull();
});
