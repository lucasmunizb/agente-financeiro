<?php

declare(strict_types=1);

use App\Ai\Agents\AssistenteDeConsulta;
use App\Domain\Chat\ResponderNoChat;
use App\Models\ChatMessage;
use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\ToolCall;

/*
 * Serviço do chat financeiro na web (spec FE §7.14). Reutiliza o MESMO motor do Telegram
 * (ResponderConsulta → AssistenteDeConsulta + guard barreira 4 + fontes barreira 5) e
 * persiste o histórico real (chat_messages), isolado por usuário. Determinístico via fakes
 * da SDK. A IA nunca calcula dinheiro (regra 4).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** Gasto de parcela única que alimenta a tool consultar_gastos. */
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

it('persiste a pergunta do usuário e a resposta do assistente, reusando o motor', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, ['Olá! Posso ajudar com suas finanças.']);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'oi, tudo bem?');

    // A pergunta foi encaminhada, íntegra, ao mesmo agente do Telegram.
    Ai::assertAgentWasPrompted(
        AssistenteDeConsulta::class,
        fn ($prompt) => $prompt->prompt === 'oi, tudo bem?',
    );

    expect(ChatMessage::query()->where('user_id', $user->id)->count())->toBe(2);

    $pergunta = ChatMessage::query()->where('role', 'user')->first();
    expect($pergunta->body)->toBe('oi, tudo bem?')
        ->and($pergunta->tem_anexo)->toBeFalse()
        ->and($pergunta->fontes)->toBeNull();

    expect($assistente)->toBeInstanceOf(ChatMessage::class)
        ->and($assistente->role)->toBe('assistant')
        ->and($assistente->body)->toContain('Olá')
        ->and($assistente->aprovado)->toBeTrue()
        ->and($assistente->fontes)->toBe([]);
});

it('grava as fontes (barreira 5) e aprovado=true quando o guard aprova o número real', function () {
    $user = User::factory()->create();
    gastoNoChat($user, 150000, '2026-06-10');

    Ai::fakeAgent(AssistenteDeConsulta::class, [
        chamarGastosNoChat('1'),
        'Você gastou R$ 1.500,00 em junho.',
    ]);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'quanto gastei em junho?');

    expect($assistente->aprovado)->toBeTrue()
        ->and($assistente->body)->toContain('R$ 1.500,00')
        ->and($assistente->fontes)->toHaveCount(1)
        ->and($assistente->fontes[0]['ferramenta'])->toBe('consultar_gastos')
        ->and($assistente->fontes[0])->toHaveKeys(['ferramenta', 'filtros', 'registros', 'resumo']);
});

it('grava o fallback sem números (aprovado=false) quando o guard reprova', function () {
    $user = User::factory()->create();
    gastoNoChat($user, 150000, '2026-06-10');

    Ai::fakeAgent(AssistenteDeConsulta::class, [
        chamarGastosNoChat('1'),
        'Você gastou R$ 9.999,99 em junho.',
        chamarGastosNoChat('2'),
        'Você gastou R$ 9.999,99 em junho.',
    ]);

    $assistente = app(ResponderNoChat::class)->perguntar($user, 'quanto gastei?');

    expect($assistente->aprovado)->toBeFalse()
        ->and($assistente->body)->not->toContain('9.999');
});

it('isola por usuário: cada pergunta grava com o user_id do autor', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, ['Resposta A', 'Resposta B']);

    app(ResponderNoChat::class)->perguntar($a, 'pergunta do A');
    app(ResponderNoChat::class)->perguntar($b, 'pergunta do B');

    expect(ChatMessage::query()->where('user_id', $a->id)->count())->toBe(2)
        ->and(ChatMessage::query()->where('user_id', $b->id)->count())->toBe(2)
        ->and(ChatMessage::query()->where('user_id', $a->id)->where('body', 'pergunta do B')->exists())->toBeFalse();
});

it('anexo de fatura: grava a mensagem com tem_anexo e um aviso honesto, SEM chamar a IA', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, []); // provamos que NÃO é chamado

    $assistente = app(ResponderNoChat::class)->anexarFatura($user, 'Segue minha fatura');

    Ai::assertAgentNotPrompted(AssistenteDeConsulta::class, fn () => true);

    $pergunta = ChatMessage::query()->where('role', 'user')->first();
    expect($pergunta->tem_anexo)->toBeTrue()
        ->and($pergunta->body)->toBe('Segue minha fatura');

    expect($assistente->role)->toBe('assistant')
        ->and($assistente->body)->toBe(ResponderNoChat::RESPOSTA_ANEXO)
        ->and($assistente->aprovado)->toBeTrue();
});
