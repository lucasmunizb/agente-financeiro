<?php

use App\Ai\Agents\ExtratorDeGasto;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\StringType;
use Laravel\Ai\ObjectSchema;

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
        ->toEqualCanonicalizing(['descricao', 'valor', 'forma_pagamento', 'cartao', 'categoria', 'data', 'parcelas', 'recorrencia_dia']);
});

it('mantém o dia da recorrência como TEXTO cru — a IA não calcula data (spec 10c C1)', function () {
    // "todo dia 10" → "10". Quem valida 1..31 e resolve proxima_em (com clamp no fim do mês)
    // é o domínio determinístico (OcorrenciaMensal), nunca a IA (regra 4).
    expect(schemaExtrator()['recorrencia_dia'])->toBeInstanceOf(StringType::class);
});

it('mantém valor e data como TEXTO — a IA não calcula nem resolve', function () {
    $schema = schemaExtrator();

    expect($schema['valor'])->toBeInstanceOf(StringType::class)
        ->and($schema['data'])->toBeInstanceOf(StringType::class);
});

it('conta parcelas como inteiro (quantidade declarada, não dinheiro)', function () {
    expect(schemaExtrator()['parcelas'])->toBeInstanceOf(IntegerType::class);
});

it('restringe a forma de pagamento às formas suportadas (mais null para "não disse")', function () {
    // null é aceito (campo ausente vira esclarecimento); as demais são só as formas válidas.
    expect(schemaExtrator()['forma_pagamento']->toArray()['enum'])
        ->toEqualCanonicalizing(['credito', 'debito', 'pix', 'dinheiro', 'boleto', null]);
});

it('gera schema compatível com structured output ESTRITO (Groq strict) sem mudar a semântica', function () {
    // Groq força response_format strict=true: o schema PRECISA listar TODOS os campos em
    // `required` (senão 400 "required is required"). Para preservar "campo ausente vira
    // esclarecimento", cada campo é required PORÉM nullable (type: [..., "null"]) — o modelo
    // devolve null quando não sabe, e limpar() já trata null como ausente (barreira 1).
    $shape = (new ObjectSchema(schemaExtrator(), 'schema_definition', true))->toSchema();

    $campos = array_keys($shape['properties']);

    expect($shape['required'] ?? [])->toEqualCanonicalizing($campos);

    foreach ($shape['properties'] as $prop) {
        expect((array) ($prop['type'] ?? []))->toContain('null');
    }
});

it('instrui a não calcular dinheiro, assumir BRL e o fuso de São Paulo', function () {
    $instrucoes = mb_strtolower((string) (new ExtratorDeGasto)->instructions());

    expect($instrucoes)
        ->toContain('não calcule')
        ->toContain('são paulo')
        ->toContain('crédito');
});
