<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Importacao\DetectorDeDuplicidadeNaImportacao;
use App\Domain\Importacao\LancamentoExtraido;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * DetectorDeDuplicidadeNaImportacao (spec 07 §6, C7): marca um lançamento como
 * duplicado quando já existe lançamento do usuário com a mesma chave (valor + descrição
 * + data + nº de parcelas) — NUNCA pela parcela atual. Concilia com gastos vindos do
 * Telegram/manual. Reusa o detector do Bloco 1.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]));

/** Cria um gasto já salvo (ex.: via Telegram) que servirá de "existente". */
function gastoSalvo(User $user, string $origem, int $valorCents, string $data, int $parcelas): void
{
    (new RegistrarGastoManual)->confirmar(new DadosGastoManual(
        userId: $user->id,
        descricao: 'Mercado',
        valorTotalCents: $valorCents,
        dataCompra: CarbonImmutable::parse($data, 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: $parcelas,
        origem: $origem,
    ), CarbonImmutable::parse($data, 'America/Sao_Paulo'));
}

it('marca duplicado quando a chave já existe e concilia com gasto do Telegram (C7)', function () {
    $user = User::factory()->create();
    gastoSalvo($user, 'telegram', 30000, '2026-06-10', 1);

    $data = CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo');
    $flags = (new DetectorDeDuplicidadeNaImportacao)->marcar($user->id, [
        new LancamentoExtraido('Mercado', 30000, $data, 1),  // mesma chave do gasto do Telegram
        new LancamentoExtraido('Mercado', 31000, $data, 1),  // valor diferente → novo
    ]);

    expect($flags)->toBe([true, false]);
});

it('não marca duplicado de outro usuário (escopo)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    gastoSalvo($outro, 'manual', 30000, '2026-06-10', 1);

    $flags = (new DetectorDeDuplicidadeNaImportacao)->marcar($user->id, [
        new LancamentoExtraido('Mercado', 30000, CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'), 1),
    ]);

    expect($flags)->toBe([false]);
});

it('distingue pela quantidade total de parcelas, não pela parcela atual', function () {
    $user = User::factory()->create();
    gastoSalvo($user, 'telegram', 60000, '2026-06-10', 3); // 3 parcelas salvas

    $data = CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo');
    $flags = (new DetectorDeDuplicidadeNaImportacao)->marcar($user->id, [
        new LancamentoExtraido('Mercado', 60000, $data, 3), // mesmo total de parcelas → duplicado
        new LancamentoExtraido('Mercado', 60000, $data, 6), // total diferente → novo
    ]);

    expect($flags)->toBe([true, false]);
});
