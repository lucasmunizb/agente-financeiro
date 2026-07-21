<?php

declare(strict_types=1);

use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\GerarOcorrencias;
use App\Domain\Recorrencia\LiquidarOcorrenciasDeCartao;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Models\Card;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Ciclo da ocorrência EM CARTÃO (spec 12, D3): ela liquida sozinha pela DATA DE COBRANÇA (o
 * dia do molde), não pelo vencimento da fatura — do ponto de vista do usuário a cobrança já
 * aconteceu e o cartão cuida do resto. `data_pagamento` recebe a própria data de cobrança
 * (verdade histórica, não "hoje"). Fora de cartão NUNCA liquida sozinho (R9c).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function recorrenciaEmCartao(User $user, int $dia, array $over = []): array
{
    $card = Card::factory()->create([
        'user_id' => $user->id,
        'dia_fechamento' => $over['dia_fechamento'] ?? 20,
        'dia_vencimento' => $over['dia_vencimento'] ?? 28,
    ]);

    $rec = Recurrence::factory()->create([
        'user_id' => $user->id,
        'descricao' => 'Netflix',
        'valor_cents' => 5590,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $card->id,
        'dia' => $dia,
        'status' => Recurrence::STATUS_ATIVO,
        'proxima_em' => $over['proxima_em'] ?? '2026-07-01',
    ]);

    return [$rec, $card];
}

it('gera a ocorrência de cartão na competência da fatura (R7)', function () {
    $user = User::factory()->create();
    [$rec] = recorrenciaEmCartao($user, dia: 25);

    (new GerarOcorrencias)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));

    $oc = RecurrenceOccurrence::where('recurrence_id', $rec->id)->sole();

    expect($oc->data_cobranca->toDateString())->toBe('2026-07-25')
        ->and($oc->vencimento->toDateString())->toBe('2026-08-28')
        ->and($oc->competencia)->toBe('2026-08')
        ->and($oc->card_id)->toBe($rec->card_id);
});

it('liquida sozinha a ocorrência de cartão cuja cobrança já passou, com data_pagamento = data de cobrança (R9b)', function () {
    $user = User::factory()->create();
    [$rec] = recorrenciaEmCartao($user, dia: 25);

    (new GerarOcorrencias)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));

    // 21/07: a cobrança (25/07) ainda não aconteceu ⇒ segue aberta.
    $liquidadas = (new LiquidarOcorrenciasDeCartao)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));
    expect($liquidadas)->toBe(0)
        ->and(RecurrenceOccurrence::sole()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));

    // 25/07: o cartão debitou ⇒ vira `pago` sem ação do usuário.
    $liquidadas = (new LiquidarOcorrenciasDeCartao)->paraTodos(CarbonImmutable::parse('2026-07-25 09:00', 'America/Sao_Paulo'));

    $oc = RecurrenceOccurrence::where('recurrence_id', $rec->id)->sole();
    expect($liquidadas)->toBe(1)
        ->and($oc->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($oc->data_pagamento->setTimezone('America/Sao_Paulo')->toDateString())->toBe('2026-07-25');
});

it('nunca liquida sozinha uma ocorrência fora de cartão (R9c)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->create([
        'user_id' => $user->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'card_id' => null,
        'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO,
        'proxima_em' => '2026-07-01',
    ]);

    $hoje = CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo');
    (new GerarOcorrencias)->paraTodos($hoje);
    $liquidadas = (new LiquidarOcorrenciasDeCartao)->paraTodos($hoje);

    // Venceu em 05/07 e continua em aberto: só o usuário a marca como paga.
    expect($liquidadas)->toBe(0)
        ->and(RecurrenceOccurrence::sole()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('cadastro em cartão com cobrança já passada nasce pago, sem ação do usuário (R9)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id, 'dia_fechamento' => 20, 'dia_vencimento' => 28]);
    $hoje = CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo');

    (new RegistrarRecorrencia)->registrar(new DadosRecorrencia(
        userId: $user->id,
        descricao: 'Netflix',
        valorCents: 5590,
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::CREDITO),
        dia: 5,
        cardId: $card->id,
    ), $hoje);

    $oc = RecurrenceOccurrence::sole();

    expect($oc->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($oc->data_pagamento->setTimezone('America/Sao_Paulo')->toDateString())->toBe('2026-07-05')
        ->and($oc->data_cobranca->toDateString())->toBe('2026-07-05')
        // Compra em 05/07 é anterior ao fechamento (20) ⇒ fatura que vence em 28/07.
        ->and($oc->vencimento->toDateString())->toBe('2026-07-28')
        ->and($oc->competencia)->toBe('2026-07')
        ->and(Transaction::count())->toBe(0);
});

it('cadastro em cartão com cobrança futura nasce aberto e liquida no dia (R9b)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id, 'dia_fechamento' => 20, 'dia_vencimento' => 28]);

    (new RegistrarRecorrencia)->registrar(new DadosRecorrencia(
        userId: $user->id,
        descricao: 'Netflix',
        valorCents: 5590,
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::CREDITO),
        dia: 25,
        cardId: $card->id,
    ), CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));

    expect(RecurrenceOccurrence::sole()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));

    (new LiquidarOcorrenciasDeCartao)->paraTodos(CarbonImmutable::parse('2026-07-25 06:00', 'America/Sao_Paulo'));

    $oc = RecurrenceOccurrence::sole();
    expect($oc->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($oc->data_pagamento->setTimezone('America/Sao_Paulo')->toDateString())->toBe('2026-07-25');
});

it('recusa cadastrar recorrência em crédito sem cartão', function () {
    $user = User::factory()->create();

    expect(fn () => (new RegistrarRecorrencia)->registrar(new DadosRecorrencia(
        userId: $user->id,
        descricao: 'Netflix',
        valorCents: 5590,
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::CREDITO),
        dia: 5,
        cardId: null,
    ), CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo')))->toThrow(InvalidArgumentException::class);

    expect(Recurrence::count())->toBe(0);
});

it('recusa cartão de outro usuário no cadastro da recorrência', function () {
    $user = User::factory()->create();
    $alheio = Card::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(fn () => (new RegistrarRecorrencia)->registrar(new DadosRecorrencia(
        userId: $user->id,
        descricao: 'Netflix',
        valorCents: 5590,
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::CREDITO),
        dia: 5,
        cardId: $alheio->id,
    ), CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo')))->toThrow(InvalidArgumentException::class);
});

it('é idempotente: rodar a liquidação duas vezes não muda a data de pagamento', function () {
    $user = User::factory()->create();
    recorrenciaEmCartao($user, dia: 5);

    (new GerarOcorrencias)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));
    (new LiquidarOcorrenciasDeCartao)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));
    $primeira = RecurrenceOccurrence::sole()->data_pagamento;

    $segunda = (new LiquidarOcorrenciasDeCartao)->paraTodos(CarbonImmutable::parse('2026-07-22 09:00', 'America/Sao_Paulo'));

    expect($segunda)->toBe(0)
        ->and(RecurrenceOccurrence::sole()->data_pagamento->equalTo($primeira))->toBeTrue();
});
