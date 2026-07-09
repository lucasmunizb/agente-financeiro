<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda web de "cancelar (esta e as próximas)" na tela de detalhe (FE §7.8).
 * POST server-rendered: delega ao domínio já testado ({@see App\Domain\Gasto\CancelarGastoManual}),
 * que marca a transaction e as parcelas NÃO finalizadas como 'cancelado', preservando as
 * já pagas/parciais/estornadas, e registra auditoria. {transaction} SEMPRE por token opaco
 * (regra dos ids criptografados); escopo por usuário (404 para lançamento alheio).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Gasto PIX (fora de cartão) em 3 parcelas, todas em aberto. */
function lancamentoTresParcelas(User $user): Transaction
{
    $tx = Transaction::factory()->for($user)->create([
        'valor_total_cents' => 30000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'card_id' => null,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);

    foreach ([1, 2, 3] as $numero) {
        Installment::factory()->for($tx, 'transaction')->create([
            'numero' => $numero, 'total' => 3,
            'vencimento' => "2026-0{$numero}-20",
            'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
        ]);
    }

    return $tx;
}

it('exige login', function () {
    $tx = lancamentoTresParcelas(User::factory()->create());

    $this->post(route('lancamentos.cancelar', OpaqueId::encode($tx->id)))
        ->assertRedirect(route('login'));
});

it('cancela o lançamento e as parcelas em aberto, voltando ao detalhe', function () {
    $user = User::factory()->create();
    $tx = lancamentoTresParcelas($user);

    // Token opaco é não-determinístico (IV aleatório): compara pelo id decodificado.
    $resposta = $this->actingAs($user)
        ->post(route('lancamentos.cancelar', OpaqueId::encode($tx->id)));

    $resposta->assertRedirect();
    preg_match('#/lancamentos/([A-Za-z0-9_-]+)$#', $resposta->headers->get('Location'), $m);
    expect(OpaqueId::decode($m[1]))->toBe($tx->id);

    $cancelado = StatusPagamento::idFor(StatusPagamento::CANCELADO);
    expect($tx->fresh()->status_id)->toBe($cancelado)
        ->and(Installment::where('transaction_id', $tx->id)->pluck('status_id')->all())
        ->each->toBe($cancelado);
});

it('preserva a parcela já paga ao cancelar as demais', function () {
    $user = User::factory()->create();
    $tx = lancamentoTresParcelas($user);
    $paga = $tx->installments()->where('numero', 1)->first();
    $paga->update(['status_id' => StatusPagamento::idFor(StatusPagamento::PAGO)]);

    $this->actingAs($user)
        ->post(route('lancamentos.cancelar', OpaqueId::encode($tx->id)))
        ->assertRedirect();

    $cancelado = StatusPagamento::idFor(StatusPagamento::CANCELADO);
    expect($paga->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($tx->installments()->whereIn('numero', [2, 3])->pluck('status_id')->all())
        ->each->toBe($cancelado);
});

it('devolve 404 para lançamento de outro usuário', function () {
    $user = User::factory()->create();
    $alheio = lancamentoTresParcelas(User::factory()->create());

    $this->actingAs($user)
        ->post(route('lancamentos.cancelar', OpaqueId::encode($alheio->id)))
        ->assertNotFound();

    expect($alheio->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});

it('recusa (404) o id REAL no path — só token criptografado cancela', function () {
    $user = User::factory()->create();
    $tx = lancamentoTresParcelas($user);

    $this->actingAs($user)
        ->post("/lancamentos/{$tx->id}/cancelar")
        ->assertNotFound();

    expect($tx->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO));
});
