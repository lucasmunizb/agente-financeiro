<?php

declare(strict_types=1);

use App\Domain\ContasVencidas\ConsultarContasVencidas;
use App\Domain\Dashboard\ResumoDoMes;
use App\Domain\Dashboard\ResumoDoMesResultado;
use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Domain\FaturaCartao\ConsultarFaturaCartao;
use App\Domain\Gastos\ConsultarGastos;
use App\Domain\ProximasContas\ConsultarProximasContas;
use App\Models\Card;
use App\Models\Income;
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
 * Spec 06 — Dashboard (agregações do mês). O `ResumoDoMes` é um orquestrador read-only
 * que monta os números do mês corrente DELEGANDO às 4 consultas determinísticas já
 * testadas (gastos, próximas contas, fatura, disponível) — NÃO recalcula nada (regra 4),
 * tudo em centavos inteiros (regra 5), escopo ESTRITO por usuário e "hoje" INJETADO
 * (nunca o relógio global). A IA não participa desta etapa.
 *
 * Decisões de regra registradas nesta implementação (spec §10):
 *  - "cartão atual" = TODOS os cartões (ativos) do usuário, inclusive os de fatura zerada;
 *  - janela default de próximas contas = 30 dias (parâmetro injetável).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Instante de referência fixo (fuso SP) usado como "hoje" no resumo. */
function hojeSP(string $data = '2026-06-15'): CarbonImmutable
{
    return CarbonImmutable::parse($data.' 09:00:00', 'America/Sao_Paulo');
}

/** Receita recebida na data dada. */
function resumoReceita(User $user, int $valorCents, string $data): Income
{
    return Income::factory()->for($user)->create(['valor_cents' => $valorCents, 'data' => $data]);
}

/**
 * Gasto de parcela única vencendo na data dada. Opcionalmente em cartão (card_id) e com
 * status específico. Devolve a transaction.
 */
function resumoParcela(
    User $user,
    int $valorCents,
    string $vencimento,
    ?Card $cartao = null,
    string $statusCodigo = StatusPagamento::ABERTO,
): Transaction {
    $transaction = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
        'card_id' => $cartao?->id,
    ]);

    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1,
        'total' => 1,
        'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor($statusCodigo),
    ]);

    return $transaction;
}

it('agrega gastos, próximas contas, fatura e disponível do mês corrente (C1)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    resumoReceita($user, 400000, '2026-06-05');
    resumoParcela($user, 120000, '2026-06-20', $cartao);  // gasto + próxima conta + fatura
    resumoParcela($user, 20000, '2026-06-10');            // gasto fora de cartão (já venceu p/ próximas)

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    expect($resumo)->toBeInstanceOf(ResumoDoMesResultado::class)
        ->and($resumo->mes)->toBe('2026-06')
        ->and($resumo->totalGastosCents())->toBe(140000)            // 1200 + 200
        ->and($resumo->totalProximasContasCents())->toBe(120000)    // só a de 2026-06-20 (futura)
        ->and($resumo->disponivelCents())->toBe(260000)             // 4000 - 1200 - 200
        ->and($resumo->faturas)->toHaveCount(1)
        ->and($resumo->faturas[0]->totalCents)->toBe(120000);
});

it('não vaza dados de outro usuário (C1 — escopo)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    Card::factory()->for($outro)->create(['descricao' => 'Alheio', 'final_4' => '9999']);

    resumoReceita($user, 100000, '2026-06-05');
    resumoParcela($user, 30000, '2026-06-20');

    resumoReceita($outro, 999900, '2026-06-05');
    resumoParcela($outro, 555500, '2026-06-20');

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    expect($resumo->totalGastosCents())->toBe(30000)
        ->and($resumo->disponivelCents())->toBe(70000)   // 1000 - 300, sem o do outro
        ->and($resumo->faturas)->toHaveCount(0);          // o cartão é do outro usuário
});

