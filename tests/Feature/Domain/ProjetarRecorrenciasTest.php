<?php

declare(strict_types=1);

use App\Domain\Recorrencia\ProjetarRecorrencias;
use App\Domain\Recorrencia\ResultadoProjecaoRecorrencias;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Previsão de recorrências no dashboard (spec 10b): camada READ-ONLY que PROJETA — a partir
 * do molde em `recurrences` — as ocorrências de um mês FUTURO, para a visão de mês futuro do
 * dashboard. Não grava nada (regra 7); só age em meses estritamente futuros (anti-dupla-
 * contagem com a materialização just-in-time da spec 10). "Agora" injetado; escopo por user.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/** "Agora" fixo do usuário: 09/07/2026 (mês corrente = 2026-07). */
function agoraFixo(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
}

it('projeta a ocorrência de um mês futuro com valor, data e a flag prevista (P1/P10)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 5,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => '2026-08-05',
    ]);

    $resultado = (new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo());

    expect($resultado)->toBeInstanceOf(ResultadoProjecaoRecorrencias::class)
        ->and($resultado->totalCents)->toBe(5590)
        ->and($resultado->ocorrencias)->toHaveCount(1);

    expect($resultado->ocorrencias[0])->toMatchArray([
        'descricao' => 'Netflix',
        'vencimento' => '2026-08-05',
        'cents' => 5590,
        'prevista' => true,
    ]);
});

it('projeta várias ordenadas por vencimento e soma o total (P10)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => 180000, 'dia' => 10, 'proxima_em' => '2026-08-10',
    ]);
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590, 'dia' => 5, 'proxima_em' => '2026-08-05',
    ]);

    $resultado = (new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo());

    expect(array_column($resultado->ocorrencias, 'descricao'))->toBe(['Netflix', 'Aluguel'])
        ->and($resultado->totalCents)->toBe(185590);
});

it('não projeta nada no mês corrente — servido pela fila just-in-time (guard/P3)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 20, 'proxima_em' => '2026-07-20']);

    $resultado = (new ProjetarRecorrencias)->para($user->id, '2026-07', agoraFixo());

    expect($resultado->totalCents)->toBe(0)
        ->and($resultado->ocorrencias)->toBe([]);
});

it('não projeta nada em mês passado (guard/P4)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-06-05']);

    $resultado = (new ProjetarRecorrencias)->para($user->id, '2026-06', agoraFixo());

    expect($resultado->ocorrencias)->toBe([]);
});

it('clampa o dia ao fim do mês projetado (P5)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 31, 'proxima_em' => '2026-08-31']);

    // Fevereiro futuro (2027-02) tem 28 dias.
    $resultado = (new ProjetarRecorrencias)->para($user->id, '2027-02', agoraFixo());

    expect($resultado->ocorrencias[0]['vencimento'])->toBe('2027-02-28');
});

it('só projeta a partir do mês em que a recorrência começa (P6)', function () {
    $user = User::factory()->create();
    // Começa em setembro (proxima_em 2026-09-05).
    Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-09-05']);

    // Agosto é futuro, mas antes do início → não aparece.
    expect((new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo())->ocorrencias)->toBe([]);

    // Setembro em diante → aparece.
    $set = (new ProjetarRecorrencias)->para($user->id, '2026-09', agoraFixo());
    expect($set->ocorrencias)->toHaveCount(1)
        ->and($set->ocorrencias[0]['vencimento'])->toBe('2026-09-05');
});

it('não projeta recorrência cancelada nem soft-deleted (P7)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->cancelado()->create(['dia' => 5]);
    Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-08-05'])->delete();

    $resultado = (new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo());

    expect($resultado->ocorrencias)->toBe([]);
});

it('isola a projeção por usuário (P8)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    Recurrence::factory()->for($user)->create(['descricao' => 'Minha', 'dia' => 5, 'proxima_em' => '2026-08-05']);
    Recurrence::factory()->for($outro)->create(['descricao' => 'Alheia', 'dia' => 5, 'proxima_em' => '2026-08-05']);

    $resultado = (new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo());

    expect($resultado->ocorrencias)->toHaveCount(1)
        ->and($resultado->ocorrencias[0]['descricao'])->toBe('Minha');
});

it('enriquece a ocorrência com categoria (nome+cor) e forma — para o extrato de mês futuro', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->for($user)->create(['nome' => 'Moradia', 'cor' => '#C9852A']);
    Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => 180000, 'dia' => 10, 'proxima_em' => '2026-08-10',
        'categoria_id' => $cat->id,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::BOLETO),
    ]);

    $oc = (new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo())->ocorrencias[0];

    expect($oc['categoria'])->toBe(['nome' => 'Moradia', 'cor' => '#C9852A'])
        ->and($oc['forma'])->toBe(PaymentMethod::BOLETO)
        ->and($oc['categoriaId'])->toBe($cat->id);
});

it('deixa a categoria da ocorrência null quando a recorrência não tem categoria', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-08-05', 'categoria_id' => null]);

    $oc = (new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo())->ocorrencias[0];

    expect($oc['categoria'])->toBeNull()
        ->and($oc['categoriaId'])->toBeNull();
});

it('é read-only: projetar não grava confirmação nem lançamento (P9/regra 7)', function () {
    $user = User::factory()->create();
    Recurrence::factory()->for($user)->create(['dia' => 5, 'proxima_em' => '2026-08-05']);

    (new ProjetarRecorrencias)->para($user->id, '2026-08', agoraFixo());

    expect(PendingConfirmation::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});
