<?php

declare(strict_types=1);

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeContaPaga;
use App\Domain\Chat\RedatorDoChat;
use App\Domain\Interacao\ProcessarInteracao;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;
use App\Domain\Telegram\Resposta\TipoDeInteracao;
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
use Laravel\Ai\Ai;

/*
 * "Paguei a luz" pelo bot (decisão do usuário 2026-07-21) — marcar uma conta como quitada
 * por conversa, o que antes só existia na tela.
 *
 * Divisão de papéis (doc 02): a IA classifica a intenção e extrai o TERMO que o usuário
 * disse; o domínio determinístico resolve QUAL conta é, quanto vale e quando vence
 * ({@see App\Domain\Pagamento\ResolverContaAPagar}), e só o "sim" grava (regra 7). A IA
 * nunca vê nem escolhe valor. Toda IA é fakeada aqui (regra 8).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 12:00', 'America/Sao_Paulo'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function falarComOBotSobrePagamento(User $user, string $texto)
{
    return app(ProcessarInteracao::class)->processar(
        $user,
        new ComandoRecebido(Comando::DESCONHECIDO, '', $texto),
    );
}

function contaDoBot(User $user, string $descricao, string $venc, ?Card $card = null): Installment
{
    $tx = Transaction::factory()->for($user)->create([
        'descricao' => $descricao,
        'valor_total_cents' => 12000,
        'card_id' => $card?->id,
        'payment_method_id' => PaymentMethod::idFor($card !== null ? PaymentMethod::CREDITO : PaymentMethod::PIX),
    ]);

    return Installment::factory()->for($tx, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $venc,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

/** Fakeia a dupla de agentes do fluxo: classificação da intenção + extração do termo. */
function fakearPagamento(string $conta): void
{
    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'pagar']]);
    Ai::fakeAgent(ExtratorDeContaPaga::class, [['conta' => $conta]]);
}