it('reusa as consultas existentes — os números batem com elas isoladas, sem recálculo (C2)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    resumoReceita($user, 400000, '2026-06-05');
    resumoParcela($user, 120000, '2026-06-20', $cartao);
    resumoParcela($user, 20000, '2026-06-10');

    $hoje = hojeSP();
    $resumo = app(ResumoDoMes::class)->para($user->id, $hoje);

    $gastos = app(ConsultarGastos::class)->para($user->id, '2026-06');
    $proximas = app(ConsultarProximasContas::class)->para($user->id, $hoje, 30);
    $disponivel = app(ConsultarDisponivelDoMes::class)->para($user->id, '2026-06');
    $fatura = app(ConsultarFaturaCartao::class)->para($user->id, '1234', '2026-06');

    expect($resumo->totalGastosCents())->toBe($gastos->totalCents)
        ->and($resumo->totalProximasContasCents())->toBe($proximas->totalCents)
        ->and($resumo->disponivelCents())->toBe($disponivel->disponivel->disponivel->cents())
        ->and($resumo->faturas[0]->totalCents)->toBe($fatura->totalCents);
});

it('os recortes mudam de forma determinística com o "hoje" injetado (C3)', function () {
    $user = User::factory()->create();

    resumoParcela($user, 50000, '2026-06-20');
    resumoParcela($user, 70000, '2026-07-20');

    $junho = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'));
    $julho = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-07-15'));

    expect($junho->mes)->toBe('2026-06')
        ->and($junho->totalGastosCents())->toBe(50000)
        ->and($julho->mes)->toBe('2026-07')
        ->and($julho->totalGastosCents())->toBe(70000);
});

it('é determinístico: o mesmo "hoje" produz o mesmo resultado (C3)', function () {
    $user = User::factory()->create();
    resumoParcela($user, 50000, '2026-06-20');

    $a = app(ResumoDoMes::class)->para($user->id, hojeSP());
    $b = app(ResumoDoMes::class)->para($user->id, hojeSP());

    expect($a->totalGastosCents())->toBe($b->totalGastosCents())
        ->and($a->totalProximasContasCents())->toBe($b->totalProximasContasCents())
        ->and($a->disponivelCents())->toBe($b->disponivelCents());
});

it('usuário sem cartão e sem movimento devolve um VO bem-formado com zeros e listas vazias (C4)', function () {
    $user = User::factory()->create();

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    expect($resumo)->toBeInstanceOf(ResumoDoMesResultado::class)
        ->and($resumo->mes)->toBe('2026-06')
        ->and($resumo->totalGastosCents())->toBe(0)
        ->and($resumo->totalProximasContasCents())->toBe(0)
        ->and($resumo->disponivelCents())->toBe(0)
        ->and($resumo->faturas)->toBe([])
        ->and($resumo->proximasContas->contas)->toBe([])
        ->and($resumo->gastos->porCategoria)->toBe([]);
});

it('lista uma fatura por cartão do usuário, inclusive a de total zero (decisão §10)', function () {
    $user = User::factory()->create();
    $comMovimento = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);
    Card::factory()->for($user)->create(['descricao' => 'Inter', 'final_4' => '5678']); // sem movimento

    resumoParcela($user, 90000, '2026-06-20', $comMovimento);

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    $totais = collect($resumo->faturas)->pluck('totalCents')->sort()->values()->all();

    expect($resumo->faturas)->toHaveCount(2)
        ->and($totais)->toBe([0, 90000]);
});

it('distingue as faturas de dois cartões com o MESMO final_4 (resolve por id)', function () {
    $user = User::factory()->create();
    $nubank = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);
    $inter = Card::factory()->for($user)->create(['descricao' => 'Inter', 'final_4' => '1234']);

    resumoParcela($user, 10000, '2026-06-20', $nubank);
    resumoParcela($user, 25000, '2026-06-20', $inter);

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    $porDescricao = collect($resumo->faturas)->keyBy('cartaoDescricao');
    expect($resumo->faturas)->toHaveCount(2)
        ->and($porDescricao->get('Nubank')?->totalCents)->toBe(10000)
        ->and($porDescricao->get('Inter')?->totalCents)->toBe(25000);
});

it('ignora cartões excluídos (soft delete) na lista de faturas', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);
    $excluido = Card::factory()->for($user)->create(['descricao' => 'Antigo', 'final_4' => '0000']);
    $excluido->delete();

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    expect($resumo->faturas)->toHaveCount(1);
});

