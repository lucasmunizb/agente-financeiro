<?php

declare(strict_types=1);

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Recorrencia\CancelarRecorrencia;
use App\Domain\Recorrencia\ConsultarRecorrencias;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\OcorrenciaMensal;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Domain\Recorrencia\SincronizarRecorrencia;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Molde da recorrência mensal (spec 10, revisto pela spec 12): cadastrar cria a regra `ativo`
 * E a ocorrência do mês corrente (D2) — nunca um lançamento. O ponteiro `proxima_em` passou a
 * ser o 1º dia do primeiro MÊS ainda não gerado. A fila de confirmações saiu de cena (D1),
 * junto com a cascata "rejeitar → cancela". O ciclo da ocorrência em si vive em
 * RecorrenciaOcorrenciaTest / OcorrenciaCartaoTest / PagarOcorrenciaTest.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function dadosRecorrencia(User $user, array $over = []): DadosRecorrencia
{
    return new DadosRecorrencia(
        userId: $user->id,
        descricao: $over['descricao'] ?? 'Netflix',
        valorCents: $over['valorCents'] ?? 5590,
        paymentMethodId: $over['paymentMethodId'] ?? PaymentMethod::idFor(PaymentMethod::PIX),
        dia: $over['dia'] ?? 5,
        categoriaId: $over['categoriaId'] ?? null,
        cardId: $over['cardId'] ?? null,
    );
}

// ---- OcorrenciaMensal (helper determinístico) ------------------------------------------

it('resolve a próxima ocorrência no mesmo mês quando o dia ainda não passou', function () {
    $data = CarbonImmutable::parse('2026-07-09', 'America/Sao_Paulo');

    expect(OcorrenciaMensal::aPartirDe(20, $data)->format('Y-m-d'))->toBe('2026-07-20')
        ->and(OcorrenciaMensal::aPartirDe(9, $data)->format('Y-m-d'))->toBe('2026-07-09'); // hoje conta
});

it('rola para o mês seguinte quando o dia já passou', function () {
    $data = CarbonImmutable::parse('2026-07-09', 'America/Sao_Paulo');

    expect(OcorrenciaMensal::aPartirDe(5, $data)->format('Y-m-d'))->toBe('2026-08-05');
});

it('clampa o dia ao último dia do mês (31 em fevereiro)', function () {
    $data = CarbonImmutable::parse('2026-02-01', 'America/Sao_Paulo');

    expect(OcorrenciaMensal::aPartirDe(31, $data)->format('Y-m-d'))->toBe('2026-02-28');
});

// ---- RegistrarRecorrencia ---------------------------------------------------------------

it('registra uma recorrência ativa, gera a ocorrência do mês e audita (C1/D2)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 5]), $hoje);

    expect($rec)->toBeInstanceOf(Recurrence::class)
        ->and($rec->user_id)->toBe($user->id)
        ->and($rec->status)->toBe(Recurrence::STATUS_ATIVO)
        ->and($rec->valor_cents)->toBe(5590)
        ->and($rec->periodicidade)->toBe(Recurrence::PERIODICIDADE_MENSAL)
        // O ponteiro é o 1º dia do primeiro MÊS ainda não gerado — julho já nasceu.
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-01');

    // O dia 5 já passou (hoje 9): a ocorrência de julho nasce vencida, não some.
    $oc = RecurrenceOccurrence::where('recurrence_id', $rec->id)->sole();
    expect($oc->competencia)->toBe('2026-07')
        ->and($oc->vencimento->toDateString())->toBe('2026-07-05')
        ->and(Transaction::count())->toBe(0);

    expect(AuditLog::where('entidade', 'recurrence')->where('entidade_id', $rec->id)
        ->where('acao', AuditLog::ACAO_CRIAR)->exists())->toBeTrue();
});

it('gera a ocorrência do mês também quando o dia ainda não chegou', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 20]), $hoje);

    expect(RecurrenceOccurrence::where('recurrence_id', $rec->id)->sole()->vencimento->toDateString())
        ->toBe('2026-07-20')
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-01');
});

it('aceita referência explícita para começar num mês futuro (nada gerado ainda)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $rec = (new RegistrarRecorrencia)->registrar(
        dadosRecorrencia($user, ['dia' => 20]),
        $hoje,
        $hoje->startOfMonth()->addMonthNoOverflow(),
    );

    expect($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-01')
        ->and(RecurrenceOccurrence::count())->toBe(0);
});

// ---- SincronizarRecorrencia ("este e os próximos") --------------------------------------

