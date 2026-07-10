<?php

use App\Ai\Agents\AssistenteDeConsulta;
use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Ai\Agents\RedatorDeResposta;
use App\Ai\Agents\SugeridorDeCategoria;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/*
 * Economia de tokens/custo da camada de IA (doc 02 §3.6). São asserções de CONFIGURAÇÃO,
 * não de comportamento do provedor (a recusa/qualidade do modelo NÃO é determinística — ver
 * skill seguranca-ia). Garantimos, via os atributos/hooks que a Laravel AI SDK lê:
 *   - os agentes MECÂNICOS (classificar intenção, extrair campos) usam o modelo mais BARATO;
 *   - eles pedem reasoning_effort=low À GROQ (corta tokens de reasoning dos modelos gpt-oss
 *     sem truncar o JSON), e NADA a outros provedores (não vaza param inválido no failover);
 *   - NENHUM agente usa #[MaxTokens]: em modelo de raciocínio o teto inclui o reasoning e
 *     truncaria o structured output (Groq 400) — foi o que quebrou o chat em produção.
 * Nenhuma barreira de segurança depende disto: escopo por usuário e guard seguem intactos.
 */

function usaModeloMaisBarato(string $agente): bool
{
    return (new ReflectionClass($agente))->getAttributes(UseCheapestModel::class) !== [];
}

function temMaxTokens(string $agente): bool
{
    return (new ReflectionClass($agente))->getAttributes(MaxTokens::class) !== [];
}

it('classifica intenção no modelo mais barato — tarefa trivial não paga o modelo smartest', function () {
    expect(usaModeloMaisBarato(ClassificadorDeIntencao::class))->toBeTrue();
});

it('extrai campos do gasto no modelo mais barato', function () {
    expect(usaModeloMaisBarato(ExtratorDeGasto::class))->toBeTrue();
});

it('redige a resposta no modelo mais barato — formatar um payload já calculado é mecânico', function () {
    // Sem roteamento de tools: apenas formata números que o motor já calculou (regra 4).
    // Não há retry de guard a temer aqui, então o modelo mais barato é sempre o certo.
    expect(usaModeloMaisBarato(RedatorDeResposta::class))->toBeTrue();
});

it('pede reasoning_effort baixo à Groq no classificador, e nada a outros provedores', function () {
    $agente = app(ClassificadorDeIntencao::class);

    expect($agente->providerOptions(Lab::Groq))->toBe(['reasoning_effort' => 'low'])
        ->and($agente->providerOptions(Lab::Gemini))->toBe([]);
});

it('pede reasoning_effort baixo à Groq no extrator, e nada a outros provedores', function () {
    $agente = app(ExtratorDeGasto::class);

    expect($agente->providerOptions(Lab::Groq))->toBe(['reasoning_effort' => 'low'])
        ->and($agente->providerOptions(Lab::Gemini))->toBe([]);
});

it('pede reasoning_effort baixo à Groq no redator, e nada a outros provedores', function () {
    $agente = app(RedatorDeResposta::class);

    expect($agente->providerOptions(Lab::Groq))->toBe(['reasoning_effort' => 'low'])
        ->and($agente->providerOptions(Lab::Gemini))->toBe([]);
});

it('sugere categoria no modelo mais barato — escolher uma opção de lista é mecânico', function () {
    expect(usaModeloMaisBarato(SugeridorDeCategoria::class))->toBeTrue();
});

it('pede reasoning_effort baixo à Groq no sugeridor de categoria, e nada a outros provedores', function () {
    $agente = app(SugeridorDeCategoria::class);

    expect($agente->providerOptions(Lab::Groq))->toBe(['reasoning_effort' => 'low'])
        ->and($agente->providerOptions(Lab::Gemini))->toBe([]);
});

it('não usa #[MaxTokens] nos agentes de raciocínio — o teto truncaria o structured output (Groq 400)', function () {
    expect(temMaxTokens(ClassificadorDeIntencao::class))->toBeFalse()
        ->and(temMaxTokens(ExtratorDeGasto::class))->toBeFalse()
        ->and(temMaxTokens(RedatorDeResposta::class))->toBeFalse()
        ->and(temMaxTokens(SugeridorDeCategoria::class))->toBeFalse()
        ->and(temMaxTokens(AssistenteDeConsulta::class))->toBeFalse();
});

it('mantém o assistente de consulta no modelo padrão — roteamento de tools + redação exigem qualidade', function () {
    // Não é "cheapest": mis-rotear tool geraria retry pelo guard (mais tokens, não menos).
    expect(usaModeloMaisBarato(AssistenteDeConsulta::class))->toBeFalse();
});

it('o assistente de consulta não impõe reasoning_effort — mantém qualidade de roteamento', function () {
    expect((new ReflectionClass(AssistenteDeConsulta::class))->implementsInterface(HasProviderOptions::class))
        ->toBeFalse();
});
