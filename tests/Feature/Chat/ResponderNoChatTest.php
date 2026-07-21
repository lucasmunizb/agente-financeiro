<?php

declare(strict_types=1);

use App\Ai\Agents\AssistenteDeConsulta;
use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Domain\Chat\ResponderNoChat;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes;
use App\Models\ChatMessage;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\ToolCall;

/*
 * Chat financeiro na web (spec FE §7.14). Usa o MESMO motor do Telegram (ProcessarInteracao):
 * as mesmas funcionalidades — REGISTRAR um gasto (confirmação "sim/não" antes de gravar,
 * regra 7) e CONSULTAR (guard barreira 4 + fontes barreira 5) — e persiste o histórico real
 * (chat_messages), isolado por usuário. Determinístico via fakes da SDK. A IA nunca calcula
 * dinheiro (regra 4).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function gastoNoChat(User $user, int $valorCents, string $vencimento): void
{
    $transaction = Transaction::factory()->for($user)->create(['valor_total_cents' => $valorCents]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

function chamarGastosNoChat(string $id, string $periodo = '2026-06'): ToolCall
{
    return new ToolCall($id, 'ConsultarGastos', ['periodo' => $periodo]);
}

/** Guarda uma confirmação de gasto pendente (Mercado, PIX, R$ 90,00) para o "sim" seguinte. */
function pendenteNoChat(User $user, ?CarbonImmutable $dataPagamento = null): void
{
    $dados = new DadosGastoManual(
        userId: $user->id,
        descricao: 'Mercado',
        valorTotalCents: 9000,
        dataCompra: CarbonImmutable::parse('2026-06-26', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 1,
        dataPagamento: $dataPagamento,
    );
    $agora = CarbonImmutable::now('America/Sao_Paulo');
    $previa = (new RegistrarGastoManual)->preview($dados, $agora);

    app(ConfirmacoesPendentes::class)->guardar($user->id, new ConfirmacaoDeGasto($previa, $dados, []), $agora);
}

it('consulta: classifica o texto livre, reusa o motor e grava a resposta com fontes', function () {
    $user = User::factory()->create();
    gastoNoChat($user, 150000, '2026-06-10');

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'consultar']]);
    Ai::fakeAgent(AssistenteDeConsulta::class, [
        chamarGastosNoChat('1'),
        'Você gastou R$ 1.500,00 em junho.',
    ]);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'quanto gastei em junho?');

    Ai::assertAgentWasPrompted(AssistenteDeConsulta::class, fn ($p) => $p->prompt === 'quanto gastei em junho?');

    expect(ChatMessage::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($assistente->aprovado)->toBeTrue()
        ->and($assistente->body)->toContain('R$ 1.500,00')
        ->and($assistente->fontes)->toHaveCount(1)
        ->and($assistente->fontes[0]['ferramenta'])->toBe('consultar_gastos');
});

it('consulta: grava o fallback sem números (aprovado=false) quando o guard reprova', function () {
    $user = User::factory()->create();
    gastoNoChat($user, 150000, '2026-06-10');

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'consultar']]);
    Ai::fakeAgent(AssistenteDeConsulta::class, [
        chamarGastosNoChat('1'), 'Você gastou R$ 9.999,99 em junho.',
        chamarGastosNoChat('2'), 'Você gastou R$ 9.999,99 em junho.',
    ]);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'quanto gastei?');

    expect($assistente->aprovado)->toBeFalse()
        ->and($assistente->body)->not->toContain('9.999');
});

it('registrar: monta a prévia com "sim/não" e deixa a confirmação pendente (regra 7)', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'registrar']]);
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'mercado', 'valor' => '90', 'forma_pagamento' => 'pix', 'data' => 'hoje', 'pago' => true,
    ]]);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'gastei 90 no mercado no pix hoje');

    expect($assistente->body)->toContain('R$ 90,00')
        ->and($assistente->body)->toContain('sim')
        ->and($assistente->body)->toContain('não')
        ->and($assistente->aprovado)->toBeNull();

    // Nada gravado ainda; o pendente aguarda o "sim" (regra 7).
    expect(Transaction::count())->toBe(0)
        ->and(app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))->not->toBeNull();
});

it('confirmar "sim": grava o gasto e responde que registrou (mesma máquina do bot)', function () {
    $user = User::factory()->create();
    pendenteNoChat($user);

    // Com pendente, é determinístico: o classificador NÃO é chamado (sem fake, a SDK falharia).
    $assistente = app(ResponderNoChat::class)->perguntar($user, 'sim');

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1)
        ->and($assistente->body)->toContain('registrei')
        ->and($assistente->body)->toContain('R$ 90,00')
        ->and(app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))->toBeNull();
});

it('confirmar "não": cancela sem gravar e descarta o pendente', function () {
    $user = User::factory()->create();
    pendenteNoChat($user);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'não');

    expect(Transaction::count())->toBe(0)
        ->and($assistente->body)->toContain('Cancelei')
        ->and(app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))->toBeNull();
});

it('intenção não suportada: responde "não entendi", sem gravar nem consultar', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'editar']]);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'muda o valor daquele gasto');

    expect($assistente->body)->toContain('Não entendi')
        ->and(Transaction::count())->toBe(0);
});

it('isola por usuário: cada mensagem grava com o user_id do autor', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'consultar'], ['intencao' => 'consultar']]);
    Ai::fakeAgent(AssistenteDeConsulta::class, ['Resposta A', 'Resposta B']);

    app(ResponderNoChat::class)->perguntar($a, 'pergunta do A');
    app(ResponderNoChat::class)->perguntar($b, 'pergunta do B');

    expect(ChatMessage::query()->where('user_id', $a->id)->count())->toBe(2)
        ->and(ChatMessage::query()->where('user_id', $b->id)->count())->toBe(2)
        ->and(ChatMessage::query()->where('user_id', $a->id)->where('body', 'pergunta do B')->exists())->toBeFalse();
});

it('anexo de fatura: grava a mensagem com tem_anexo e um aviso honesto, SEM chamar a IA', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, []);
    Ai::fakeAgent(ClassificadorDeIntencao::class, []);

    $assistente = app(ResponderNoChat::class)->anexarFatura($user, 'Segue minha fatura');

    Ai::assertAgentNotPrompted(AssistenteDeConsulta::class, fn () => true);
    Ai::assertAgentNotPrompted(ClassificadorDeIntencao::class, fn () => true);

    $pergunta = ChatMessage::query()->where('role', 'user')->first();
    expect($pergunta->tem_anexo)->toBeTrue()
        ->and($pergunta->body)->toBe('Segue minha fatura');

    expect($assistente->role)->toBe('assistant')
        ->and($assistente->body)->toBe(ResponderNoChat::RESPOSTA_ANEXO)
        ->and($assistente->aprovado)->toBeTrue();
});

it('confirmar "sim" de um gasto já pago: grava a parcela paga e diz isso na resposta', function () {
    $user = User::factory()->create();
    pendenteNoChat($user, CarbonImmutable::parse('2026-06-26', 'America/Sao_Paulo'));

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'sim');

    $transacao = Transaction::where('user_id', $user->id)->sole();

    expect($transacao->installments()->first()->data_pagamento->toDateString())->toBe('2026-06-26')
        ->and($assistente->body)->toContain('registrei')
        ->and($assistente->body)->toContain('já pago');
});
