<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\PreviaGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\GastoExtraido;
use App\Domain\IA\PrepararConfirmacaoDeGasto;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Orquestrador da confirmação (Bloco 4 item 3): normaliza a extração da IA e, se tudo
 * resolve, gera a PRÉVIA via RegistrarGastoManual::preview() — SEM persistir (regra 7).
 * Carrega o DadosGastoManual para a persistência no "sim" (reusa o confirmar() existente).
 * Quando falta resolver algo, devolve os esclarecimentos e nenhuma prévia.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function preparar(GastoExtraido $extraido, int $userId, string $hoje = '2026-06-26'): ConfirmacaoDeGasto
{
    return app(PrepararConfirmacaoDeGasto::class)->preparar(
        $extraido,
        $userId,
        CarbonImmutable::parse($hoje, 'America/Sao_Paulo'),
    );
}

it('gera a prévia sem persistir quando a extração resolve', function () {
    $user = User::factory()->create();

    $conf = preparar(
        gastoExtraidoFake(['valorTexto' => '90', 'formaPagamento' => 'pix', 'parcelas' => 3, 'dataTexto' => '10/06']),
        $user->id,
    );

    expect($conf->precisaEsclarecer())->toBeFalse()
        ->and($conf->confirmavel())->toBeTrue()
        ->and($conf->previa)->toBeInstanceOf(PreviaGastoManual::class)
        ->and($conf->previa->parcelas)->toHaveCount(3)
        ->and($conf->dados)->toBeInstanceOf(DadosGastoManual::class)
        ->and(Transaction::count())->toBe(0)
        ->and(Installment::count())->toBe(0);
});

it('devolve esclarecimentos e nenhuma prévia quando falta resolver', function () {
    $user = User::factory()->create();

    $conf = preparar(gastoExtraidoFake(['valorTexto' => 'sei lá']), $user->id);

    expect($conf->precisaEsclarecer())->toBeTrue()
        ->and($conf->confirmavel())->toBeFalse()
        ->and($conf->previa)->toBeNull()
        ->and($conf->esclarecimentos)->toContain('valor');
});

it('os dados preparados são persistíveis pelo registro manual (fluxo do "sim")', function () {
    $user = User::factory()->create();

    $conf = preparar(
        gastoExtraidoFake(['valorTexto' => '90', 'formaPagamento' => 'pix']),
        $user->id,
    );

    $transaction = (new RegistrarGastoManual)->confirmar(
        $conf->dados,
        CarbonImmutable::parse('2026-06-26', 'America/Sao_Paulo'),
    );

    expect($transaction->valor_total_cents)->toBe(9000)
        ->and(Transaction::count())->toBe(1)
        ->and($transaction->origem)->toBe('manual');
});
