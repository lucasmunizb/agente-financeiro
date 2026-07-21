<?php

declare(strict_types=1);

use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Domain\Gastos\ConsultarGastos;
use App\Domain\Lancamentos\ConsultarLancamentos;
use App\Domain\Orcamento\ConsumoDoMes;
use App\Models\Card;
use App\Models\Installment;
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
 * MÊS DE CAIXA (decisão do usuário 2026-07-21) — o que já foi PAGO fora de cartão pertence ao
 * mês em que o dinheiro saiu, não ao mês em que a conta venceu.
 *
 * Antes, todo gasto pertencia ao mês do VENCIMENTO (doc 03 §4.5): pagar em julho a conta fixa
 * de agosto (adiantado) inflava agosto e não tocava julho; pagar em julho a de junho (atrasado)
 * mantinha o peso num mês já encerrado. O usuário paga hoje — o número do mês tem de refletir
 * isso.
 *
 * Barreiras que NÃO mudam: cartão continua pertencendo ao mês da FATURA (§4.3/§4.5) — a compra
 * não se paga sozinha, e a ocorrência de cartão é liquidada pelo agendador (D3). Enquanto a
 * conta não é paga, o mês continua sendo o do vencimento/competência (é previsão).
 *
 * "Hoje" congelado em 2026-07-10.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    $this->hoje = CarbonImmutable::parse('2026-07-10 09:00:00', 'America/Sao_Paulo');
    CarbonImmutable::setTestNow($this->hoje);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Ocorrência de conta fixa fora de cartão; `$pagaEm` = data (SP) em que o dinheiro saiu. */
function ocorrenciaDeCaixa(User $user, string $competencia, string $vencimento, int $cents, ?string $pagaEm = null, ?Card $card = null): RecurrenceOccurrence
{
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => $cents, 'dia' => (int) substr($vencimento, 8, 2),
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2099-01-01',
        'card_id' => $card?->id,
    ]);

    return RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id,
        'competencia' => $competencia, 'descricao' => 'Aluguel', 'valor_cents' => $cents,
        'data_cobranca' => $vencimento, 'vencimento' => $vencimento,
        'card_id' => $card?->id,
        'status_id' => StatusPagamento::idFor($pagaEm !== null ? StatusPagamento::PAGO : StatusPagamento::ABERTO),
        // timestamptz: o instante é gravado em UTC (a data civil é a de SP).
        'data_pagamento' => $pagaEm !== null
            ? CarbonImmutable::parse($pagaEm.' 12:00:00', 'America/Sao_Paulo')->setTimezone('UTC')
            : null,
    ]);
}

/** Parcela única fora de cartão; `$pagaEm` = data do pagamento (coluna `date`). */
function parcelaDeCaixa(User $user, string $vencimento, int $cents, ?string $pagaEm = null, ?Card $card = null): Installment
{
    $tx = Transaction::factory()->for($user)->create([
        'descricao' => 'Dentista', 'valor_total_cents' => $cents, 'card_id' => $card?->id,
        'payment_method_id' => PaymentMethod::idFor($card !== null ? PaymentMethod::CREDITO : PaymentMethod::PIX),
    ]);

    return Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($pagaEm !== null ? StatusPagamento::PAGO : StatusPagamento::ABERTO),
        'data_pagamento' => $pagaEm,
    ]);
}

it('conta fixa paga ADIANTADA pesa no mês do pagamento, não no da competência', function () {
    $user = User::factory()->create();
    ocorrenciaDeCaixa($user, '2026-08', '2026-08-05', 180000, pagaEm: '2026-07-10');

    $julho = app(ConsultarGastos::class)->para($user->id, '2026-07', agora: $this->hoje);
    $agosto = app(ConsultarGastos::class)->para($user->id, '2026-08', agora: $this->hoje);

    expect($julho->totalCents)->toBe(180000)
        ->and($agosto->totalCents)->toBe(0);
});

it('conta fixa paga ATRASADA sai do mês vencido e pesa no mês do pagamento', function () {
    $user = User::factory()->create();
    ocorrenciaDeCaixa($user, '2026-06', '2026-06-05', 180000, pagaEm: '2026-07-10');

    $junho = app(ConsultarGastos::class)->para($user->id, '2026-06', agora: $this->hoje);
    $julho = app(ConsultarGastos::class)->para($user->id, '2026-07', agora: $this->hoje);

    expect($junho->totalCents)->toBe(0)
        ->and($julho->totalCents)->toBe(180000);
});

it('conta fixa NÃO paga continua pesando no mês da competência (ainda é previsão)', function () {
    $user = User::factory()->create();
    ocorrenciaDeCaixa($user, '2026-08', '2026-08-05', 180000);

    expect(app(ConsultarGastos::class)->para($user->id, '2026-08', agora: $this->hoje)->totalCents)->toBe(180000)
        ->and(app(ConsultarGastos::class)->para($user->id, '2026-07', agora: $this->hoje)->totalCents)->toBe(0);
});