it('sincroniza o molde da recorrência ao propagar "este e os próximos" e recalcula o dia', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 4500,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'categoria_id' => null, 'dia' => 10, 'proxima_em' => '2026-08-01',
        'status' => Recurrence::STATUS_ATIVO,
    ]);
    $novos = new DadosGastoManual(
        userId: $user->id, descricao: 'Netflix 4K', valorTotalCents: 5500,
        dataCompra: CarbonImmutable::parse('2026-07-15', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
    );

    $ok = (new SincronizarRecorrencia)->sincronizar($rec, $novos);

    expect($ok)->toBeTrue();
    $rec->refresh();
    expect($rec->descricao)->toBe('Netflix 4K')
        ->and($rec->valor_cents)->toBe(5500)
        ->and($rec->dia)->toBe(15)
        // O ponteiro é um MÊS: trocar o dia-do-mês não o move (spec 12).
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-01')
        ->and(AuditLog::where('entidade', 'recurrence')->where('entidade_id', $rec->id)
            ->where('acao', AuditLog::ACAO_EDITAR)->exists())->toBeTrue();
});

it('não rebaixa o dia 31 do molde ao sincronizar uma ocorrência clampada (fev → 28)', function () {
    // Regra é "todo dia 31"; fevereiro resolveu clampado no dia 28. Editar SÓ o valor
    // dessa ocorrência ("este e os próximos") não pode reescrever o molde para dia 28.
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => 150000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'categoria_id' => null, 'dia' => 31, 'proxima_em' => '2026-03-01',
        'status' => Recurrence::STATUS_ATIVO,
    ]);
    $novos = new DadosGastoManual(
        userId: $user->id, descricao: 'Aluguel', valorTotalCents: 160000,
        dataCompra: CarbonImmutable::parse('2026-02-28', 'America/Sao_Paulo'), // clamp de fev
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
    );

    (new SincronizarRecorrencia)->sincronizar($rec, $novos);

    $rec->refresh();
    expect($rec->valor_cents)->toBe(160000)
        ->and($rec->dia)->toBe(31); // o molde continua "todo dia 31"
});

it('muda o dia do molde quando a edição escolheu de fato outro dia', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => 150000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'categoria_id' => null, 'dia' => 31, 'proxima_em' => '2026-03-01',
        'status' => Recurrence::STATUS_ATIVO,
    ]);
    $novos = new DadosGastoManual(
        userId: $user->id, descricao: 'Aluguel', valorTotalCents: 150000,
        dataCompra: CarbonImmutable::parse('2026-02-10', 'America/Sao_Paulo'), // dia 10 de verdade
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
    );

    (new SincronizarRecorrencia)->sincronizar($rec, $novos);

    expect($rec->fresh()->dia)->toBe(10);
});

it('não sincroniza recorrência cancelada (não há futuro a alterar)', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->cancelado()->create(['valor_cents' => 4500]);
    $novos = new DadosGastoManual(
        userId: $user->id, descricao: 'X', valorTotalCents: 9999,
        dataCompra: CarbonImmutable::parse('2026-07-15', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
    );

    expect((new SincronizarRecorrencia)->sincronizar($rec, $novos))->toBeFalse()
        ->and($rec->fresh()->valor_cents)->toBe(4500); // intacta
});

// ---- Cancelar ---------------------------------------------------------------------------

it('cancela a recorrência: status cancelado, ponteiro nulo, audita e é idempotente (C8)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);

    $ok = (new CancelarRecorrencia)->cancelar($rec->id, $user->id, $hoje);
    $segunda = (new CancelarRecorrencia)->cancelar($rec->id, $user->id, $hoje);

    expect($ok)->toBeTrue()
        ->and($segunda)->toBeFalse()
        ->and($rec->fresh()->status)->toBe(Recurrence::STATUS_CANCELADO)
        ->and($rec->fresh()->proxima_em)->toBeNull();

    expect(AuditLog::where('entidade', 'recurrence')->where('entidade_id', $rec->id)
        ->where('acao', AuditLog::ACAO_CANCELAR)->exists())->toBeTrue();
});

it('não cancela recorrência de outro usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);

    (new CancelarRecorrencia)->cancelar($rec->id, $outro->id, $hoje);
})->throws(ModelNotFoundException::class);

// ---- ConsultarRecorrencias (gerenciar) --------------------------------------------------

it('lista só as recorrências ativas do usuário, ordenadas por dia e isoladas', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');

    (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 20, 'descricao' => 'Aluguel']), $hoje);
    (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 5, 'descricao' => 'Netflix']), $hoje);
    $cancelada = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9, 'descricao' => 'Antiga']), $hoje);
    (new CancelarRecorrencia)->cancelar($cancelada->id, $user->id, $hoje);
    (new RegistrarRecorrencia)->registrar(dadosRecorrencia($outro, ['dia' => 1, 'descricao' => 'Alheia']), $hoje);

    $lista = (new ConsultarRecorrencias)->para($user->id);

    // Ordenado por dia (5 antes de 20); sem a cancelada nem a de outro usuário.
    expect($lista)->toHaveCount(2)
        ->and($lista->pluck('descricao')->all())->toBe(['Netflix', 'Aluguel']);
});
