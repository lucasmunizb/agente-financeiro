<?php

use App\Ai\Agents\ExtratorDeGasto;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\StringType;

/*
 * Agent de extração de campos (doc 02 §3.1, papel 2). Texto → JSON estruturado. A IA
 * NUNCA calcula nem normaliza: valor e data saem como TEXTO cru ("35 conto", "amanhã")
 * e a normalização determinística (Money/RelativeDate) acontece fora da IA, no item 3.
 * Aqui testamos a forma do schema e as barreiras nas instruções (puro, sem rede).
 */

function schemaExtrator(): array
{
    return (new ExtratorDeGasto)->schema(new JsonSchemaTypeFactory);
}

it('declara os campos de um gasto', function () {
    expect(array_keys(schemaExtrator()))
        ->toEqualCanonicalizing(['descricao', 'valor', 'forma_pagamento', 'cartao', 'categoria', 'data', 'parcelas']);
});

it('mantém valor e data como TEXTO — a IA não calcula nem resolve', function () {
    $schema = schemaExtrator();

    expect($schema['valor'])->toBeInstanceOf(StringType::class)
        ->and($schema['data'])->toBeInstanceOf(StringType::class);
});

it('conta parcelas como inteiro (quantidade declarada, não dinheiro)', function () {
    expect(schemaExtrator()['parcelas'])->toBeInstanceOf(IntegerType::class);
});

it('restringe a forma de pagamento às formas suportadas', function () {
    expect(schemaExtrator()['forma_pagamento']->toArray()['enum'])
        ->toEqualCanonicalizing(['credito', 'debito', 'pix', 'dinheiro', 'boleto']);
});

it('instrui a não calcular dinheiro, assumir BRL e o fuso de São Paulo', function () {
    $instrucoes = mb_strtolower((string) (new ExtratorDeGasto)->instructions());

    expect($instrucoes)
        ->toContain('não calcule')
        ->toContain('são paulo')
        ->toContain('crédito');
});
