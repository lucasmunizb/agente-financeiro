<?php

declare(strict_types=1);

use App\Domain\Atualizacoes\AssinaturaDeAtualizacoes;
use App\Models\Installment;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Auto-atualizar a tela (dashboard/extrato) quando o usuário confirma uma
 * transação por outro canal (ex.: o chat do Telegram). O backend expõe uma
 * ASSINATURA determinística do estado do usuário; o frontend (etapa separada,
 * regra 3) faz polling e recarrega quando a assinatura muda. A assinatura é um
 * hash de agregados (contagem/somatórios/max id de transactions + installments):
 * muda a cada registro/edição/pagamento, não depende do relógio e não vaza dados
 * (regra 4 — a UI nunca recebe número calculado por aqui; só um opaco). Escopo
 * estrito por usuário: a assinatura de um nunca reflete o dado do outro.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([StatusPagamentoSeeder::class]);
});

function gastoDeAtualizacao(User $user, int $cents = 5000, string $status = StatusPagamento::ABERTO): Transaction
{
    $tx = Transaction::factory()->for($user)->create(['valor_total_cents' => $cents]);
    Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1,
        'total' => 1,
        'status_id' => StatusPagamento::idFor($status),
    ]);

    return $tx;
}

it('mantém a mesma assinatura enquanto nada muda', function () {
    $user = User::factory()->create();
    gastoDeAtualizacao($user);

    $svc = app(AssinaturaDeAtualizacoes::class);

    expect($svc->para($user->id))->toBe($svc->para($user->id));
});

it('muda a assinatura quando uma nova transação é registrada', function () {
    $user = User::factory()->create();
    $svc = app(AssinaturaDeAtualizacoes::class);

    $antes = $svc->para($user->id);
    gastoDeAtualizacao($user);

    expect($svc->para($user->id))->not->toBe($antes);
});

it('muda a assinatura quando uma parcela é paga', function () {
    $user = User::factory()->create();
    $tx = gastoDeAtualizacao($user);
    $svc = app(AssinaturaDeAtualizacoes::class);

    $antes = $svc->para($user->id);
    $tx->installments()->first()->update([
        'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
    ]);

    expect($svc->para($user->id))->not->toBe($antes);
});

it('muda a assinatura quando uma recorrência é criada — ela já aparece nos quadros', function () {
    $user = User::factory()->create();
    $svc = app(AssinaturaDeAtualizacoes::class);

    $antes = $svc->para($user->id);
    Recurrence::factory()->for($user)->create(['dia' => 25, 'proxima_em' => '2026-07-25']);

    expect($svc->para($user->id))->not->toBe($antes);
});

it('muda a assinatura quando uma recorrência é cancelada', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 25, 'proxima_em' => '2026-07-25']);
    $svc = app(AssinaturaDeAtualizacoes::class);

    $antes = $svc->para($user->id);
    $rec->update(['status' => Recurrence::STATUS_CANCELADO]);

    expect($svc->para($user->id))->not->toBe($antes);
});

it('muda a assinatura quando o agendador enfileira uma ocorrência na fila', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create(['dia' => 25, 'proxima_em' => '2026-07-25']);
    $svc = app(AssinaturaDeAtualizacoes::class);

    $antes = $svc->para($user->id);
    PendingConfirmation::factory()->for($user)->create([
        'origem' => PendingConfirmation::ORIGEM_RECORRENCIA,
        'status' => PendingConfirmation::STATUS_PENDENTE,
        'recurrence_id' => $rec->id,
    ]);

    expect($svc->para($user->id))->not->toBe($antes);
});

it('muda a assinatura quando um pendente é rejeitado (a linha some do quadro)', function () {
    $user = User::factory()->create();
    $pendente = PendingConfirmation::factory()->for($user)->create([
        'origem' => PendingConfirmation::ORIGEM_RECORRENCIA,
        'status' => PendingConfirmation::STATUS_PENDENTE,
    ]);
    $svc = app(AssinaturaDeAtualizacoes::class);

    $antes = $svc->para($user->id);
    $pendente->update(['status' => PendingConfirmation::STATUS_REJEITADO]);

    expect($svc->para($user->id))->not->toBe($antes);
});

it('é isolada por usuário — mudança de outra conta não altera a assinatura', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    gastoDeAtualizacao($user);

    $svc = app(AssinaturaDeAtualizacoes::class);
    $antes = $svc->para($user->id);

    gastoDeAtualizacao($outro);

    expect($svc->para($user->id))->toBe($antes);
});

it('o endpoint de atualizações exige autenticação', function () {
    $this->getJson('/atualizacoes')->assertUnauthorized();
});

it('o endpoint devolve a assinatura atual do usuário logado', function () {
    $user = User::factory()->create();
    gastoDeAtualizacao($user);

    $esperado = app(AssinaturaDeAtualizacoes::class)->para($user->id);

    $this->actingAs($user)->getJson('/atualizacoes')
        ->assertOk()
        ->assertExactJson(['assinatura' => $esperado]);
});

it('o endpoint é isolado por usuário (não vaza estado de outra conta)', function () {
    $dono = User::factory()->create();
    gastoDeAtualizacao($dono);
    $outro = User::factory()->create();

    $assinaturaDono = app(AssinaturaDeAtualizacoes::class)->para($dono->id);

    $assinaturaOutro = $this->actingAs($outro)->getJson('/atualizacoes')
        ->assertOk()
        ->json('assinatura');

    expect($assinaturaOutro)->not->toBe($assinaturaDono);
});
