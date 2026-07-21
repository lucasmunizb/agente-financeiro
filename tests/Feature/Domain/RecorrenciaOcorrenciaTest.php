<?php

declare(strict_types=1);

use App\Domain\Recorrencia\CalcularOcorrencia;
use App\Domain\Recorrencia\CancelarRecorrencia;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\EditarOcorrencia;
use App\Domain\Recorrencia\GerarOcorrencias;
use App\Domain\Recorrencia\RegistrarRecorrencia;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Ocorrência mensal como ÚNICA representação de uma recorrência num mês (spec 12). Cobre o
 * cálculo puro (CalcularOcorrencia), a geração idempotente pelo agendador (GerarOcorrencias,
 * R5/R6/R13) e a invariante central: recorrência NUNCA escreve em transactions/installments.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function moldeAtivo(User $user, array $over = []): Recurrence
{
    return Recurrence::factory()->create([
        'user_id' => $user->id,
        'descricao' => $over['descricao'] ?? 'Netflix',
        'valor_cents' => $over['valor_cents'] ?? 5590,
        'payment_method_id' => $over['payment_method_id'] ?? PaymentMethod::idFor(PaymentMethod::PIX),
        'card_id' => $over['card_id'] ?? null,
        'categoria_id' => $over['categoria_id'] ?? null,
        'dia' => $over['dia'] ?? 5,
        'status' => $over['status'] ?? Recurrence::STATUS_ATIVO,
        'proxima_em' => $over['proxima_em'] ?? '2026-07-01',
    ]);
}

// ---- CalcularOcorrencia (puro) -----------------------------------------------------------

it('calcula a ocorrência fora de cartão: cobrança e vencimento no dia do molde', function () {
    $rec = moldeAtivo(User::factory()->create(), ['dia' => 5]);

    $oc = (new CalcularOcorrencia)->para($rec, '2026-07');

    expect($oc->dataCobranca->toDateString())->toBe('2026-07-05')
        ->and($oc->vencimento->toDateString())->toBe('2026-07-05')
        ->and($oc->competencia)->toBe('2026-07');
});

it('clampa o dia ao fim do mês na competência pedida (R6)', function () {
    $rec = moldeAtivo(User::factory()->create(), ['dia' => 31]);

    $oc = (new CalcularOcorrencia)->para($rec, '2026-02');

    expect($oc->vencimento->toDateString())->toBe('2026-02-28')
        ->and($oc->competencia)->toBe('2026-02');
});

it('em cartão, o vencimento é o da fatura e a competência passa a ser a dela (R7)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id, 'dia_fechamento' => 20, 'dia_vencimento' => 28]);
    $rec = moldeAtivo($user, [
        'dia' => 25,
        'card_id' => $card->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
    ]);

    $oc = (new CalcularOcorrencia)->para($rec, '2026-07');

    // Compra em 25/07 é posterior ao fechamento (20) ⇒ cai na fatura que vence em 28/08.
    expect($oc->dataCobranca->toDateString())->toBe('2026-07-25')
        ->and($oc->vencimento->toDateString())->toBe('2026-08-28')
        ->and($oc->competencia)->toBe('2026-08');
});

// ---- GerarOcorrencias --------------------------------------------------------------------

it('gera a ocorrência do mês e nunca escreve em transactions/installments', function () {
    $user = User::factory()->create();
    $rec = moldeAtivo($user, ['dia' => 5, 'proxima_em' => '2026-07-01']);

    $geradas = (new GerarOcorrencias)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));

    expect($geradas)->toBe(1)
        ->and(Transaction::count())->toBe(0)
        ->and(Installment::count())->toBe(0);

    $oc = RecurrenceOccurrence::where('recurrence_id', $rec->id)->sole();
    expect($oc->competencia)->toBe('2026-07')
        ->and($oc->user_id)->toBe($user->id)
        ->and($oc->descricao)->toBe('Netflix')
        ->and($oc->valor_cents)->toBe(5590)
        ->and($oc->vencimento->toDateString())->toBe('2026-07-05')
        ->and($oc->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));

    // Ponteiro avança para o primeiro mês ainda não gerado.
    expect($rec->fresh()->proxima_em->toDateString())->toBe('2026-08-01');
});

it('recupera o agendador parado gerando todas as competências faltantes (R5)', function () {
    $user = User::factory()->create();
    $rec = moldeAtivo($user, ['dia' => 5, 'proxima_em' => '2026-04-01']);

    $hoje = CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo');
    $geradas = (new GerarOcorrencias)->paraTodos($hoje);

    expect($geradas)->toBe(4);
    expect(RecurrenceOccurrence::where('recurrence_id', $rec->id)->pluck('competencia')->sort()->values()->all())
        ->toBe(['2026-04', '2026-05', '2026-06', '2026-07']);

    // Segunda execução no mesmo dia é idempotente (unique + ponteiro).
    expect((new GerarOcorrencias)->paraTodos($hoje))->toBe(0)
        ->and(RecurrenceOccurrence::count())->toBe(4);
});