it('todo valor do VO é int de centavos — nada de float (regra 5)', function () {
    $user = User::factory()->create();
    $cartao = Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    resumoReceita($user, 400000, '2026-06-05');
    resumoParcela($user, 120000, '2026-06-20', $cartao);

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    expect($resumo->totalGastosCents())->toBeInt()
        ->and($resumo->totalProximasContasCents())->toBeInt()
        ->and($resumo->disponivelCents())->toBeInt()
        ->and($resumo->totalFaturasCents())->toBeInt()
        ->and($resumo->proximasContas->contas[0]['cents'])->toBeInt()
        ->and($resumo->faturas[0]->totalCents)->toBeInt();
});

it('expõe os traces (fontes) de cada consulta para auditoria', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['descricao' => 'Nubank', 'final_4' => '1234']);

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    $ferramentas = collect($resumo->traces())->pluck('ferramenta')->all();

    expect($ferramentas)->toContain('consultar_gastos')
        ->toContain('consultar_proximas_contas')
        ->toContain('consultar_disponivel_mes')
        ->toContain('consultar_fatura_cartao')
        ->toContain('consultar_contas_vencidas');
});

it('agrega também as contas em atraso, batendo com a consulta isolada (C8 — spec 06b)', function () {
    $user = User::factory()->create();

    resumoParcela($user, 30000, '2026-06-05');  // já venceu (antes de hojeSP 2026-06-15)
    resumoParcela($user, 18000, '2026-06-10');  // já venceu
    resumoParcela($user, 90000, '2026-06-20');  // a vencer

    $hoje = hojeSP();
    $resumo = app(ResumoDoMes::class)->para($user->id, $hoje);
    $vencidas = app(ConsultarContasVencidas::class)->para($user->id, $hoje);

    expect($resumo->totalContasVencidasCents())->toBe(48000)                 // 300 + 180
        ->and($resumo->totalContasVencidasCents())->toBe($vencidas->totalCents) // reuso, não recálculo
        ->and($resumo->totalContasVencidasCents())->toBeInt()                // regra 5
        ->and($resumo->contasVencidas->contas)->toHaveCount(2);
});

it('usuário sem nada em atraso tem contasVencidas zeradas e lista vazia (C8 — borda)', function () {
    $user = User::factory()->create();

    resumoParcela($user, 90000, '2026-06-20'); // só futura

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP());

    expect($resumo->totalContasVencidasCents())->toBe(0)
        ->and($resumo->contasVencidas->contas)->toBe([]);
});

// ---- Previsão de recorrências na visão de mês FUTURO (spec 10b) --------------------------

/** Recorrência ativa fora de cartão com ponteiro na 1ª ocorrência dada. */
function resumoRecorrencia(User $user, int $valorCents, int $dia, string $proximaEm, string $descricao = 'Netflix'): Recurrence
{
    return Recurrence::factory()->for($user)->create([
        'descricao' => $descricao, 'valor_cents' => $valorCents, 'dia' => $dia,
        'status' => Recurrence::STATUS_ATIVO, 'proxima_em' => $proximaEm,
    ]);
}

/** Ocorrência já materializada aguardando confirmação na fila (o ponteiro do molde já avançou). */
/** A OCORRÊNCIA real de uma recorrência num mês (spec 12) — o que o agendador gera. */
function resumoOcorrencia(User $user, Recurrence $recorrencia, string $vencimento, int $cents, string $descricao, ?string $status = null): RecurrenceOccurrence
{
    return RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id,
        'recurrence_id' => $recorrencia->id,
        'competencia' => substr($vencimento, 0, 7),
        'descricao' => $descricao,
        'valor_cents' => $cents,
        'data_cobranca' => $vencimento,
        'vencimento' => $vencimento,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::BOLETO),
        'status_id' => StatusPagamento::idFor($status ?? StatusPagamento::ABERTO),
    ]);
}

