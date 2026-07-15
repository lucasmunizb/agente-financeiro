<?php

declare(strict_types=1);

use App\Domain\Confirmacao\ConfirmarPendente;
use App\Domain\Confirmacao\RejeitarPendente;
use App\Domain\Recorrencia\CancelarRecorrencia;
use App\Domain\Recorrencia\ConsultarRecorrencias;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\MaterializarRecorrencias;
use App\Domain\Recorrencia\OcorrenciaMensal;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Domain\Recorrencia\SincronizarRecorrencia;
use App\Domain\Gasto\DadosGastoManual;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Recorrência mensal (spec 10): assinaturas/contas fixas fora de cartão. Registrar cria a
 * regra `ativo`; o materializador ENFILEIRA 1 confirmação no dia (regra 7, sem auto-save) e
 * avança o ponteiro; confirmar reusa RegistrarGastoManual; rejeitar/cancelar encerram a regra.
 * Determinismo de "hoje" injetado; escopo estrito por user_id.
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

it('registra uma recorrência ativa com a próxima ocorrência e audita (C1)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 5]), $hoje);

    expect($rec)->toBeInstanceOf(Recurrence::class)
        ->and($rec->user_id)->toBe($user->id)
        ->and($rec->status)->toBe(Recurrence::STATUS_ATIVO)
        ->and($rec->valor_cents)->toBe(5590)
        ->and($rec->periodicidade)->toBe(Recurrence::PERIODICIDADE_MENSAL)
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-05'); // dia 5 já passou (hoje 9)

    expect(AuditLog::where('entidade', 'recurrence')->where('entidade_id', $rec->id)
        ->where('acao', AuditLog::ACAO_CRIAR)->exists())->toBeTrue();
});

it('registra com ocorrência neste mês quando o dia ainda não chegou', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 20]), $hoje);

    expect($rec->proxima_em->format('Y-m-d'))->toBe('2026-07-20');
});

it('aceita referência explícita p/ a 1ª ocorrência (começar no mês seguinte)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    // dia 20 cairia neste mês (20 >= 9); com a referência no mês seguinte, começa em agosto —
    // é o caso do form de gasto: o mês atual já é lançado como gasto avulso (sem duplicar).
    $rec = (new RegistrarRecorrencia)->registrar(
        dadosRecorrencia($user, ['dia' => 20]),
        $hoje,
        $hoje->startOfMonth()->addMonthNoOverflow(),
    );

    expect($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-20');
});

it('recusa recorrência em cartão de crédito (crédito usa parcelas) (C3)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    (new RegistrarRecorrencia)->registrar(
        dadosRecorrencia($user, ['paymentMethodId' => PaymentMethod::idFor(PaymentMethod::CREDITO)]),
        $hoje,
    );
})->throws(InvalidArgumentException::class);

// ---- MaterializarRecorrencias -----------------------------------------------------------

it('materializa: enfileira 1 confirmação no dia, avança o ponteiro e não grava lançamento (C4)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    // dia 9 = hoje → proxima_em cai HOJE, vencível de imediato.
    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);
    expect($rec->proxima_em->format('Y-m-d'))->toBe('2026-07-09');

    $n = (new MaterializarRecorrencias)->paraTodos($hoje);

    expect($n)->toBe(1)
        ->and(Transaction::where('user_id', $user->id)->count())->toBe(0); // regra 7: nada gravado

    $pendente = PendingConfirmation::where('user_id', $user->id)->sole();
    expect($pendente->origem)->toBe(PendingConfirmation::ORIGEM_RECORRENCIA)
        ->and($pendente->status)->toBe(PendingConfirmation::STATUS_PENDENTE)
        ->and($pendente->recurrence_id)->toBe($rec->id)
        ->and($pendente->expira_em)->toBeNull()
        ->and($pendente->payload['valorTotalCents'])->toBe(5590)
        ->and($pendente->payload['parcelas'])->toBe(1)
        ->and($pendente->payload['origem'])->toBe('recorrencia')
        ->and($pendente->payload['recurrenceId'])->toBe($rec->id) // o elo viaja no payload
        ->and($pendente->payload['dataCompra'])->toBe('2026-07-09');

    // Ponteiro avança para a ocorrência do mês seguinte.
    expect($rec->fresh()->proxima_em->format('Y-m-d'))->toBe('2026-08-09');
});

it('é idempotente: rodar de novo no mesmo dia não enfileira outra ocorrência (C5)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);

    (new MaterializarRecorrencias)->paraTodos($hoje);
    $segunda = (new MaterializarRecorrencias)->paraTodos($hoje);

    expect($segunda)->toBe(0)
        ->and(PendingConfirmation::where('user_id', $user->id)->count())->toBe(1);
});

it('não materializa 2× quando uma execução concorrente já avançou o ponteiro (corrida)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);

    // Worker B leu a recorrência ANTES de A materializar (leitura defasada).
    $stale = Recurrence::findOrFail($rec->id);

    (new MaterializarRecorrencias)->paraTodos($hoje); // worker A materializa

    // Worker B processa a leitura defasada: o re-check sob lock deve descartá-la —
    // sem confirmação duplicada e sem avançar o ponteiro de novo (mês pulado).
    $materializou = (new MaterializarRecorrencias)->materializarUma($stale, $hoje->startOfDay());

    expect($materializou)->toBeFalse()
        ->and(PendingConfirmation::where('user_id', $user->id)->count())->toBe(1)
        ->and($rec->fresh()->proxima_em->format('Y-m-d'))->toBe('2026-08-09');
});

