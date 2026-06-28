<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\PreviaGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Serviço de cadastro de gasto manual (F2.3) — orquestra o motor do Bloco 1:
 * vencimento (cartão/fora) → parcelas → duplicidade → status por data → persiste
 * transaction + installments + auditoria, com preview/confirmação antes de gravar.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function dadosPix(User $user, array $over = []): DadosGastoManual
{
    return new DadosGastoManual(
        userId: $over['userId'] ?? $user->id,
        descricao: $over['descricao'] ?? 'Mercado',
        valorTotalCents: $over['valorTotalCents'] ?? 30000,
        dataCompra: $over['dataCompra'] ?? CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: $over['paymentMethodId'] ?? PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: $over['parcelas'] ?? 3,
    );
}

/* -------- preview: não persiste, calcula tudo -------- */

it('preview não persiste nada no banco', function () {
    $user = User::factory()->create();

    $previa = (new RegistrarGastoManual)->preview(dadosPix($user), CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));

    expect($previa)->toBeInstanceOf(PreviaGastoManual::class)
        ->and(Transaction::count())->toBe(0)
        ->and(Installment::count())->toBe(0)
        ->and($previa->parcelas)->toHaveCount(3);
});

it('preview fora de cartão: primeiro vencimento na data da compra, +1 mês cada, valor derivado', function () {
    $user = User::factory()->create();

    $previa = (new RegistrarGastoManual)->preview(dadosPix($user), CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));

    expect(array_map(fn ($p) => $p->vencimento->toDateString(), $previa->parcelas))
        ->toBe(['2026-06-10', '2026-07-10', '2026-08-10'])
        ->and(array_map(fn ($p) => $p->valor->cents(), $previa->parcelas))
        ->toBe([10000, 10000, 10000]);
});

it('preview deriva o status de cada parcela pela data', function () {
    $user = User::factory()->create();

    // hoje = 10/jul: parcela 06-10 vencida, 07-10 vence hoje (aberto), 08-10 agendada
    $previa = (new RegistrarGastoManual)->preview(dadosPix($user), CarbonImmutable::parse('2026-07-10', 'America/Sao_Paulo'));

    expect(array_map(fn ($p) => $p->statusCodigo, $previa->parcelas))
        ->toBe([StatusPagamento::VENCIDO, StatusPagamento::ABERTO, StatusPagamento::AGENDADO]);
});

it('preview em cartão usa o vencimento do cartão (ciclo de fatura)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 2, 'dia_vencimento' => 10]);

    $dados = new DadosGastoManual(
        userId: $user->id,
        descricao: 'Notebook',
        valorTotalCents: 50000,
        dataCompra: CarbonImmutable::parse('2026-06-05', 'America/Sao_Paulo'), // após fechamento (dia 2)
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::CREDITO),
        parcelas: 1,
        cardId: $card->id,
    );

    $previa = (new RegistrarGastoManual)->preview($dados, CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));

    expect($previa->parcelas)->toHaveCount(1)
        ->and($previa->parcelas[0]->vencimento->toDateString())->toBe('2026-07-10');
});

it('preview sinaliza duplicidade quando já existe lançamento com a mesma chave', function () {
    $user = User::factory()->create();
    $servico = new RegistrarGastoManual;
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');

    $servico->confirmar(dadosPix($user), $hoje);

    expect($servico->preview(dadosPix($user), $hoje)->ehDuplicado)->toBeTrue()
        ->and($servico->preview(dadosPix($user, ['valorTotalCents' => 31000]), $hoje)->ehDuplicado)->toBeFalse();
});

/* -------- confirmar: persiste transaction + installments + auditoria -------- */

it('confirmar persiste a transaction com origem manual, status aberto e BRL', function () {
    $user = User::factory()->create();

    $tx = (new RegistrarGastoManual)->confirmar(dadosPix($user), CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));

    expect($tx)->toBeInstanceOf(Transaction::class)
        ->and($tx->origem)->toBe('manual')
        ->and($tx->moeda)->toBe('BRL')
        ->and($tx->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::ABERTO))
        ->and($tx->user_id)->toBe($user->id)
        ->and($tx->valor_total_cents)->toBe(30000);
});

it('confirmar persiste as parcelas com numero/total/vencimento/status e valor derivado', function () {
    $user = User::factory()->create();

    $tx = (new RegistrarGastoManual)->confirmar(dadosPix($user), CarbonImmutable::parse('2026-07-10', 'America/Sao_Paulo'));
    $parcelas = $tx->installments()->orderBy('numero')->get();

    expect($parcelas)->toHaveCount(3)
        ->and($parcelas->pluck('total')->all())->toBe([3, 3, 3])
        ->and($parcelas->map(fn ($p) => $p->vencimento->toDateString())->all())
        ->toBe(['2026-06-10', '2026-07-10', '2026-08-10'])
        ->and($parcelas->map(fn ($p) => $p->status_id)->all())->toBe([
            StatusPagamento::idFor(StatusPagamento::VENCIDO),
            StatusPagamento::idFor(StatusPagamento::ABERTO),
            StatusPagamento::idFor(StatusPagamento::AGENDADO),
        ])
        ->and($parcelas->first()->valor()->cents())->toBe(10000);
});

it('confirmar respeita a origem informada (ex.: pdf da importação de fatura)', function () {
    $user = User::factory()->create();

    $dados = new DadosGastoManual(
        userId: $user->id,
        descricao: 'Mercado',
        valorTotalCents: 30000,
        dataCompra: CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 1,
        origem: 'pdf',
    );

    $tx = (new RegistrarGastoManual)->confirmar($dados, CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));
    $log = AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)->first();

    expect($tx->origem)->toBe('pdf')
        ->and($log->origem)->toBe('pdf');
});

it('preview e confirmar mantêm a origem manual por padrão', function () {
    $user = User::factory()->create();
    $servico = new RegistrarGastoManual;
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');

    expect($servico->preview(dadosPix($user), $hoje)->origem)->toBe('manual')
        ->and($servico->confirmar(dadosPix($user), $hoje)->origem)->toBe('manual');
});

it('confirmar registra a auditoria de criação', function () {
    $user = User::factory()->create();

    $tx = (new RegistrarGastoManual)->confirmar(dadosPix($user), CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));

    $log = AuditLog::where('entidade', 'transaction')->where('entidade_id', $tx->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->acao)->toBe(AuditLog::ACAO_CRIAR)
        ->and($log->origem)->toBe('manual')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->antes)->toBeNull()
        ->and($log->depois)->not->toBeNull();
});
