<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borda web de EDITAR uma ocorrência de recorrência — o escopo "só este mês" (spec 12).
 * O domínio ({@see App\Domain\Recorrencia\EditarOcorrencia}) já existia e estava órfão: não
 * havia rota alguma que chegasse nele, então a conta fixa deste mês não podia ser corrigida
 * pela interface.
 *
 * Regra 7: dois passos — a PRÉVIA calcula e mostra sem gravar; o PUT grava. Ids sempre por
 * token opaco; escopo por usuário (404 para ocorrência alheia).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function ocorrenciaEditavel(User $user, array $over = []): RecurrenceOccurrence
{
    $rec = Recurrence::factory()->create([
        'user_id' => $user->id,
        'payment_method_id' => $over['payment_method_id'] ?? PaymentMethod::idFor(PaymentMethod::PIX),
        'card_id' => $over['card_id'] ?? null,
        'proxima_em' => null,
    ]);

    return RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id,
        'recurrence_id' => $rec->id,
        'competencia' => '2026-07',
        'descricao' => 'Aluguel',
        'valor_cents' => 150000,
        'data_cobranca' => '2026-07-05',
        'vencimento' => '2026-07-05',
        'payment_method_id' => $rec->payment_method_id,
        'card_id' => $rec->card_id,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('exige login', function () {
    $oc = ocorrenciaEditavel(User::factory()->create());

    $this->put(route('recorrencias.ocorrencia.update', OpaqueId::encode($oc->id)), [
        'descricao' => 'Aluguel novo',
    ])->assertRedirect(route('login'));
});

it('a prévia NÃO grava — só devolve o que seria salvo (regra 7)', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaEditavel($user);

    $this->actingAs($user)
        ->post(route('recorrencias.ocorrencia.previa', OpaqueId::encode($oc->id)), [
            'descricao' => 'Aluguel reajustado',
            'valor' => '1.700,00',
            'vencimento' => '2026-07-10',
        ])
        ->assertOk()
        ->assertJsonPath('previa.descricao', 'Aluguel reajustado')
        ->assertJsonPath('previa.valor', 'R$ 1.700,00');

    $oc->refresh();
    expect($oc->descricao)->toBe('Aluguel')
        ->and($oc->valor_cents)->toBe(150000);
});

it('grava a edição da ocorrência do mês, em centavos', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaEditavel($user);
    $categoria = Category::factory()->for($user)->create(['nome' => 'Moradia']);

    $this->actingAs($user)
        ->from(route('lancamentos'))
        ->put(route('recorrencias.ocorrencia.update', OpaqueId::encode($oc->id)), [
            'descricao' => 'Aluguel reajustado',
            'valor' => '1.700,00',
            'categoria' => OpaqueId::encode($categoria->id),
            'vencimento' => '2026-07-10',
        ])
        ->assertRedirect(route('lancamentos'))
        ->assertSessionHas('sucesso');

    $oc->refresh();
    expect($oc->descricao)->toBe('Aluguel reajustado')
        ->and($oc->valor_cents)->toBe(170000)
        ->and($oc->categoria_id)->toBe($categoria->id)
        ->and($oc->vencimento->toDateString())->toBe('2026-07-10');
});

it('não toca o molde da recorrência (escopo é só este mês)', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaEditavel($user);
    $moldeAntes = $oc->recurrence->only(['descricao', 'valor_cents']);

    $this->actingAs($user)
        ->from(route('lancamentos'))
        ->put(route('recorrencias.ocorrencia.update', OpaqueId::encode($oc->id)), [
            'descricao' => 'Aluguel reajustado',
            'valor' => '1.700,00',
            'vencimento' => '2026-07-05',
        ]);

    expect($oc->recurrence->fresh()->only(['descricao', 'valor_cents']))->toBe($moldeAntes);
});

it('valida a descrição e o valor', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaEditavel($user);

    $this->actingAs($user)
        ->from(route('lancamentos'))
        ->put(route('recorrencias.ocorrencia.update', OpaqueId::encode($oc->id)), [
            'descricao' => '',
            'valor' => 'abacaxi',
            'vencimento' => '2026-07-05',
        ])
        ->assertSessionHasErrors(['descricao', 'valor']);

    expect($oc->fresh()->descricao)->toBe('Aluguel');
});

it('recusa categoria de outro usuário (escopo)', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaEditavel($user);
    $alheia = Category::factory()->for(User::factory()->create())->create(['nome' => 'Outra']);

    $this->actingAs($user)
        ->from(route('lancamentos'))
        ->put(route('recorrencias.ocorrencia.update', OpaqueId::encode($oc->id)), [
            'descricao' => 'Aluguel',
            'valor' => '1.500,00',
            'categoria' => OpaqueId::encode($alheia->id),
            'vencimento' => '2026-07-05',
        ]);

    expect($oc->fresh()->categoria_id)->not->toBe($alheia->id);
});

it('devolve 404 para ocorrência de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = ocorrenciaEditavel(User::factory()->create());

    $this->actingAs($user)
        ->put(route('recorrencias.ocorrencia.update', OpaqueId::encode($alheia->id)), [
            'descricao' => 'Invadido',
            'valor' => '1,00',
            'vencimento' => '2026-07-05',
        ])
        ->assertNotFound();

    expect($alheia->fresh()->descricao)->toBe('Aluguel');
});

it('recusa (404) o id REAL no path', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaEditavel($user);

    $this->actingAs($user)
        ->put("/recorrencias/ocorrencia/{$oc->id}", [
            'descricao' => 'Aluguel',
            'valor' => '1,00',
            'vencimento' => '2026-07-05',
        ])
        ->assertNotFound();
});

it('recusa editar ocorrência de cartão pela linha do extrato', function () {
    // Cobrança em cartão é item de fatura (R8): mexer nela aqui divergiria do extrato
    // do cartão. A correção, nesse caso, é no molde da recorrência.
    $user = User::factory()->create();
    $card = Card::factory()->create(['user_id' => $user->id]);
    $oc = ocorrenciaEditavel($user, [
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $card->id,
    ]);

    $this->actingAs($user)
        ->from(route('lancamentos'))
        ->put(route('recorrencias.ocorrencia.update', OpaqueId::encode($oc->id)), [
            'descricao' => 'Mudou',
            'valor' => '1,00',
            'vencimento' => '2026-07-05',
        ])
        ->assertSessionHasErrors();

    expect($oc->fresh()->descricao)->toBe('Aluguel');
});