it('não materializa recorrência com ocorrência no futuro (C10)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 20]), $hoje); // 2026-07-20

    $n = (new MaterializarRecorrencias)->paraTodos($hoje);

    expect($n)->toBe(0)
        ->and(PendingConfirmation::where('user_id', $user->id)->count())->toBe(0);
});

it('não materializa recorrência cancelada', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);
    (new CancelarRecorrencia)->cancelar($rec->id, $user->id, $hoje);

    $n = (new MaterializarRecorrencias)->paraTodos($hoje);

    expect($n)->toBe(0)
        ->and(PendingConfirmation::where('user_id', $user->id)->count())->toBe(0);
});

it('isola a materialização por usuário (C9)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9, 'descricao' => 'Meu']), $hoje);
    (new RegistrarRecorrencia)->registrar(dadosRecorrencia($outro, ['dia' => 9, 'descricao' => 'Alheio']), $hoje);

    (new MaterializarRecorrencias)->paraTodos($hoje);

    expect(PendingConfirmation::where('user_id', $user->id)->sole()->payload['descricao'])->toBe('Meu')
        ->and(PendingConfirmation::where('user_id', $outro->id)->sole()->payload['descricao'])->toBe('Alheio');
});

// ---- Confirmar (reuso da fila) ----------------------------------------------------------

it('confirmar o pendente vira lançamento com origem recorrencia (C6)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);
    (new MaterializarRecorrencias)->paraTodos($hoje);
    $pendente = PendingConfirmation::where('user_id', $user->id)->sole();

    $tx = (new ConfirmarPendente)->confirmar($pendente->id, $user->id, $hoje);

    expect($tx)->toBeInstanceOf(Transaction::class)
        ->and($tx->origem)->toBe('recorrencia')
        ->and($tx->valor_total_cents)->toBe(5590)
        ->and($tx->installments)->toHaveCount(1)
        // O lançamento carrega o VÍNCULO com a recorrência de origem (não só a procedência).
        ->and($tx->recurrence_id)->toBe($rec->id)
        ->and($tx->ehRecorrente())->toBeTrue()
        ->and($tx->recurrence->is($rec))->toBeTrue();
});

// ---- SincronizarRecorrencia ("este e os próximos") --------------------------------------

it('sincroniza o molde da recorrência ao propagar "este e os próximos" e recalcula o dia', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Netflix', 'valor_cents' => 4500,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'categoria_id' => null, 'dia' => 10, 'proxima_em' => '2026-08-10',
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
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-08-15') // novo dia, mesmo mês
        ->and(AuditLog::where('entidade', 'recurrence')->where('entidade_id', $rec->id)
            ->where('acao', AuditLog::ACAO_EDITAR)->exists())->toBeTrue();
});

it('não rebaixa o dia 31 do molde ao sincronizar uma ocorrência clampada (fev → 28)', function () {
    // Regra é "todo dia 31"; fevereiro materializou clampado no dia 28. Editar SÓ o valor
    // dessa ocorrência ("este e os próximos") não pode reescrever o molde para dia 28.
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => 150000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'categoria_id' => null, 'dia' => 31, 'proxima_em' => '2026-03-31',
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
        ->and($rec->dia)->toBe(31) // o molde continua "todo dia 31"
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-03-31');
});

it('muda o dia do molde quando a edição escolheu de fato outro dia', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel', 'valor_cents' => 150000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'categoria_id' => null, 'dia' => 31, 'proxima_em' => '2026-03-31',
        'status' => Recurrence::STATUS_ATIVO,
    ]);
    $novos = new DadosGastoManual(
        userId: $user->id, descricao: 'Aluguel', valorTotalCents: 150000,
        dataCompra: CarbonImmutable::parse('2026-02-10', 'America/Sao_Paulo'), // dia 10 de verdade
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
    );

    (new SincronizarRecorrencia)->sincronizar($rec, $novos);

    $rec->refresh();
    expect($rec->dia)->toBe(10)
        ->and($rec->proxima_em->format('Y-m-d'))->toBe('2026-03-10');
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

// ---- Cascata: rejeitar o pendente cancela a recorrência (C7, decisão do usuário) --------

it('rejeitar uma ocorrência cancela a recorrência inteira (C7)', function () {
    $user = User::factory()->create();
    $hoje = CarbonImmutable::parse('2026-07-09 06:00', 'America/Sao_Paulo');
    $rec = (new RegistrarRecorrencia)->registrar(dadosRecorrencia($user, ['dia' => 9]), $hoje);
    (new MaterializarRecorrencias)->paraTodos($hoje);
    $pendente = PendingConfirmation::where('user_id', $user->id)->sole();

    $ok = (new RejeitarPendente)->rejeitar($pendente->id, $user->id, $hoje);

    expect($ok)->toBeTrue()
        ->and($pendente->fresh()->status)->toBe(PendingConfirmation::STATUS_REJEITADO)
        ->and($rec->fresh()->status)->toBe(Recurrence::STATUS_CANCELADO)
        ->and($rec->fresh()->proxima_em)->toBeNull()
        ->and(Transaction::where('user_id', $user->id)->count())->toBe(0);
});
