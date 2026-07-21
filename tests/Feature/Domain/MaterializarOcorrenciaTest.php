<?php

declare(strict_types=1);

use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Domain\Recorrencia\MaterializarOcorrencia;
use App\Models\Card;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Materializar sob demanda a ocorrência que hoje é só PREVISÃO (spec 12 / spec 13).
 *
 * O quadro do dashboard mostra a conta fixa do mês antes de o agendador gerá-la — e até aqui
 * essa linha não tinha alvo: não havia id para "marcar como paga". Este serviço cria a
 * ocorrência daquela competência a partir do molde (mesmo snapshot do agendador) para que ela
 * PASSE A EXISTIR e possa ser paga pelo caminho já testado ({@see PagarOcorrencia}).
 *
 * Invariantes: idempotência pela UNIQUE (recurrence_id, competencia); cartão nunca (§4.3, D3);
 * escopo estrito por usuário; o ponteiro `proxima_em` NÃO se mexe — quem o move é o agendador,
 * e a competência já materializada cai fora dele pela unique/NOT EXISTS.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    $this->hoje = CarbonImmutable::parse('2026-06-15 09:00:00', 'America/Sao_Paulo');
    $this->materializar = new MaterializarOcorrencia;
});

function moldeParaMaterializar(User $user, array $attrs = []): Recurrence
{
    return Recurrence::factory()->for($user)->create([
        'descricao' => 'Academia',
        'valor_cents' => 12000,
        'dia' => 20,
        'status' => Recurrence::STATUS_ATIVO,
        'proxima_em' => '2026-06-01',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        ...$attrs,
    ]);
}

it('materializa a competência prevista com o snapshot do molde', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user);

    $ocorrencia = $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje);

    expect($ocorrencia->user_id)->toBe($user->id)
        ->and($ocorrencia->recurrence_id)->toBe($molde->id)
        ->and($ocorrencia->competencia)->toBe('2026-06')
        ->and($ocorrencia->descricao)->toBe('Academia')
        ->and($ocorrencia->valor_cents)->toBe(12000)
        ->and($ocorrencia->vencimento->toDateString())->toBe('2026-06-20')
        ->and($ocorrencia->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('não mexe no ponteiro da recorrência — quem avança é o agendador', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user);

    $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje);

    expect($molde->refresh()->proxima_em->toDateString())->toBe('2026-06-01');
});

it('é idempotente: competência já materializada devolve a mesma ocorrência', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user);

    $primeira = $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje);
    $segunda = $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje);

    expect($segunda->id)->toBe($primeira->id)
        ->and(RecurrenceOccurrence::query()->count())->toBe(1);
});

it('devolve a ocorrência existente sem reabrir o que já foi pago', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user);
    $paga = RecurrenceOccurrence::factory()->pago()->create([
        'user_id' => $user->id, 'recurrence_id' => $molde->id, 'competencia' => '2026-06',
        'descricao' => 'Academia', 'valor_cents' => 12000,
        'data_cobranca' => '2026-06-20', 'vencimento' => '2026-06-20',
    ]);

    $devolvida = $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje);

    expect($devolvida->id)->toBe($paga->id)
        ->and($devolvida->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('materializa competência FUTURA (conta fixa paga adiantado)', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user);

    $ocorrencia = $this->materializar->para($molde->id, $user->id, '2026-08', $this->hoje);

    expect($ocorrencia->competencia)->toBe('2026-08')
        ->and($ocorrencia->vencimento->toDateString())->toBe('2026-08-20');
});

it('recusa competência de mês PASSADO — projeção não existe no retrato fechado', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user, ['proxima_em' => '2026-05-01']);

    expect(fn () => $this->materializar->para($molde->id, $user->id, '2026-05', $this->hoje))
        ->toThrow(PagamentoNaoPermitidoException::class);

    expect(RecurrenceOccurrence::query()->count())->toBe(0);
});

it('recusa recorrência EM CARTÃO — quem quita é a fatura (D3)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 5]);
    $molde = moldeParaMaterializar($user, ['card_id' => $card->id]);

    expect(fn () => $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje))
        ->toThrow(PagamentoNaoPermitidoException::class);

    expect(RecurrenceOccurrence::query()->count())->toBe(0);
});

it('recusa recorrência cancelada — não é mais cobrança', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user, ['status' => Recurrence::STATUS_CANCELADO]);

    expect(fn () => $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje))
        ->toThrow(ModelNotFoundException::class);
});

it('recusa recorrência de OUTRO usuário e nada é criado', function () {
    $dono = User::factory()->create();
    $intruso = User::factory()->create();
    $molde = moldeParaMaterializar($dono);

    expect(fn () => $this->materializar->para($molde->id, $intruso->id, '2026-06', $this->hoje))
        ->toThrow(ModelNotFoundException::class);

    expect(RecurrenceOccurrence::query()->count())->toBe(0);
});

it('recusa competência anterior ao início da recorrência', function () {
    $user = User::factory()->create();
    // Molde que só começa em agosto: junho/julho não são previsão dele, são nada.
    $molde = moldeParaMaterializar($user, ['proxima_em' => '2026-08-01']);

    expect(fn () => $this->materializar->para($molde->id, $user->id, '2026-06', $this->hoje))
        ->toThrow(PagamentoNaoPermitidoException::class);
});

it('recusa competência mal formada', function () {
    $user = User::factory()->create();
    $molde = moldeParaMaterializar($user);

    expect(fn () => $this->materializar->para($molde->id, $user->id, '2026-6', $this->hoje))
        ->toThrow(PagamentoNaoPermitidoException::class);
});
