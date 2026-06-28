<?php

use App\Domain\Importacao\EfetivarImportacao;
use App\Domain\Importacao\ItemPreImportacao;
use App\Domain\Importacao\LancamentoExtraido;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\InvoiceImport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * EfetivarImportacao (spec 07 §6, C8, regra 7): grava SÓ os itens que o usuário aceitou,
 * reusando o motor do Bloco 1 (RegistrarGastoManual) com origem 'pdf'. Atualiza o status
 * da invoice_imports (confirmada/parcial/cancelada). Nada é gravado sem aceite.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]));

function importCom(User $user): array
{
    $card = Card::factory()->for($user)->create(['dia_fechamento' => 2, 'dia_vencimento' => 10]);
    $import = InvoiceImport::create([
        'user_id' => $user->id,
        'card_id' => $card->id,
        'hash_arquivo_nome' => hash('sha256', 'fatura.pdf'),
        'status' => InvoiceImport::PENDENTE_REVISAO,
    ]);
    $data = CarbonImmutable::parse('2026-06-05', 'America/Sao_Paulo');
    $itens = [
        new ItemPreImportacao(new LancamentoExtraido('Notebook', 300000, $data, 3), duplicado: false),
        new ItemPreImportacao(new LancamentoExtraido('Cafeteria', 4500, $data, 1), duplicado: false),
    ];

    return [$import, $itens];
}

it('confirmação parcial: grava só o subconjunto aceito, origem pdf, e marca status parcial (C8)', function () {
    $user = User::factory()->create();
    [$import, $itens] = importCom($user);
    $hoje = CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo');

    $txs = (new EfetivarImportacao)->confirmar($import->id, $user->id, [$itens[0]], totalOferecidos: 2, hoje: $hoje);

    expect($txs)->toHaveCount(1)
        ->and(Transaction::count())->toBe(1)
        ->and($txs[0]->origem)->toBe('pdf')
        ->and($txs[0]->descricao)->toBe('Notebook')
        ->and($txs[0]->installments)->toHaveCount(3) // parcelas pelo motor do Bloco 1
        ->and($import->fresh()->status)->toBe(InvoiceImport::PARCIAL)
        ->and(AuditLog::where('entidade', 'transaction')->where('origem', 'pdf')->count())->toBe(1);
});

it('confirmação total: grava todos e marca status confirmada', function () {
    $user = User::factory()->create();
    [$import, $itens] = importCom($user);

    (new EfetivarImportacao)->confirmar($import->id, $user->id, $itens, totalOferecidos: 2, hoje: CarbonImmutable::parse('2026-06-25', 'America/Sao_Paulo'));

    expect(Transaction::count())->toBe(2)
        ->and($import->fresh()->status)->toBe(InvoiceImport::CONFIRMADA);
});

it('cancelamento: nenhum item aceito não grava nada e marca cancelada (regra 7)', function () {
    $user = User::factory()->create();
    [$import] = importCom($user);

    $txs = (new EfetivarImportacao)->confirmar($import->id, $user->id, [], totalOferecidos: 2);

    expect($txs)->toHaveCount(0)
        ->and(Transaction::count())->toBe(0)
        ->and($import->fresh()->status)->toBe(InvoiceImport::CANCELADA);
});

it('não efetiva importação de outro usuário (escopo)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    [$import, $itens] = importCom($user);

    expect(fn () => (new EfetivarImportacao)->confirmar($import->id, $outro->id, $itens, totalOferecidos: 2))
        ->toThrow(RuntimeException::class);

    expect(Transaction::count())->toBe(0)
        ->and($import->fresh()->status)->toBe(InvoiceImport::PENDENTE_REVISAO);
});
