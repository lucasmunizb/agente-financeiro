<?php

use App\Ai\Agents\SugeridorDeCategoria;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\IA\NormalizadorDeGastoExtraido;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

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

it('preserva data explícita PASSADA junto do parcelamento (lançamento retroativo via IA/Telegram)', function () {
    $user = User::factory()->create();

    // "gastei 1200 em 3x no dia 10/01" registrado só em julho: a normalização NÃO força
    // a data para hoje — a data retroativa e as parcelas fluem intactas ao domínio, que
    // então gera todas as parcelas (inclusive as já vencidas). Fecha o caminho Telegram.
    $r = normalizar(
        gastoExtraidoFake(['dataTexto' => '10/01/2026', 'parcelas' => 3]),
        $user->id,
        '2026-07-10',
    );

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->dados->dataCompra->toDateString())->toBe('2026-01-10')
        ->and($r->dados->parcelas)->toBe(3);
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

    expect($r->dados->categoriaId)->toBe($mercado->id)
        ->and($r->dados->categoriaSugeridaPorIa)->toBeFalse();
});

it('cai na sugestão da IA quando o lookup não classifica e marca a flag', function () {
    $user = User::factory()->create();
    $lazer = Category::factory()->for($user)->create(['nome' => 'Lazer']);
    Ai::fakeAgent(SugeridorDeCategoria::class, [['categoria' => 'Lazer']]);

    $r = normalizar(gastoExtraidoFake(['descricao' => 'cinema no shopping']), $user->id);

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->dados->categoriaId)->toBe($lazer->id)
        ->and($r->dados->categoriaSugeridaPorIa)->toBeTrue();
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

/* -------- recorrência (spec 10c) -------- */

/*
 * Incidente de produção 2026-07-16: "registra uma recorrencia no pix para pagar todo dia 10
 * 520 reias ingles carol categoria estudos" respondeu "me diga também a data e a forma de
 * pagamento" — pedindo dois campos que a mensagem TINHA. Duas causas: (1) recorrência não
 * existia no pipeline de IA, então "todo dia 10" caiu no campo `data` e não parseou; (2)
 * idFor() casava o tipo literal, então qualquer "PIX" da IA era rejeitado.
 */

it('monta uma recorrência (não um gasto) quando há dia de recorrência', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake([
        'descricao' => 'ingles carol',
        'valorTexto' => '520',
        'formaPagamento' => 'pix',
        'recorrenciaDiaTexto' => '10',
        'dataTexto' => null,
    ]), $user->id);

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->dados)->toBeNull()
        ->and($r->recorrencia)->toBeInstanceOf(DadosRecorrencia::class)
        ->and($r->recorrencia->dia)->toBe(10)
        ->and($r->recorrencia->valorCents)->toBe(52000)
        ->and($r->recorrencia->descricao)->toBe('ingles carol')
        ->and($r->recorrencia->userId)->toBe($user->id)
        ->and($r->recorrencia->paymentMethodId)->toBe(PaymentMethod::idFor('pix'));
});

it('NÃO exige data numa recorrência — ela tem dia-do-mês, não data de compra (C2)', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake([
        'recorrenciaDiaTexto' => '10',
        'dataTexto' => null,
    ]), $user->id);

    expect($r->esclarecimentos)->not->toContain('data')
        ->and($r->precisaEsclarecer())->toBeFalse();
});

it('descarta a data que o modelo repetiu no campo errado, sem pedir esclarecimento (C3)', function () {
    $user = User::factory()->create();

    // Exatamente o payload do incidente: o modelo ecoou "todo dia 10" também em `data`.
    $r = normalizar(gastoExtraidoFake([
        'recorrenciaDiaTexto' => '10',
        'dataTexto' => 'todo dia 10',
    ]), $user->id);

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->recorrencia->dia)->toBe(10);
});

it('pede esclarecimento quando o dia é impossível ou não tem dígito (C4)', function (string $dia) {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['recorrenciaDiaTexto' => $dia]), $user->id);

    expect($r->precisaEsclarecer())->toBeTrue()
        ->and($r->recorrencia)->toBeNull()
        ->and($r->esclarecimentos)->toContain('recorrencia_dia');
})->with(['32', '0', 'todo mês', '-3']);

it('mantém o dia 31 como está — o clamp ao fim do mês é do domínio, não da IA (C5)', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['recorrenciaDiaTexto' => '31']), $user->id);

    expect($r->recorrencia->dia)->toBe(31);
});

it('recusa recorrência em cartão de crédito — crédito usa parcelas (C6)', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['descricao' => 'Nubank']);

    $r = normalizar(gastoExtraidoFake([
        'recorrenciaDiaTexto' => '10',
        'formaPagamento' => 'credito',
        'cartao' => 'nubank',
    ]), $user->id);

    expect($r->precisaEsclarecer())->toBeTrue()
        ->and($r->recorrencia)->toBeNull()
        ->and($r->esclarecimentos)->toContain('forma_pagamento');
});

it('recusa a contradição "recorrente E parcelado" (C7)', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake([
        'recorrenciaDiaTexto' => '10',
        'parcelas' => 3,
    ]), $user->id);

    expect($r->precisaEsclarecer())->toBeTrue()
        ->and($r->esclarecimentos)->toContain('recorrencia_dia');
});

/* -------- forma de pagamento: normalização determinística (causa raiz 2) -------- */

it('casa a forma de pagamento independente de caixa e espaço (C8)', function (string $forma) {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['formaPagamento' => $forma]), $user->id);

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->dados->paymentMethodId)->toBe(PaymentMethod::idFor('pix'));
})->with(['PIX', 'Pix', ' pix ', 'PIX ']);

it('pede esclarecimento quando a forma está fora do conjunto suportado (C9)', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['formaPagamento' => 'pix recorrente']), $user->id);

    expect($r->precisaEsclarecer())->toBeTrue()
        ->and($r->esclarecimentos)->toContain('forma_pagamento');
});

it('não muda nada no gasto avulso quando não há recorrência (C13 — regressão zero)', function () {
    $user = User::factory()->create();

    $r = normalizar(gastoExtraidoFake(['recorrenciaDiaTexto' => null]), $user->id);

    expect($r->precisaEsclarecer())->toBeFalse()
        ->and($r->recorrencia)->toBeNull()
        ->and($r->dados)->toBeInstanceOf(DadosGastoManual::class);
});
