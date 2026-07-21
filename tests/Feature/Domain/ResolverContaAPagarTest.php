<?php

declare(strict_types=1);

use App\Domain\Pagamento\ContaPagavel;
use App\Domain\Pagamento\ResolverContaAPagar;
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
 * "Paguei a luz" pelo bot (decisão do usuário 2026-07-21). Esta é a peça DETERMINÍSTICA do
 * fluxo: a IA só extrai o termo que o usuário disse ("luz"); QUEM é a conta, quanto vale e
 * quando vence sai daqui, do banco — a IA nunca escolhe nem calcula (regra 4).
 *
 * Busca nas duas fontes de conta a pagar (parcela de lançamento e ocorrência de recorrência),
 * sempre FORA DE CARTÃO — a fatura é quem quita (§4.3 / D3) — e só no que ainda não foi pago.
 * Ordena por vencimento asc: a conta mais atrasada é a que o usuário quer quitar. Escopo
 * ESTRITO por usuário. Devolver mais de um candidato é resposta válida: quem desempata é o
 * usuário, nunca o modelo.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function parcelaAPagar(User $user, string $descricao, string $venc, ?Card $card = null, ?string $status = null): Installment
{
    $tx = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => 12000,
        'card_id' => $card?->id,
        'payment_method_id' => PaymentMethod::idFor($card !== null ? PaymentMethod::CREDITO : PaymentMethod::PIX),
    ]);

    return Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor($status ?? StatusPagamento::ABERTO),
    ]);
}

function ocorrenciaAPagar(User $user, string $descricao, string $venc, ?string $status = null): RecurrenceOccurrence
{
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_cents' => 9000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'proxima_em' => null,
    ]);

    return RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id,
        'recurrence_id' => $rec->id,
        'competencia' => substr($venc, 0, 7),
        'descricao' => $descricao,
        'valor_cents' => 9000,
        'data_cobranca' => $venc,
        'vencimento' => $venc,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'status_id' => StatusPagamento::idFor($status ?? StatusPagamento::ABERTO),
    ]);
}

function hojePagamento(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-21', 'America/Sao_Paulo');
}

it('acha a conta pelo termo, com valor e vencimento vindos do banco', function () {
    $user = User::factory()->create();
    $parcela = parcelaAPagar($user, 'Conta de luz Enel', '2026-07-15');

    $achadas = app(ResolverContaAPagar::class)->para($user->id, 'luz', hojePagamento());

    expect($achadas)->toHaveCount(1)
        ->and($achadas[0])->toBeInstanceOf(ContaPagavel::class)
        ->and($achadas[0]->tipo)->toBe(ContaPagavel::TIPO_PARCELA)
        ->and($achadas[0]->id)->toBe($parcela->id)
        ->and($achadas[0]->descricao)->toBe('Conta de luz Enel')
        ->and($achadas[0]->cents)->toBe(12000)
        ->and($achadas[0]->vencimento->toDateString())->toBe('2026-07-15');
});

it('acha também a ocorrência de recorrência', function () {
    $user = User::factory()->create();
    $oc = ocorrenciaAPagar($user, 'Aluguel', '2026-07-05');

    $achadas = app(ResolverContaAPagar::class)->para($user->id, 'aluguel', hojePagamento());

    expect($achadas)->toHaveCount(1)
        ->and($achadas[0]->tipo)->toBe(ContaPagavel::TIPO_OCORRENCIA)
        ->and($achadas[0]->id)->toBe($oc->id)
        ->and($achadas[0]->cents)->toBe(9000);
});

it('busca sem diferenciar maiúsculas e acha por pedaço da descrição', function () {
    $user = User::factory()->create();
    parcelaAPagar($user, 'INTERNET Vivo Fibra', '2026-07-10');

    expect(app(ResolverContaAPagar::class)->para($user->id, 'internet', hojePagamento()))->toHaveCount(1);
});

it('devolve os dois candidatos ordenados por vencimento (mais antigo primeiro)', function () {
    // Duas contas casam com "luz": quem desempata é o usuário, não o modelo.
    $user = User::factory()->create();
    parcelaAPagar($user, 'Luz de julho', '2026-07-15');
    parcelaAPagar($user, 'Luz de junho', '2026-06-15');

    $achadas = app(ResolverContaAPagar::class)->para($user->id, 'luz', hojePagamento());

    expect($achadas)->toHaveCount(2)
        ->and($achadas[0]->descricao)->toBe('Luz de junho')
        ->and($achadas[1]->descricao)->toBe('Luz de julho');
});

it('ignora conta em CARTÃO (a fatura é que quita)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 25]);
    parcelaAPagar($user, 'Luz no cartão', '2026-07-15', $card);

    expect(app(ResolverContaAPagar::class)->para($user->id, 'luz', hojePagamento()))->toBe([]);
});

it('ignora o que já está pago ou cancelado', function () {
    $user = User::factory()->create();
    parcelaAPagar($user, 'Luz paga', '2026-07-15', status: StatusPagamento::PAGO);
    parcelaAPagar($user, 'Luz cancelada', '2026-07-16', status: StatusPagamento::CANCELADO);
    ocorrenciaAPagar($user, 'Luz recorrente paga', '2026-07-17', status: StatusPagamento::PAGO);

    expect(app(ResolverContaAPagar::class)->para($user->id, 'luz', hojePagamento()))->toBe([]);
});

it('nunca acha conta de outro usuário', function () {
    $user = User::factory()->create();
    parcelaAPagar(User::factory()->create(), 'Luz do vizinho', '2026-07-15');

    expect(app(ResolverContaAPagar::class)->para($user->id, 'luz', hojePagamento()))->toBe([]);
});

it('devolve vazio quando o termo não casa com nada', function () {
    $user = User::factory()->create();
    parcelaAPagar($user, 'Internet', '2026-07-15');

    expect(app(ResolverContaAPagar::class)->para($user->id, 'condomínio', hojePagamento()))->toBe([]);
});

it('trata o curinga do usuário como texto, não como busca-tudo', function () {
    // "%" digitado pelo usuário não pode virar "traga todas as contas".
    $user = User::factory()->create();
    parcelaAPagar($user, 'Internet', '2026-07-15');

    expect(app(ResolverContaAPagar::class)->para($user->id, '%', hojePagamento()))->toBe([]);
});

it('ignora termo vazio em vez de listar tudo', function () {
    $user = User::factory()->create();
    parcelaAPagar($user, 'Internet', '2026-07-15');

    expect(app(ResolverContaAPagar::class)->para($user->id, '   ', hojePagamento()))->toBe([]);
});

it('não olha meses distantes no futuro (a conta de dezembro não é "a que paguei hoje")', function () {
    $user = User::factory()->create();
    parcelaAPagar($user, 'Internet', '2026-12-10');

    expect(app(ResolverContaAPagar::class)->para($user->id, 'internet', hojePagamento()))->toBe([]);
});
