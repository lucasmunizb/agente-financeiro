<?php

use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Domain\Disponivel\ResultadoConsultaDisponivel;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Models\Income;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Camada de consulta do "disponível do mês" (doc 02 §3.2 / §4.5). Tool com escopo
 * por usuário: lê do banco do usuário e devolve dados JÁ calculados + trace
 * (período, filtros, contagem). A IA não calcula — só redige sobre estes números.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Receita recebida no mês. */
function receita(User $user, int $valorCents, string $data): Income
{
    return Income::factory()->for($user)->create(['valor_cents' => $valorCents, 'data' => $data]);
}

/** Gasto de parcela única vencendo na data dada (cartão ou fora, indiferente para o disponível). */
function parcelaVencendo(User $user, int $valorCents, string $vencimento, string $statusCodigo = StatusPagamento::ABERTO): void
{
    $transaction = Transaction::factory()->for($user)->create(['valor_total_cents' => $valorCents]);

    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1,
        'total' => 1,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);
}

it('aplica a fórmula: disponível = receitas do mês − gastos vencendo no mês', function () {
    $user = User::factory()->create();

    receita($user, 400000, '2026-06-05');
    parcelaVencendo($user, 120000, '2026-06-10');
    parcelaVencendo($user, 20000, '2026-06-15');

    $resultado = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06');

    expect($resultado)->toBeInstanceOf(ResultadoConsultaDisponivel::class)
        ->and($resultado->disponivel->disponivel->cents())->toBe(260000); // 4000 - 1200 - 200
});

it('expõe o previsto do próximo mês (parcelas vencendo no mês seguinte)', function () {
    $user = User::factory()->create();

    receita($user, 400000, '2026-06-05');
    parcelaVencendo($user, 120000, '2026-06-10');
    parcelaVencendo($user, 30000, '2026-07-10'); // próximo mês

    $resultado = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06');

    expect($resultado->disponivel->disponivel->cents())->toBe(280000)     // 4000 - 1200 (só junho)
        ->and($resultado->disponivel->previstoProximoMes->cents())->toBe(30000);
});

it('isola por usuário: ignora receitas e gastos de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    receita($user, 100000, '2026-06-05');
    receita($outro, 999900, '2026-06-05');
    parcelaVencendo($outro, 555500, '2026-06-10');

    $resultado = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06');

    expect($resultado->disponivel->disponivel->cents())->toBe(100000);
});

it('exclui parcelas em status que não entram no cálculo', function () {
    $user = User::factory()->create();

    receita($user, 100000, '2026-06-05');
    parcelaVencendo($user, 10000, '2026-06-10', StatusPagamento::ABERTO);
    parcelaVencendo($user, 99900, '2026-06-10', StatusPagamento::CANCELADO);

    $resultado = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06');

    expect($resultado->disponivel->disponivel->cents())->toBe(90000); // 1000 - 100
});

it('carrega um trace com a ferramenta, o filtro de mês e a contagem de registros', function () {
    $user = User::factory()->create();

    receita($user, 400000, '2026-06-05');
    parcelaVencendo($user, 120000, '2026-06-10');
    parcelaVencendo($user, 20000, '2026-06-15');

    $trace = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06')->trace;

    expect($trace->ferramenta)->toBe('consultar_disponivel_mes')
        ->and($trace->filtros)->toBe(['mes' => '2026-06'])
        ->and($trace->registros)->toBe(3); // 1 receita + 2 parcelas do mês
});

it('expõe um payload para o guard com os valores calculados', function () {
    $user = User::factory()->create();

    receita($user, 400000, '2026-06-05');
    parcelaVencendo($user, 120000, '2026-06-10');

    $payload = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06')->payload();

    expect($payload)->toBeInstanceOf(PayloadDeResposta::class)
        ->and($payload->permiteValor(280000))->toBeTrue()  // disponível (4000 - 1200)
        ->and($payload->permiteValor(123456))->toBeFalse(); // valor inventado
});
