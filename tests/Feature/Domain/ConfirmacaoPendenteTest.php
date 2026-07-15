<?php

declare(strict_types=1);

use App\Domain\Confirmacao\ConfirmarPendente;
use App\Domain\Confirmacao\ConsultarPendentes;
use App\Domain\Confirmacao\EnfileirarConfirmacao;
use App\Domain\Confirmacao\RejeitarPendente;
use App\Domain\Gasto\DadosGastoManual;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Base de confirmações pendentes (FE §7.9): fila de N itens por usuário, revisáveis 1 a 1.
 * Enfileirar (recorrência/PDF alimentam) → listar pendentes → confirmar (reusa
 * RegistrarGastoManual, regra 4/7) ou rejeitar. Escopo ESTRITO por usuário; nada grava sem o
 * "sim"; idempotente (um "sim" não grava duas vezes).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function dadosPendente(User $user, int $cents = 5590, string $descricao = 'Netflix'): DadosGastoManual
{
    return new DadosGastoManual(
        userId: $user->id,
        descricao: $descricao,
        valorTotalCents: $cents,
        dataCompra: CarbonImmutable::parse('2026-07-05', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 1,
        // origem da TRANSACTION (constraint manual|telegram|pdf). A fonte do PENDENTE
        // (recorrencia/importacao) mora na coluna própria de pending_confirmations; quando a
        // recorrência existir, ela amplia o constraint e passa 'recorrencia' aqui.
        origem: 'manual',
    );
}

it('enfileira um pendente com payload, origem e status pendente', function () {
    $user = User::factory()->create();

    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    expect($pendente->status)->toBe(PendingConfirmation::STATUS_PENDENTE)
        ->and($pendente->origem)->toBe(PendingConfirmation::ORIGEM_RECORRENCIA)
        ->and($pendente->payload['valorTotalCents'])->toBe(5590)
        ->and($pendente->payload['descricao'])->toBe('Netflix')
        ->and($pendente->transaction_id)->toBeNull();
});

it('lista só os pendentes do usuário, excluindo resolvidos e expirados', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user, 1000, 'Vivo'), PendingConfirmation::ORIGEM_RECORRENCIA);
    (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user, 2000, 'Spotify'), PendingConfirmation::ORIGEM_RECORRENCIA);
    PendingConfirmation::factory()->for($user)->confirmado()->create();
    PendingConfirmation::factory()->for($user)->rejeitado()->create();
    PendingConfirmation::factory()->for($user)->expirado($agora)->create();

    $lista = (new ConsultarPendentes)->para($user->id, $agora);

    expect($lista)->toHaveCount(2)
        ->and($lista->pluck('payload.descricao')->all())->toBe(['Vivo', 'Spotify']);
});

it('isola a lista por usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $agora = CarbonImmutable::now('America/Sao_Paulo');
    (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user, 1000, 'Meu'), PendingConfirmation::ORIGEM_RECORRENCIA);
    (new EnfileirarConfirmacao)->enfileirar(dadosPendente($outro, 9999, 'Alheio'), PendingConfirmation::ORIGEM_RECORRENCIA);

    $lista = (new ConsultarPendentes)->para($user->id, $agora);

    expect($lista)->toHaveCount(1)
        ->and($lista->first()->payload['descricao'])->toBe('Meu');
});

it('confirma: grava o gasto, marca confirmado, linka a transaction e audita', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    $tx = (new ConfirmarPendente)->confirmar($pendente->id, $user->id, $agora);

    expect($tx)->toBeInstanceOf(Transaction::class)
        ->and($tx->user_id)->toBe($user->id)
        ->and($tx->valor_total_cents)->toBe(5590);

    $pendente->refresh();
    expect($pendente->status)->toBe(PendingConfirmation::STATUS_CONFIRMADO)
        ->and($pendente->transaction_id)->toBe($tx->id)
        ->and($pendente->resolvido_em)->not->toBeNull();

    expect(AuditLog::where('entidade', 'pending_confirmation')->where('entidade_id', $pendente->id)
        ->where('acao', AuditLog::ACAO_CONFIRMAR)->exists())->toBeTrue();
});

