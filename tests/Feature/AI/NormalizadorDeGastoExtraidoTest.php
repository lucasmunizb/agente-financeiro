<?php

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\IA\NormalizadorDeGastoExtraido;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Normalização determinística da extração da IA (Bloco 4 item 3). Converte o GastoExtraido
 * CRU (valor/data como texto) em DadosGastoManual, resolvendo valor → centavos (Money), data
 * → fuso SP (RelativeDate / data explícita), forma → id de referência, cartão → id (só crédito,
 * casado pelo texto na descrição) e categoria pelo lookup determinístico. O que não resolve
 * vira esclarecimento (§3.4) — NUNCA chute. A IA não participa desta camada.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

function normalizar(App\Domain\IA\GastoExtraido $extraido, int $userId, string $hoje = '2026-06-26')
{
    return app(NormalizadorDeGastoExtraido::class)->normalizar(
        $extraido,
        $userId,
        CarbonImmutable::parse($hoje, 'America/Sao_Paulo'),
    );
}

/* -------- valor -------- */

it('normaliza o valor humano para centavos inteiros', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['valorTexto' => '35 conto']), $user->id);

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->dados)->toBeInstanceOf(DadosGastoManual::class)
        ->and($r->dados->valorTotalCents)->toBe(3500)
        ->and($r->dados->descricao)->toBe('mercado');
});

it('pede esclarecimento quando o valor não tem dígito', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['valorTexto' => 'sei lá']), $user->id);

    expect($r->precisaEsclarecer())->toBeTrue()
        ->and($r->dados)->toBeNull()
        ->and($r->esclarecimentos)->toContain('valor');
});

it('pede esclarecimento quando o valor é zero', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['valorTexto' => '0']), $user->id);

    expect($r->esclarecimentos)->toContain('valor');
});

/* -------- data -------- */

it('assume hoje (fuso SP) quando a data não é informada', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['dataTexto' => null]), $user->id, '2026-06-26');

    expect($r->dados->dataCompra->toDateString())->toBe('2026-06-26');
});

it('resolve data relativa no fuso de São Paulo', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['dataTexto' => 'amanhã']), $user->id, '2026-06-26');

    expect($r->dados->dataCompra->toDateString())->toBe('2026-06-27');
});

it('resolve data explícita dd/mm no ano corrente', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['dataTexto' => '05/06']), $user->id, '2026-06-26');

    expect($r->dados->dataCompra->toDateString())->toBe('2026-06-05');
});

it('resolve data explícita dd/mm/aaaa', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['dataTexto' => '05/06/2025']), $user->id);

    expect($r->dados->dataCompra->toDateString())->toBe('2025-06-05');
});

it('pede esclarecimento quando a data não é compreendida', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['dataTexto' => 'sexta que vem']), $user->id);

    expect($r->esclarecimentos)->toContain('data');
});

/* -------- forma de pagamento -------- */

it('resolve a forma de pagamento para o id da tabela de referência', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['formaPagamento' => 'pix']), $user->id);

    expect($r->dados->paymentMethodId)->toBe(PaymentMethod::idFor(PaymentMethod::PIX));
});

it('pede esclarecimento quando a forma de pagamento não é suportada', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['formaPagamento' => 'cripto']), $user->id);

    expect($r->esclarecimentos)->toContain('forma_pagamento');
});

/* -------- cartão (só crédito) -------- */

it('casa o cartão pelo texto contido na descrição (crédito)', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'Nubank Roxinho']);

    $r = normalizar(
        gastoExtraidoFake(['formaPagamento' => 'credito', 'cartao' => 'nubank']),
        $user->id,
    );

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->dados->cardId)->toBe($card->id);
});

it('ignora a palavra "cartão" ao casar o cartão pelo texto', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['descricao' => 'cartão pai']);

    $r = normalizar(
        gastoExtraidoFake(['formaPagamento' => 'credito', 'cartao' => 'cartão pai']),
        $user->id,
    );

    expect($r->dados->cardId)->toBe($card->id);
});

it('pede esclarecimento quando o cartão não é encontrado', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['descricao' => 'Inter']);

    $r = normalizar(
        gastoExtraidoFake(['formaPagamento' => 'credito', 'cartao' => 'nubank']),
        $user->id,
    );

    expect($r->esclarecimentos)->toContain('cartao');
});

it('pede esclarecimento quando o texto casa mais de um cartão (ambíguo)', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['descricao' => 'Nubank Roxinho']);
    Card::factory()->for($user)->create(['descricao' => 'Nubank Ultravioleta']);

    $r = normalizar(
        gastoExtraidoFake(['formaPagamento' => 'credito', 'cartao' => 'nubank']),
        $user->id,
    );

    expect($r->esclarecimentos)->toContain('cartao');
});

it('não casa cartão de outro usuário (isolamento)', function () {
    $outro = User::factory()->create();
    Card::factory()->for($outro)->create(['descricao' => 'Nubank']);
    $user = User::factory()->create();

    $r = normalizar(
        gastoExtraidoFake(['formaPagamento' => 'credito', 'cartao' => 'nubank']),
        $user->id,
    );

    expect($r->esclarecimentos)->toContain('cartao');
});

it('não vincula cartão quando a forma não é crédito', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['descricao' => 'Nubank']);

    $r = normalizar(
        gastoExtraidoFake(['formaPagamento' => 'pix', 'cartao' => 'nubank']),
        $user->id,
    );

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->dados->cardId)->toBeNull();
});

/* -------- categoria, parcelas -------- */

it('classifica a categoria deterministicamente pela descrição', function () {
    $user = User::factory()->create();
    $mercado = Category::factory()->for($user)->create(['nome' => 'Mercado']);
    $mercado->keywords()->create(['palavra_chave' => 'mercado']);

    $r = normalizar(gastoExtraidoFake(['descricao' => 'mercado extra']), $user->id);

    expect($r->dados->categoriaId)->toBe($mercado->id);
});

it('usa 1 parcela por padrão e respeita o número informado', function () {
    $user = User::factory()->create();

    $aVista = normalizar(gastoExtraidoFake(['parcelas' => null]), $user->id);
    $parcelado = normalizar(gastoExtraidoFake(['parcelas' => 3]), $user->id);

    expect($aVista->dados->parcelas)->toBe(1)
        ->and($parcelado->dados->parcelas)->toBe(3);
});

it('acumula múltiplos esclarecimentos e não monta os dados', function () {
    $user = User::factory()->create();

    $r = normalizar(
        gastoExtraidoFake(['valorTexto' => 'xx', 'formaPagamento' => 'cripto']),
        $user->id,
    );

    expect($r->dados)->toBeNull()
        ->and($r->esclarecimentos)->toContain('valor')
        ->and($r->esclarecimentos)->toContain('forma_pagamento');
});
