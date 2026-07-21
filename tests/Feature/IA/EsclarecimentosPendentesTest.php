<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Esclarecimento\EsclarecimentosPendentes;
use App\Domain\IA\GastoParcial;
use App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Estado do esclarecimento pendente (extração parcial) entre mensagens — a memória que
 * faltava para o slot-filling multi-turno. Espelha o ConfirmacoesPendentes: UM por usuário
 * (§6.b), TTL (§6.a), escopo estrito por user_id. Nada de dinheiro em coluna própria: só o
 * texto cru já dito, para mesclar no próximo turno (a IA nunca calcula, regra 4).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00', 'America/Sao_Paulo'));
    $this->store = app(EsclarecimentosPendentes::class);
    $this->agora = CarbonImmutable::now('America/Sao_Paulo');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('guarda e recupera a extração parcial crua do usuário', function () {
    $user = User::factory()->create();
    $parcial = new GastoParcial(null, '263,52', 'pix', null, 'viagem', 'amanhã', null);

    $this->store->guardar($user->id, $parcial, $this->agora);
    $recuperado = $this->store->recuperar($user->id, $this->agora);

    expect($recuperado)->not->toBeNull()
        ->and($recuperado->valorTexto)->toBe('263,52')
        ->and($recuperado->formaPagamento)->toBe('pix')
        ->and($recuperado->categoria)->toBe('viagem')
        ->and($recuperado->dataTexto)->toBe('amanhã')
        ->and($recuperado->descricao)->toBeNull();
});

it('mantém apenas UM pendente por usuário (o novo substitui o anterior)', function () {
    $user = User::factory()->create();

    $this->store->guardar($user->id, new GastoParcial(null, '10', 'pix', null, null, null, null), $this->agora);
    $this->store->guardar($user->id, new GastoParcial('uber', '20', 'debito', null, null, null, null), $this->agora);

    $recuperado = $this->store->recuperar($user->id, $this->agora);

    expect($recuperado->descricao)->toBe('uber')
        ->and($recuperado->valorTexto)->toBe('20');
});

it('não recupera um pendente expirado (TTL)', function () {
    $user = User::factory()->create();
    $this->store->guardar($user->id, new GastoParcial(null, '10', 'pix', null, null, null, null), $this->agora);

    $depois = $this->agora->addMinutes(EsclarecimentosPendentes::TTL_MINUTOS + 1);

    expect($this->store->recuperar($user->id, $depois))->toBeNull();
});

it('é estritamente escopado por usuário', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->store->guardar($a->id, new GastoParcial(null, '10', 'pix', null, null, null, null), $this->agora);

    expect($this->store->recuperar($b->id, $this->agora))->toBeNull();
});

it('descarta o pendente', function () {
    $user = User::factory()->create();
    $this->store->guardar($user->id, new GastoParcial(null, '10', 'pix', null, null, null, null), $this->agora);

    $this->store->descartar($user->id);

    expect($this->store->recuperar($user->id, $this->agora))->toBeNull();
});

it('não confunde um esclarecimento com uma confirmação confirmável (tipos isolados na mesma fila)', function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    $user = User::factory()->create();

    // Guarda uma CONFIRMAÇÃO (confirmável) e tenta recuperar como ESCLARECIMENTO.
    $dados = new DadosGastoManual(
        userId: $user->id, descricao: 'Mercado', valorTotalCents: 9000,
        dataCompra: $this->agora, paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX), parcelas: 1,
    );
    $previa = (new RegistrarGastoManual)->preview($dados, $this->agora);
    app(ConfirmacoesPendentes::class)->guardar($user->id, new ConfirmacaoDeGasto($previa, $dados, []), $this->agora);

    // A fila é a mesma linha, mas os tipos não se misturam.
    expect($this->store->recuperar($user->id, $this->agora))->toBeNull()
        ->and(app(ConfirmacoesPendentes::class)->recuperar($user->id, $this->agora))->not->toBeNull();
});

it('preserva o slot "já foi pago" entre turnos, inclusive quando é false', function () {
    $user = User::factory()->create();
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, false, null);

    $this->store->guardar($user->id, $parcial, $this->agora);

    expect($this->store->recuperar($user->id, $this->agora)->pago)->toBeFalse();
});

it('preserva a data de pagamento crua entre turnos', function () {
    $user = User::factory()->create();
    $parcial = new GastoParcial('mercado', '90', 'pix', null, null, 'hoje', null, null, true, 'ontem');

    $this->store->guardar($user->id, $parcial, $this->agora);
    $recuperado = $this->store->recuperar($user->id, $this->agora);

    expect($recuperado->pago)->toBeTrue()
        ->and($recuperado->dataPagamentoTexto)->toBe('ontem');
});
