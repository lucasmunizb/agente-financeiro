<?php

declare(strict_types=1);

use App\Domain\ContasVencidas\ConsultarContasVencidas;
use App\Domain\ProximasContas\ConsultarProximasContas;
use App\Domain\Recorrencia\ProjetarRecorrencias;
use App\Domain\Shared\OpaqueId;
use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Alvos de ação nos quadros de contas do dashboard (decisão do usuário 2026-07-21).
 *
 * "Em atraso" e "a vencer" eram só leitura: o usuário via a conta esquecida e não tinha como
 * resolvê-la ali. Agora cada linha carrega o id OPACO da parcela (alvo de "marcar pago") e o
 * do lançamento (alvo de "editar") — o mesmo contrato que o extrato já usa.
 *
 * Cartão continua sem alvo de pagamento: a fatura é quem quita (§4.3), e essas linhas ainda
 * são condensadas por fatura no quadro. O payload das TOOLS de IA não muda: elas só leem
 * descrição, valor e vencimento.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function contaComParcela(User $user, string $venc, string $descricao, ?Card $card = null): Installment
{
    $tx = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => 12000,
        'card_id' => $card?->id,
        'payment_method_id' => PaymentMethod::idFor($card !== null ? PaymentMethod::CREDITO : PaymentMethod::PIX),
    ]);

    return Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('expõe o alvo de pagamento e de edição em cada conta A VENCER', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-15', 'America/Sao_Paulo');
    $parcela = contaComParcela($user, '2026-06-20', 'Internet');

    $conta = app(ConsultarProximasContas::class)->para($user->id, $hoje, 15)->contas[0];

    expect($conta['pagavel'])->toBeTrue()
        ->and(OpaqueId::decode($conta['parcelaId']))->toBe($parcela->id)
        ->and(OpaqueId::decode($conta['transactionId']))->toBe($parcela->transaction_id);
});

it('expõe o alvo de pagamento e de edição em cada conta EM ATRASO', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-15', 'America/Sao_Paulo');
    $parcela = contaComParcela($user, '2026-06-05', 'Luz');

    $conta = app(ConsultarContasVencidas::class)->para($user->id, $hoje)->contas[0];

    expect($conta['pagavel'])->toBeTrue()
        ->and(OpaqueId::decode($conta['parcelaId']))->toBe($parcela->id)
        ->and(OpaqueId::decode($conta['transactionId']))->toBe($parcela->transaction_id);
});

it('não expõe alvo de pagamento em conta de cartão (a fatura é que quita)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-15', 'America/Sao_Paulo');
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 25]);
    contaComParcela($user, '2026-06-20', 'Mercado', $card);

    $conta = app(ConsultarProximasContas::class)->para($user->id, $hoje, 15)->contas[0];

    expect($conta['pagavel'])->toBeFalse()
        ->and($conta['parcelaId'])->toBeNull();
});

it('expõe o alvo da conta fixa PREVISTA: o molde + a competência da linha', function () {
    // Projeção não tem ocorrência no banco, então o alvo não pode ser um id de ocorrência:
    // é o MOLDE (opaco) mais a competência que a linha representa — o par que a rota
    // materializa antes de pagar.
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-06-15', 'America/Sao_Paulo');
    $molde = Recurrence::factory()->for($user)->create([
        'descricao' => 'Academia', 'valor_cents' => 12000, 'dia' => 20,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-06-01',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
    ]);

    $prevista = app(ProjetarRecorrencias::class)->para($user->id, '2026-06', $agora)->ocorrencias[0];

    expect($prevista['pagavel'])->toBeTrue()
        ->and($prevista['competencia'])->toBe('2026-06')
        ->and(OpaqueId::decode($prevista['recorrenciaId']))->toBe($molde->id)
        ->and($prevista['ocorrenciaId'] ?? null)->toBeNull();
});

it('não expõe alvo de pagamento em conta fixa prevista EM CARTÃO', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-06-15', 'America/Sao_Paulo');
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 25]);
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Streaming', 'valor_cents' => 5590, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-06-01',
        'card_id' => $card->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
    ]);

    $previstas = app(ProjetarRecorrencias::class)->para($user->id, '2026-06', $agora)->ocorrencias;

    expect($previstas)->not->toBeEmpty()
        ->and($previstas[0]['pagavel'])->toBeFalse();
});

it('não vaza os ids para o texto entregue ao modelo de IA', function () {
    // O payload do prompt é conjunto-verdade de valores/datas (doc 02 §3.3) — id de banco
    // não tem o que fazer lá, nem opaco.
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-06-15', 'America/Sao_Paulo');
    $parcela = contaComParcela($user, '2026-06-20', 'Internet');

    $prompt = app(ConsultarProximasContas::class)->para($user->id, $hoje, 15)->paraPrompt();

    expect($prompt)->toContain('Internet')
        ->and($prompt)->not->toContain(OpaqueId::encode($parcela->id))
        ->and($prompt)->not->toContain('parcelaId');
});
