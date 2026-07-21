<?php

use App\Domain\FaturaCartao\ConsultarFaturaCartao;
use App\Domain\FaturaCartao\ResultadoConsultaFaturaCartao;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Camada de consulta `consultar_fatura_cartao` (doc 02 §3.2). A "fatura" de um cartão
 * numa competência (YYYY-MM) = as cobranças (parcelas) desse cartão cujo VENCIMENTO cai
 * no mês — mesma atribuição por vencimento do disponível/§4.5. É um extrato itemizado
 * (cada parcela: descrição, n/N, vencimento, valor) + total. Exclui só o conjunto §4.4
 * (pendente_revisao, cancelado, estornado); MANTÉM pago (extrato do que foi cobrado).
 * Escopo ESTRITO por usuário. A IA não calcula — só redige sobre estes números.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/**
 * Cria uma cobrança em cartão (uma parcela) vencendo na data dada. Para parcelados,
 * informe `numero`/`total` (o valor da parcela é derivado do total da transaction).
 * Devolve a transaction.
 */
function compraNaFatura(
    User $user,
    Card $cartao,
    int $valorTotalCents,
    string $vencimento,
    string $descricao = 'Compra',
    int $numero = 1,
    int $total = 1,
    string $statusCodigo = StatusPagamento::ABERTO,
): Transaction {
    $transaction = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => $valorTotalCents,
        'card_id' => $cartao->id,
    ]);

    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => $numero,
        'total' => $total,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $transaction;
}

it('soma as cobranças do cartão cujo vencimento cai na competência', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $cartao, 70000, '2026-06-10');
    compraNaFatura($user, $cartao, 30000, '2026-06-25');
    compraNaFatura($user, $cartao, 999900, '2026-07-05'); // fatura de outra competência

    $resultado = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06');

    expect($resultado)->toBeInstanceOf(ResultadoConsultaFaturaCartao::class)
        ->and($resultado->totalCents)->toBe(100000);
});

it('resolve o cartão tanto pela descrição quanto pelos 4 dígitos finais', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $cartao, 50000, '2026-06-10');

    $porDescricao = app(ConsultarFaturaCartao::class)->para($user->id, 'CARTÃO PAI', '2026-06');
    $porFinal4 = app(ConsultarFaturaCartao::class)->para($user->id, '1234', '2026-06');

    expect($porDescricao->totalCents)->toBe(50000)
        ->and($porFinal4->totalCents)->toBe(50000);
});

it('considera apenas o cartão pedido — ignora cobranças de outro cartão', function () {
    $user = User::factory()->create();
    $cartaoPai = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    $cartaoMae = Card::factory()->for($user)->create(['descricao' => 'cartão mãe', 'final_4' => '5678']);

    compraNaFatura($user, $cartaoPai, 70000, '2026-06-10');
    compraNaFatura($user, $cartaoMae, 999900, '2026-06-10');

    $resultado = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06');

    expect($resultado->totalCents)->toBe(70000);
});

it('itemiza cada cobrança com descrição, parcela n/N, vencimento e valor, ordenada por vencimento asc', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    // Geladeira 90000 em 3x: só a parcela 2/3 (30000) vence em junho.
    compraNaFatura($user, $cartao, 90000, '2026-06-15', 'Geladeira', numero: 2, total: 3);
    compraNaFatura($user, $cartao, 20000, '2026-06-05', 'iFood');

    $resultado = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06');

    expect($resultado->itens)->toHaveCount(2)
        ->and($resultado->itens[0]['descricao'])->toBe('iFood')
        ->and($resultado->itens[0]['vencimento'])->toBe('2026-06-05')
        ->and($resultado->itens[0]['cents'])->toBe(20000)
        ->and($resultado->itens[0]['numero'])->toBe(1)
        ->and($resultado->itens[0]['total'])->toBe(1)
        ->and($resultado->itens[1]['descricao'])->toBe('Geladeira')
        ->and($resultado->itens[1]['vencimento'])->toBe('2026-06-15')
        ->and($resultado->itens[1]['cents'])->toBe(30000)
        ->and($resultado->itens[1]['numero'])->toBe(2)
        ->and($resultado->itens[1]['total'])->toBe(3);
});

it('mantém parcelas já pagas — a fatura é o extrato das cobranças do mês', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $cartao, 40000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO);
    compraNaFatura($user, $cartao, 25000, '2026-06-12', statusCodigo: StatusPagamento::PAGO);

    $resultado = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06');

    expect($resultado->totalCents)->toBe(65000);
});

it('exclui do extrato pendente_revisao, cancelado e estornado (§4.4)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $cartao, 10000, '2026-06-10', statusCodigo: StatusPagamento::ABERTO);
    compraNaFatura($user, $cartao, 99900, '2026-06-11', statusCodigo: StatusPagamento::CANCELADO);
    compraNaFatura($user, $cartao, 88800, '2026-06-12', statusCodigo: StatusPagamento::ESTORNADO);
    compraNaFatura($user, $cartao, 77700, '2026-06-13', statusCodigo: StatusPagamento::PENDENTE_REVISAO);

    $resultado = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06');

    expect($resultado->totalCents)->toBe(10000)
        ->and($resultado->itens)->toHaveCount(1);
});