it('pede confirmação com os números vindos do banco — não grava ainda (regra 7)', function () {
    $user = User::factory()->create();
    $parcela = contaDoBot($user, 'Conta de luz', '2026-07-15');
    fakearPagamento('luz');

    $resultado = falarComOBotSobrePagamento($user, 'paguei a luz');

    expect($resultado->tipo)->toBe(TipoDeInteracao::PAGAMENTO_A_CONFIRMAR)
        ->and($resultado->contaAPagar->descricao)->toBe('Conta de luz')
        // O valor NUNCA veio do modelo: ele só disse "luz".
        ->and($resultado->contaAPagar->cents)->toBe(12000);

    // Nada foi gravado antes do "sim".
    expect($parcela->fresh()->status_id)->not->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('o "sim" quita a conta', function () {
    $user = User::factory()->create();
    $parcela = contaDoBot($user, 'Conta de luz', '2026-07-15');
    fakearPagamento('luz');

    falarComOBotSobrePagamento($user, 'paguei a luz');
    $resultado = falarComOBotSobrePagamento($user, 'sim');

    expect($resultado->tipo)->toBe(TipoDeInteracao::PAGAMENTO_REGISTRADO);

    $parcela->refresh();
    expect($parcela->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO))
        ->and($parcela->data_pagamento->toDateString())->toBe('2026-07-21');
});

it('o "não" descarta sem gravar', function () {
    $user = User::factory()->create();
    $parcela = contaDoBot($user, 'Conta de luz', '2026-07-15');
    fakearPagamento('luz');

    falarComOBotSobrePagamento($user, 'paguei a luz');
    $resultado = falarComOBotSobrePagamento($user, 'não');

    expect($resultado->tipo)->toBe(TipoDeInteracao::CONFIRMACAO_CANCELADA)
        ->and($parcela->fresh()->status_id)->not->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('quita também uma ocorrência de recorrência', function () {
    $user = User::factory()->create();
    $rec = Recurrence::factory()->for($user)->create([
        'descricao' => 'Aluguel',
        'valor_cents' => 150000,
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'proxima_em' => null,
    ]);
    $oc = RecurrenceOccurrence::factory()->create([
        'user_id' => $user->id,
        'recurrence_id' => $rec->id,
        'competencia' => '2026-07',
        'descricao' => 'Aluguel',
        'valor_cents' => 150000,
        'data_cobranca' => '2026-07-05',
        'vencimento' => '2026-07-05',
        'payment_method_id' => PaymentMethod::idFor(PaymentMethod::PIX),
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
    fakearPagamento('aluguel');

    falarComOBotSobrePagamento($user, 'quitei o aluguel');
    falarComOBotSobrePagamento($user, 'sim');

    expect($oc->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('diz que não achou quando nada casa — sem inventar conta', function () {
    $user = User::factory()->create();
    contaDoBot($user, 'Internet', '2026-07-15');
    fakearPagamento('condomínio');

    $resultado = falarComOBotSobrePagamento($user, 'paguei o condomínio');

    expect($resultado->tipo)->toBe(TipoDeInteracao::CONTA_A_PAGAR_NAO_ENCONTRADA)
        ->and($resultado->termoBuscado)->toBe('condomínio');
});

it('com mais de um candidato, pergunta qual — nunca escolhe sozinho', function () {
    $user = User::factory()->create();
    contaDoBot($user, 'Luz de julho', '2026-07-15');
    contaDoBot($user, 'Luz de junho', '2026-06-15');
    fakearPagamento('luz');

    $resultado = falarComOBotSobrePagamento($user, 'paguei a luz');

    expect($resultado->tipo)->toBe(TipoDeInteracao::PAGAMENTO_AMBIGUO)
        ->and($resultado->contasCandidatas)->toHaveCount(2)
        ->and($resultado->contasCandidatas[0]->descricao)->toBe('Luz de junho');
});

it('escolher pelo número resolve a ambiguidade e pede confirmação', function () {
    $user = User::factory()->create();
    contaDoBot($user, 'Luz de julho', '2026-07-15');
    $junho = contaDoBot($user, 'Luz de junho', '2026-06-15');
    fakearPagamento('luz');

    falarComOBotSobrePagamento($user, 'paguei a luz');
    $escolha = falarComOBotSobrePagamento($user, '1');

    expect($escolha->tipo)->toBe(TipoDeInteracao::PAGAMENTO_A_CONFIRMAR)
        ->and($escolha->contaAPagar->descricao)->toBe('Luz de junho');

    falarComOBotSobrePagamento($user, 'sim');
    expect($junho->fresh()->status_id)->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('não oferece conta de CARTÃO (a fatura é que quita)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 28, 'dia_vencimento' => 25]);
    contaDoBot($user, 'Luz no cartão', '2026-07-15', $card);
    fakearPagamento('luz');

    expect(falarComOBotSobrePagamento($user, 'paguei a luz')->tipo)
        ->toBe(TipoDeInteracao::CONTA_A_PAGAR_NAO_ENCONTRADA);
});

it('nunca alcança a conta de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = contaDoBot(User::factory()->create(), 'Conta de luz', '2026-07-15');
    fakearPagamento('luz');

    expect(falarComOBotSobrePagamento($user, 'paguei a luz')->tipo)
        ->toBe(TipoDeInteracao::CONTA_A_PAGAR_NAO_ENCONTRADA);

    expect($alheia->fresh()->status_id)->not->toBe(StatusPagamento::idFor(StatusPagamento::PAGO));
});

it('o extrator recebe o texto íntegro do usuário', function () {
    $user = User::factory()->create();
    contaDoBot($user, 'Conta de luz', '2026-07-15');
    fakearPagamento('luz');

    falarComOBotSobrePagamento($user, 'paguei a luz ontem');

    Ai::assertAgentWasPrompted(
        ExtratorDeContaPaga::class,
        fn ($prompt) => $prompt->prompt === 'paguei a luz ontem',
    );
});

it('a redação do bot cita os números vindos do banco', function () {
    // A redação NÃO é fonte de número: ela transcreve a ContaPagavel, que veio do banco.
    $user = User::factory()->create();
    contaDoBot($user, 'Conta de luz', '2026-07-15');
    fakearPagamento('luz');

    $previa = app(RedatorDoChat::class)->redigir(falarComOBotSobrePagamento($user, 'paguei a luz'));
    expect($previa->texto)->toContain('Conta de luz')
        ->and($previa->texto)->toContain('R$ 120,00')
        ->and($previa->texto)->toContain('sim');

    $gravado = app(RedatorDoChat::class)->redigir(falarComOBotSobrePagamento($user, 'sim'));
    expect($gravado->texto)->toContain('marquei')
        ->and($gravado->texto)->toContain('Conta de luz');
});

it('a redação lista as opções numeradas quando há ambiguidade', function () {
    $user = User::factory()->create();
    contaDoBot($user, 'Luz de julho', '2026-07-15');
    contaDoBot($user, 'Luz de junho', '2026-06-15');
    fakearPagamento('luz');

    $texto = app(RedatorDoChat::class)->redigir(falarComOBotSobrePagamento($user, 'paguei a luz'))->texto;

    expect($texto)->toContain('1. Luz de junho')
        ->and($texto)->toContain('2. Luz de julho')
        ->and($texto)->toContain('número');
});