it('na visão de mês FUTURO injeta as recorrências previstas nas próximas contas e abate o disponível (P1/P2/P10)', function () {
    $user = User::factory()->create();

    resumoReceita($user, 400000, '2026-08-05');       // receita do mês futuro
    resumoParcela($user, 50000, '2026-08-20');        // gasto real vencendo no mês futuro
    resumoRecorrencia($user, 5590, 5, '2026-08-05');  // conta fixa prevista (dia 5)

    $agora = hojeSP('2026-06-15');                     // hoje real
    $ancora = hojeSP('2026-08-01');                    // mês navegado (futuro)
    $resumo = app(ResumoDoMes::class)->para($user->id, $ancora, 30, $agora);

    // Próximas contas = real (Aug 20) + prevista (Aug 5), ordenadas por vencimento.
    expect($resumo->totalProximasContasCents())->toBe(55590)
        ->and($resumo->proximasContas->contas)->toHaveCount(2)
        ->and($resumo->proximasContas->contas[0]['descricao'])->toBe('Netflix')
        ->and($resumo->proximasContas->contas[0]['vencimento'])->toBe('2026-08-05')
        ->and($resumo->proximasContas->contas[0]['prevista'])->toBeTrue()
        ->and($resumo->proximasContas->contas[1]['vencimento'])->toBe('2026-08-20')
        ->and($resumo->proximasContas->contas[1]['prevista'])->toBeFalse();

    // Disponível projetado abate o gasto real (500) + a prevista (55,90): 4000 - 500 - 55,90.
    expect($resumo->disponivelCents())->toBe(344410);
});

it('no MÊS CORRENTE lista a recorrência cujo dia ainda não chegou e abate o disponível', function () {
    $user = User::factory()->create();

    resumoReceita($user, 400000, '2026-06-05');
    resumoParcela($user, 50000, '2026-06-20');
    resumoRecorrencia($user, 5590, 20, '2026-06-20'); // conta fixa deste mês, ainda não materializada

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'));

    expect($resumo->totalProximasContasCents())->toBe(55590)      // real (500) + prevista (55,90)
        ->and($resumo->disponivelCents())->toBe(344410)           // 4000 - 500 - 55,90
        ->and($resumo->proximasContas->contas)->toHaveCount(2);

    $previstas = collect($resumo->proximasContas->contas)->firstWhere('prevista', true);
    expect($previstas['descricao'])->toBe('Netflix')
        ->and($previstas['vencimento'])->toBe('2026-06-20');
});

it('no MÊS CORRENTE não conta em dobro a competência já gerada (R2/R4)', function () {
    $user = User::factory()->create();

    resumoReceita($user, 400000, '2026-06-05');
    // Junho já foi gerado (o ponteiro está em julho): a projeção o exclui por NOT EXISTS.
    $rec = resumoRecorrencia($user, 5590, 20, '2026-07-01');
    resumoOcorrencia($user, $rec, '2026-06-20', 5590, 'Netflix');

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'));

    // Uma linha só: a ocorrência real.
    expect($resumo->proximasContas->contas)->toHaveCount(1)
        ->and($resumo->proximasContas->contas[0]['prevista'])->toBeFalse()
        ->and($resumo->totalProximasContasCents())->toBe(5590)
        ->and($resumo->disponivelCents())->toBe(394410);          // 4000 - 55,90
});

it('a ocorrência JÁ PAGA sai do quadro a vencer mas segue abatendo o disponível', function () {
    $user = User::factory()->create();

    resumoReceita($user, 400000, '2026-06-05');
    $rec = resumoRecorrencia($user, 5590, 20, '2026-07-01');
    resumoOcorrencia($user, $rec, '2026-06-20', 5590, 'Netflix', StatusPagamento::PAGO);

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'));

    expect($resumo->proximasContas->contas)->toHaveCount(0)
        // Pago é gasto igual: continua fora do dinheiro livre do mês.
        ->and($resumo->disponivelCents())->toBe(394410);
});

it('lista no quadro "em atraso" a ocorrência vencida e não paga', function () {
    $user = User::factory()->create();

    $rec = resumoRecorrencia($user, 5590, 5, '2026-07-01'); // junho já gerado
    resumoOcorrencia($user, $rec, '2026-06-05', 5590, 'Netflix'); // venceu dia 5, hoje é 15

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'));

    expect($resumo->contasVencidas->contas)->toHaveCount(1)
        ->and($resumo->totalContasVencidasCents())->toBe(5590)
        ->and($resumo->contasVencidas->contas[0])->toMatchArray([
            'descricao' => 'Netflix',
            'vencimento' => '2026-06-05',
            'prevista' => false,
            'recorrente' => true,
        ]);
});