it('conta fixa de CARTÃO paga segue no mês da fatura (quem quita é a fatura)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 5]);
    // Liquidada pelo agendador (D3) na data da cobrança, em julho; a fatura vence em agosto.
    ocorrenciaDeCaixa($user, '2026-08', '2026-08-05', 5590, pagaEm: '2026-07-20', card: $card);

    expect(app(ConsultarGastos::class)->para($user->id, '2026-08', agora: $this->hoje)->totalCents)->toBe(5590)
        ->and(app(ConsultarGastos::class)->para($user->id, '2026-07', agora: $this->hoje)->totalCents)->toBe(0);
});

it('parcela fora de cartão paga em outro mês migra para o mês do pagamento', function () {
    $user = User::factory()->create();
    parcelaDeCaixa($user, '2026-06-20', 45000, pagaEm: '2026-07-03');

    expect(app(ConsumoDoMes::class)->para($user->id, '2026-06')->totalCents)->toBe(0)
        ->and(app(ConsumoDoMes::class)->para($user->id, '2026-07')->totalCents)->toBe(45000);
});

it('parcela de CARTÃO não migra: pertence ao mês da fatura', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 5]);
    parcelaDeCaixa($user, '2026-08-05', 12000, card: $card);

    expect(app(ConsumoDoMes::class)->para($user->id, '2026-08')->totalCents)->toBe(12000)
        ->and(app(ConsumoDoMes::class)->para($user->id, '2026-07')->totalCents)->toBe(0);
});

it('o disponível do mês segue o mesmo mês de caixa', function () {
    $user = User::factory()->create();
    // Paga adiantada em julho: some de agosto e entra em julho, nas duas fontes.
    ocorrenciaDeCaixa($user, '2026-08', '2026-08-05', 180000, pagaEm: '2026-07-10');
    parcelaDeCaixa($user, '2026-08-20', 45000, pagaEm: '2026-07-03');

    $julho = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-07');
    $agosto = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-08');

    expect($julho->gastosDoMesCents)->toBe(225000)
        ->and($agosto->gastosDoMesCents)->toBe(0);
});

it('o extrato lista a conta no mês em que ela foi paga', function () {
    $user = User::factory()->create();
    ocorrenciaDeCaixa($user, '2026-06', '2026-06-05', 180000, pagaEm: '2026-07-10');
    parcelaDeCaixa($user, '2026-06-20', 45000, pagaEm: '2026-07-03');

    $junho = app(ConsultarLancamentos::class)->para($user->id, '2026-06', $this->hoje);
    $julho = app(ConsultarLancamentos::class)->para($user->id, '2026-07', $this->hoje);

    expect($junho->registros)->toBe(0)
        ->and($julho->registros)->toBe(2)
        ->and($julho->totalExibidoCents)->toBe(225000);
});

it('confirmar o pagamento HOJE joga a conta fixa para o mês corrente (fluxo da tela)', function () {
    $user = User::factory()->create();
    // Conta fixa de agosto, ainda em aberto: hoje ela pesa em agosto.
    $ocorrencia = ocorrenciaDeCaixa($user, '2026-08', '2026-08-05', 180000);

    expect(app(ConsultarGastos::class)->para($user->id, '2026-08', agora: $this->hoje)->totalCents)->toBe(180000);

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia.pagar', $ocorrencia->getRouteKey()))
        ->assertRedirect()
        ->assertSessionHas('sucesso');

    $ocorrencia->refresh();

    expect($ocorrencia->data_pagamento->setTimezone('America/Sao_Paulo')->toDateString())->toBe('2026-07-10')
        ->and(app(ConsultarGastos::class)->para($user->id, '2026-07', agora: $this->hoje)->totalCents)->toBe(180000)
        ->and(app(ConsultarGastos::class)->para($user->id, '2026-08', agora: $this->hoje)->totalCents)->toBe(0);
});

it('pagar a conta fixa PREVISTA hoje a materializa e a coloca no mês corrente', function () {
    $user = User::factory()->create();
    $molde = Recurrence::factory()->for($user)->create([
        'descricao' => 'Academia', 'valor_cents' => 12000, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->post(route('lancamentos.recorrencia-prevista.pagar', $molde->getRouteKey()), ['competencia' => '2026-08'])
        ->assertRedirect()
        ->assertSessionHas('sucesso');

    // A ocorrência nasceu na competência 08 (é dela que a conta é), mas o dinheiro saiu em 07.
    $oc = RecurrenceOccurrence::query()->sole();

    expect($oc->competencia)->toBe('2026-08')
        ->and($oc->data_pagamento->setTimezone('America/Sao_Paulo')->toDateString())->toBe('2026-07-10')
        // Julho soma DUAS contas do mesmo molde: a de julho, ainda prevista (o agendador não
        // rodou), e a de agosto que acabou de ser paga adiantada. Agosto zera — a competência
        // dele já saiu do mês pela regra de caixa e a projeção a exclui por `NOT EXISTS`.
        ->and(app(ConsultarGastos::class)->para($user->id, '2026-07', agora: $this->hoje)->totalCents)->toBe(24000)
        ->and(app(ConsultarGastos::class)->para($user->id, '2026-08', agora: $this->hoje)->totalCents)->toBe(0);
});
