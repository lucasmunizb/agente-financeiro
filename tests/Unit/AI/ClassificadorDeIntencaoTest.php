<?php

use App\Ai\Agents\ClassificadorDeIntencao;
use App\Domain\IA\Intencao;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

/*
 * Agent de classificação de intenção (doc 02 §3.1). Papel 1 da IA: texto → intenção.
 * NÃO calcula nada. Aqui testamos a forma do schema e as barreiras nas instruções —
 * sem rede (puro). O comportamento (mapear saída → enum) é testado com os fakes da
 * SDK em tests/Feature/AI.
 */

function schemaIntencao(): array
{
    return (new ClassificadorDeIntencao)->schema(new JsonSchemaTypeFactory);
}

it('expõe a intenção como enum restrito aos valores conhecidos', function () {
    expect(schemaIntencao()['intencao']->toArray()['enum'])
        ->toEqualCanonicalizing(Intencao::valores());
});

it('torna a intenção obrigatória no objeto de saída', function () {
    $obj = JsonSchema::object(schemaIntencao())->toArray();

    expect($obj['required'])->toContain('intencao');
});

it('instrui a apenas classificar, nunca extrair valores nem calcular', function () {
    $instrucoes = mb_strtolower((string) (new ClassificadorDeIntencao)->instructions());

    expect($instrucoes)
        ->toContain('classific')
        ->toContain('não calcule');
});