it('na visão de mês FUTURO o donut (gastos por categoria) inclui as recorrências previstas', function () {
    $user = User::factory()->create();

    resumoParcela($user, 50000, '2026-08-20');        // gasto real vencendo no mês futuro
    resumoRecorrencia($user, 5590, 5, '2026-08-05');  // conta fixa prevista

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-08-01'), 30, hojeSP('2026-06-15'));

    // O donut soma o real (500) + a recorrência prevista (55,90) — bate com o extrato.
    expect($resumo->gastos->totalCents)->toBe(55590);
});

it('no MÊS CORRENTE o donut também inclui a recorrência prevista — mesma verdade do quadro', function () {
    $user = User::factory()->create();

    resumoParcela($user, 50000, '2026-06-20');
    resumoRecorrencia($user, 5590, 20, '2026-06-20'); // conta fixa deste mês, ainda não materializada

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'));

    expect($resumo->gastos->totalCents)->toBe(55590);
});

it('a previsão respeita o escopo por usuário na visão futura (P8)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    resumoRecorrencia($user, 5590, 5, '2026-08-05', 'Minha');
    resumoRecorrencia($outro, 999900, 5, '2026-08-05', 'Alheia');

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-08-01'), 30, hojeSP('2026-06-15'));

    expect($resumo->totalProximasContasCents())->toBe(5590)
        ->and($resumo->proximasContas->contas)->toHaveCount(1)
        ->and($resumo->proximasContas->contas[0]['descricao'])->toBe('Minha');
});

it('não lista no "a vencer" a recorrência prevista fora da janela, mas segue abatendo o disponível', function () {
    $user = User::factory()->create();

    resumoReceita($user, 400000, '2026-06-05');
    resumoRecorrencia($user, 5590, 28, '2026-06-28', 'Netflix'); // dia 28: fora da janela de 5 dias

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'), 5);

    // O quadro é "o que vence na janela"; o disponível é do MÊS inteiro (§4.5).
    expect($resumo->proximasContas->contas)->toHaveCount(0)
        ->and($resumo->totalProximasContasCents())->toBe(0)
        ->and($resumo->disponivelCents())->toBe(394410);
});

it('não lista no "a vencer" a recorrência prevista que já venceu antes de hoje', function () {
    $user = User::factory()->create();

    resumoRecorrencia($user, 5590, 5, '2026-06-05', 'Netflix'); // dia 5, hoje é 15

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-15'));

    // A de JUNHO ficou para trás; a próxima que a janela alcança é a de julho (05/07).
    expect(array_column($resumo->proximasContas->contas, 'vencimento'))->toBe(['2026-07-05']);
});

it('traz as recorrências previstas do MÊS SEGUINTE quando a janela atravessa a virada do mês', function () {
    $user = User::factory()->create();

    // Hoje 21/06, janela de 15 dias ⇒ [21/06, 06/07]: a conta fixa do dia 5 de JULHO vence
    // dentro da janela, mesmo sendo de outra competência.
    resumoRecorrencia($user, 5590, 5, '2026-07-05', 'Netflix');

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-21'), 15);

    expect($resumo->proximasContas->contas)->toHaveCount(1)
        ->and($resumo->proximasContas->contas[0]['descricao'])->toBe('Netflix')
        ->and($resumo->proximasContas->contas[0]['vencimento'])->toBe('2026-07-05')
        ->and($resumo->proximasContas->contas[0]['prevista'])->toBeTrue()
        ->and($resumo->totalProximasContasCents())->toBe(5590);
});

it('a previsão do mês seguinte não contamina o disponível do mês navegado', function () {
    $user = User::factory()->create();

    resumoReceita($user, 400000, '2026-06-05');
    resumoRecorrencia($user, 5590, 5, '2026-07-05', 'Netflix'); // conta de julho

    $resumo = app(ResumoDoMes::class)->para($user->id, hojeSP('2026-06-21'), 15);

    // Aparece no quadro (vence na janela) mas NÃO abate junho: ela pesa em julho (§4.5).
    expect($resumo->proximasContas->contas)->toHaveCount(1)
        ->and($resumo->disponivelCents())->toBe(400000);
});