it('isola por usuário: não enxerga a fatura de um cartão homônimo de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $meuCartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    $cartaoAlheio = Card::factory()->for($outro)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $meuCartao, 30000, '2026-06-10');
    compraNaFatura($outro, $cartaoAlheio, 555500, '2026-06-10');

    $resultado = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06');

    expect($resultado->totalCents)->toBe(30000);
});

it('cartão inexistente devolve fatura vazia, sem vazar dados de ninguém', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $cartao, 30000, '2026-06-10');

    $resultado = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão fantasma', '2026-06');

    expect($resultado->totalCents)->toBe(0)
        ->and($resultado->itens)->toBe([]);
});

it('carrega um trace com a ferramenta, os filtros efetivos e a contagem de cobranças', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $cartao, 30000, '2026-06-10');
    compraNaFatura($user, $cartao, 20000, '2026-06-12');

    $trace = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06')->trace;

    expect($trace->ferramenta)->toBe('consultar_fatura_cartao')
        ->and($trace->filtros['cartao'])->toBe('cartão pai')
        ->and($trace->filtros['competencia'])->toBe('2026-06')
        ->and($trace->registros)->toBe(2);
});

it('expõe um payload para o guard com o total e o valor de cada cobrança', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);

    compraNaFatura($user, $cartao, 30000, '2026-06-10');
    compraNaFatura($user, $cartao, 20000, '2026-06-12');

    $payload = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-06')->payload();

    expect($payload)->toBeInstanceOf(PayloadDeResposta::class)
        ->and($payload->permiteValor(50000))->toBeTrue() // total
        ->and($payload->permiteValor(30000))->toBeTrue() // cobrança 1
        ->and($payload->permiteValor(20000))->toBeTrue() // cobrança 2
        ->and($payload->permiteValor(123456))->toBeFalse(); // valor inventado
});

// ---- Assinaturas recorrentes na fatura (spec 12, R8) -------------------------------------

it('itemiza a ocorrência de recorrência do cartão e a soma no total, mesmo já paga (R8)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create([
        'descricao' => 'cartão pai', 'final_4' => '1234',
        'dia_fechamento' => 20, 'dia_vencimento' => 28,
    ]);
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 5590,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $cartao->id, 'dia' => 25, 'proxima_em' => null,
    ]);

    compraNaFatura($user, $cartao, 30000, '2026-08-28');

    // Cobrança em 25/07 (após o fechamento) cai na fatura que vence em 28/08 ⇒ competência 08.
    RecurrenceOccurrence::factory()->pago()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-08',
        'descricao' => 'Netflix', 'valor_cents' => 5590,
        'data_cobranca' => '2026-07-25', 'vencimento' => '2026-08-28',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $cartao->id,
    ]);

    $fatura = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-08');

    expect($fatura->totalCents)->toBe(35590)
        ->and($fatura->itens)->toHaveCount(2)
        ->and(collect($fatura->itens)->firstWhere('recorrente', true))->toMatchArray([
            'descricao' => 'Netflix',
            'vencimento' => '2026-08-28',
            'cents' => 5590,
        ]);
});

it('não coloca na fatura a ocorrência cancelada nem a de outra competência', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    $rec = Recurrence::factory()->for($user)->create([
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::CREDITO),
        'card_id' => $cartao->id, 'proxima_em' => null,
    ]);

    RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-08',
        'descricao' => 'Cancelada', 'valor_cents' => 5590,
        'data_cobranca' => '2026-07-25', 'vencimento' => '2026-08-28',
        'card_id' => $cartao->id, 'status_id' => StatusPagamento::idFor(StatusPagamento::CANCELADO),
    ]);
    RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-09',
        'descricao' => 'Outro mês', 'valor_cents' => 5590,
        'data_cobranca' => '2026-08-25', 'vencimento' => '2026-09-28',
        'card_id' => $cartao->id,
    ]);

    $fatura = app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-08');

    expect($fatura->totalCents)->toBe(0)
        ->and($fatura->itens)->toBe([]);
});

it('não vaza a ocorrência de cartão de outro usuário na fatura', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'cartão pai', 'final_4' => '1234']);
    $rec = Recurrence::factory()->for($outro)->create(['card_id' => $cartao->id, 'proxima_em' => null]);

    RecurrenceOccurrence::factory()->create([
        'user_id' => $outro->id, 'recurrence_id' => $rec->id, 'competencia' => '2026-08',
        'descricao' => 'Alheia', 'valor_cents' => 999900,
        'data_cobranca' => '2026-07-25', 'vencimento' => '2026-08-28',
        'card_id' => $cartao->id,
    ]);

    expect(app(ConsultarFaturaCartao::class)->para($user->id, 'cartão pai', '2026-08')->totalCents)->toBe(0);
});
