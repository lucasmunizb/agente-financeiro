<?php

declare(strict_types=1);

use App\Ai\Agents\AssistenteDeConsulta;
use App\Domain\IA\Consulta\ResponderConsulta;
use App\Domain\IA\Custo\TipoDeUsoIA;
use App\Models\AiUsageLog;
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
 * Cada chamada de IA do chat registra uma linha em ai_usage_log (doc 02 §3.6): tipo
 * "mensagem", escopada ao usuário, com provedor/modelo da resposta. O valor (tokens/custo)
 * vem da resposta da SDK; aqui validamos a LIGAÇÃO (que a chamada é registrada).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function gastoParaUso(User $user, int $valorCents, string $vencimento): void
{
    $transaction = Transaction::factory()->for($user)->create([
        'valor_total_cents' => $valorCents,
    ]);
    Installment::factory()->for($transaction, 'transaction')->create([
        'numero' => 1, 'total' => 1, 'vencimento' => $vencimento,
        'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
    ]);
}

it('registra uma chamada de IA do chat como uso tipo mensagem, escopado ao usuário', function () {
    $user = User::factory()->create();
    gastoParaUso($user, 150000, '2026-06-10');

    Ai::fakeAgent(AssistenteDeConsulta::class, [
        new ToolCall('1', 'ConsultarGastos', ['periodo' => '2026-06']),
        'Você gastou R$ 1.500,00 em junho.',
    ]);

    app(ResponderConsulta::class)->responder($user, 'quanto gastei em junho?');

    $linha = AiUsageLog::query()->sole();
    expect($linha->tipo)->toBe(TipoDeUsoIA::MENSAGEM)
        ->and($linha->user_id)->toBe($user->id)
        ->and($linha->provider)->toBe(config('ai.default'))
        ->and($linha->model)->not->toBeNull();
});
