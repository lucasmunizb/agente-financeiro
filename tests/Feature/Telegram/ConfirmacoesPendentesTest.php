<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Spec 04b §5/§6 — estado de confirmação pendente do gasto interpretado pela IA.
 * Guarda o `ConfirmacaoDeGasto` (na prática o `DadosGastoManual` pronto para gravar) por
 * usuário, com TTL de 15 min (§6.a) e UM pendente ativo por usuário (§6.b: o novo
 * substitui o anterior). Round-trip preserva centavos e o instante da dataCompra (regra 5).
 * Nada sensível persistido além do necessário (regra 6); escopo estrito por user_id.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    $this->agora = CarbonImmutable::parse('2026-06-26 12:00', 'America/Sao_Paulo');
});

function confirmacaoDe(User $user, array $over = []): ConfirmacaoDeGasto
{
    $dados = new DadosGastoManual(
        userId: $user->id,
        descricao: $over['descricao'] ?? 'Mercado',
        valorTotalCents: $over['valorTotalCents'] ?? 9000,
        dataCompra: $over['dataCompra'] ?? CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: $over['parcelas'] ?? 1,
        dataPagamento: $over['dataPagamento'] ?? null,
    );

    $previa = (new RegistrarGastoManual)->preview($dados, CarbonImmutable::parse('2026-06-26 12:00', 'America/Sao_Paulo'));

    return new ConfirmacaoDeGasto($previa, $dados, []);
}

it('guardar devolve um token não vazio', function () {
    $user = User::factory()->create();

    $token = app(ConfirmacoesPendentes::class)->guardar($user->id, confirmacaoDe($user), $this->agora);

    expect($token)->toBeString()->not->toBeEmpty();
});

it('round-trip preserva centavos (int) e o instante da dataCompra', function () {
    $user = User::factory()->create();
    $data = CarbonImmutable::parse('2026-06-10 00:00:00', 'America/Sao_Paulo');
    $svc = app(ConfirmacoesPendentes::class);

    $svc->guardar($user->id, confirmacaoDe($user, [
        'valorTotalCents' => 12345, 'dataCompra' => $data, 'parcelas' => 3,
    ]), $this->agora);

    $rec = $svc->recuperar($user->id, $this->agora);

    expect($rec)->toBeInstanceOf(ConfirmacaoDeGasto::class)
        ->and($rec->confirmavel())->toBeTrue()
        ->and($rec->dados->userId)->toBe($user->id)
        ->and($rec->dados->valorTotalCents)->toBe(12345)
        ->and($rec->dados->valorTotalCents)->toBeInt()
        ->and($rec->dados->parcelas)->toBe(3)
        ->and($rec->dados->dataCompra)->toBeInstanceOf(CarbonImmutable::class)
        ->and($rec->dados->dataCompra->equalTo($data))->toBeTrue();
});

it('mantém apenas UM pendente por usuário: o novo substitui o anterior (§6.b)', function () {
    $user = User::factory()->create();
    $svc = app(ConfirmacoesPendentes::class);

    $svc->guardar($user->id, confirmacaoDe($user, ['descricao' => 'Primeiro', 'valorTotalCents' => 1000]), $this->agora);
    $svc->guardar($user->id, confirmacaoDe($user, ['descricao' => 'Segundo', 'valorTotalCents' => 2000]), $this->agora);

    $rec = $svc->recuperar($user->id, $this->agora);

    expect($rec->dados->descricao)->toBe('Segundo')
        ->and($rec->dados->valorTotalCents)->toBe(2000)
        ->and(DB::table('telegram_pending_confirmations')->where('user_id', $user->id)->count())->toBe(1);
});

it('recuperar é estrito por usuário: não vaza pendente de outro usuário (§C5)', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $svc = app(ConfirmacoesPendentes::class);

    $svc->guardar($a->id, confirmacaoDe($a), $this->agora);

    expect($svc->recuperar($b->id, $this->agora))->toBeNull()
        ->and($svc->recuperar($a->id, $this->agora))->not->toBeNull();
});

it('respeita o TTL de 15 minutos: válido no limite, expirado depois (§6.a/§C4)', function () {
    $user = User::factory()->create();
    $svc = app(ConfirmacoesPendentes::class);

    $svc->guardar($user->id, confirmacaoDe($user), $this->agora);

    expect($svc->recuperar($user->id, $this->agora->addMinutes(14)))->not->toBeNull()
        ->and($svc->recuperar($user->id, $this->agora->addMinutes(15)))->not->toBeNull()
        ->and($svc->recuperar($user->id, $this->agora->addMinutes(16)))->toBeNull();
});

it('descartar remove o pendente do usuário', function () {
    $user = User::factory()->create();
    $svc = app(ConfirmacoesPendentes::class);

    $svc->guardar($user->id, confirmacaoDe($user), $this->agora);
    $svc->descartar($user->id);

    expect($svc->recuperar($user->id, $this->agora))->toBeNull();
});

it('preserva a data de pagamento no round-trip do pendente (gasto já pago)', function () {
    $user = User::factory()->create();
    $confirmacao = confirmacaoDe($user, ['dataPagamento' => CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo')]);

    app(ConfirmacoesPendentes::class)->guardar($user->id, $confirmacao, $this->agora);
    $recuperado = app(ConfirmacoesPendentes::class)->recuperar($user->id, $this->agora);

    expect($recuperado->dados->dataPagamento?->toDateString())->toBe('2026-06-10')
        ->and($recuperado->dados->dataPagamento?->timezone->getName())->toBe('America/Sao_Paulo');
});

it('mantém null quando o gasto não foi pago', function () {
    $user = User::factory()->create();

    app(ConfirmacoesPendentes::class)->guardar($user->id, confirmacaoDe($user), $this->agora);

    expect(app(ConfirmacoesPendentes::class)->recuperar($user->id, $this->agora)->dados->dataPagamento)->toBeNull();
});