it('não gera ocorrência para recorrência cancelada (R13)', function () {
    $user = User::factory()->create();
    moldeAtivo($user, ['status' => Recurrence::STATUS_CANCELADO, 'proxima_em' => null]);

    $geradas = (new GerarOcorrencias)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));

    expect($geradas)->toBe(0)
        ->and(RecurrenceOccurrence::count())->toBe(0);
});

it('não gera competência futura: o ponteiro além do mês corrente fica parado', function () {
    $user = User::factory()->create();
    moldeAtivo($user, ['proxima_em' => '2026-08-01']);

    $geradas = (new GerarOcorrencias)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));

    expect($geradas)->toBe(0)
        ->and(RecurrenceOccurrence::count())->toBe(0);
});

// ---- Cadastro (D2) -----------------------------------------------------------------------

it('o cadastro já gera a ocorrência do mês corrente e nenhum lançamento (D2/R1)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo');

    $rec = (new RegistrarRecorrencia)->registrar(new DadosRecorrencia(
        userId: $user->id,
        descricao: 'Aluguel',
        valorCents: 150000,
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        dia: 5,
    ), $hoje);

    $oc = RecurrenceOccurrence::sole();

    expect(Recurrence::count())->toBe(1)
        ->and($oc->competencia)->toBe('2026-07')
        ->and($oc->vencimento->toDateString())->toBe('2026-07-05')
        ->and($oc->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO))
        ->and(Transaction::count())->toBe(0)
        ->and(Installment::count())->toBe(0)
        ->and($rec->proxima_em->toDateString())->toBe('2026-08-01');
});

// ---- Cancelamento (R13) ------------------------------------------------------------------

it('ao cancelar, as ocorrências futuras em aberto viram canceladas e as passadas ficam (R13)', function () {
    $user = User::factory()->create();
    $rec = moldeAtivo($user, ['proxima_em' => '2026-06-01']);

    $passada = RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-06',
        'vencimento' => '2026-06-05', 'data_cobranca' => '2026-06-05',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
    $futura = RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-08',
        'vencimento' => '2026-08-05', 'data_cobranca' => '2026-08-05',
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    $cancelou = (new CancelarRecorrencia)->cancelar(
        $rec->id, $user->id, CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo')
    );

    expect($cancelou)->toBeTrue()
        ->and($rec->fresh()->status)->toBe(Recurrence::STATUS_CANCELADO)
        ->and($rec->fresh()->proxima_em)->toBeNull()
        ->and($futura->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::CANCELADO))
        ->and($passada->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

// ---- Edição "só este mês" ----------------------------------------------------------------

it('edita só a ocorrência do mês, sem tocar no molde', function () {
    $user = User::factory()->create();
    $rec = moldeAtivo($user, ['valor_cents' => 5590]);
    $oc = RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-07',
        'descricao' => 'Netflix', 'valor_cents' => 5590,
        'vencimento' => '2026-07-05', 'data_cobranca' => '2026-07-05',
    ]);

    $editada = (new EditarOcorrencia)->editar(
        $oc->id, $user->id, descricao: 'Netflix (reajuste)', valorCents: 6990,
    );

    expect($editada->descricao)->toBe('Netflix (reajuste)')
        ->and($editada->valor_cents)->toBe(6990)
        ->and($rec->fresh()->valor_cents)->toBe(5590)
        ->and($rec->fresh()->descricao)->toBe('Netflix');
});

it('mover o vencimento da ocorrência move a competência junto', function () {
    $user = User::factory()->create();
    $rec = moldeAtivo($user);
    $oc = RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-07',
        'vencimento' => '2026-07-05', 'data_cobranca' => '2026-07-05',
    ]);

    $editada = (new EditarOcorrencia)->editar(
        $oc->id, $user->id, vencimento: CarbonImmutable::parse('2026-08-03', 'America/Sao_Paulo'),
    );

    expect($editada->vencimento->toDateString())->toBe('2026-08-03')
        ->and($editada->competencia)->toBe('2026-08');
});

it('não edita ocorrência de outro usuário', function () {
    $dono = User::factory()->create();
    $rec = moldeAtivo($dono);
    $oc = RecurrenceOccurrence::factory()->create(['user_id' => $dono->id, 'recurrence_id' => $rec->id]);

    expect(fn () => (new EditarOcorrencia)->editar($oc->id, User::factory()->create()->id, valorCents: 1))
        ->toThrow(ModelNotFoundException::class);
});

it('gera ocorrências de usuários distintos sem misturar escopo', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    moldeAtivo($a, ['descricao' => 'Netflix']);
    moldeAtivo($b, ['descricao' => 'Spotify']);

    (new GerarOcorrencias)->paraTodos(CarbonImmutable::parse('2026-07-21 09:00', 'America/Sao_Paulo'));

    expect(RecurrenceOccurrence::where('user_id', $a->id)->pluck('descricao')->all())->toBe(['Netflix'])
        ->and(RecurrenceOccurrence::where('user_id', $b->id)->pluck('descricao')->all())->toBe(['Spotify']);
});