it('é idempotente: um segundo "sim" não grava outra vez', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    (new ConfirmarPendente)->confirmar($pendente->id, $user->id, $agora);
    $segunda = (new ConfirmarPendente)->confirmar($pendente->id, $user->id, $agora);

    expect($segunda)->toBeNull()
        ->and(Transaction::where('user_id', $user->id)->count())->toBe(1);
});

it('recusa confirmar um pendente expirado (não grava nada)', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(
        dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA, $agora->subHour(),
    );

    $tx = (new ConfirmarPendente)->confirmar($pendente->id, $user->id, $agora);

    expect($tx)->toBeNull()
        ->and(Transaction::where('user_id', $user->id)->count())->toBe(0)
        ->and($pendente->fresh()->status)->toBe(PendingConfirmation::STATUS_PENDENTE);
});

it('não confirma pendente de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $agora = CarbonImmutable::now('America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    (new ConfirmarPendente)->confirmar($pendente->id, $outro->id, $agora);
})->throws(ModelNotFoundException::class);

it('rejeita: marca rejeitado e audita, sem criar lançamento', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    $ok = (new RejeitarPendente)->rejeitar($pendente->id, $user->id, $agora);

    expect($ok)->toBeTrue()
        ->and($pendente->fresh()->status)->toBe(PendingConfirmation::STATUS_REJEITADO)
        ->and(Transaction::where('user_id', $user->id)->count())->toBe(0)
        ->and(AuditLog::where('entidade', 'pending_confirmation')->where('entidade_id', $pendente->id)
            ->where('acao', AuditLog::ACAO_REJEITAR)->exists())->toBeTrue();
});

/*
 * Fuso em timestamptz (auditoria P1-6): datetime construído em America/Sao_Paulo precisa
 * ser convertido a UTC na GRAVAÇÃO e na COMPARAÇÃO SQL, senão o instante corrompe em 3h
 * (o cast serializa a hora local sem offset e o Postgres lê como UTC).
 */

it('preserva o instante de resolvido_em ao confirmar (fuso SP → UTC)', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    (new ConfirmarPendente)->confirmar($pendente->id, $user->id, $agora);

    expect($pendente->fresh()->resolvido_em->equalTo($agora))->toBeTrue();
});

it('preserva o instante de resolvido_em ao rejeitar (fuso SP → UTC)', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    (new RejeitarPendente)->rejeitar($pendente->id, $user->id, $agora);

    expect($pendente->fresh()->resolvido_em->equalTo($agora))->toBeTrue();
});

it('preserva o instante do TTL gravado no enfileirar (fuso SP → UTC)', function () {
    $user = User::factory()->create();
    $expiraEm = CarbonImmutable::parse('2026-07-09 12:00', 'America/Sao_Paulo');

    $pendente = (new EnfileirarConfirmacao)
        ->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA, $expiraEm);

    expect($pendente->fresh()->expira_em->equalTo($expiraEm))->toBeTrue();
});

it('exclui da lista pendente expirado dentro da janela de 3h do fuso', function () {
    $user = User::factory()->create();
    // Agora = 10:00 SP (13:00 UTC). TTL venceu às 08:00 SP (11:00 UTC): já expirou.
    // Comparação ingênua com a hora local ("11:00 > 10:00") listaria o item.
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');
    PendingConfirmation::factory()->for($user)->create([
        'expira_em' => CarbonImmutable::parse('2026-07-09 08:00', 'America/Sao_Paulo')->setTimezone('UTC'),
    ]);

    $lista = (new ConsultarPendentes)->para($user->id, $agora);

    expect($lista)->toHaveCount(0);
});

it('não rejeita pendente de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $agora = CarbonImmutable::now('America/Sao_Paulo');
    $pendente = (new EnfileirarConfirmacao)->enfileirar(dadosPendente($user), PendingConfirmation::ORIGEM_RECORRENCIA);

    (new RejeitarPendente)->rejeitar($pendente->id, $outro->id, $agora);
})->throws(ModelNotFoundException::class);
