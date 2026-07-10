<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\UsaFailoverDeProvedores;
use App\Ai\Concerns\UsaRaciocinioBaixoNaGroq;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Stringable;

/**
 * Papel de CLASSIFICAÇÃO da IA (doc 02 §3.1): dada a descrição de um gasto e a lista de
 * categorias do usuário, escolher UMA — a que melhor combina — ou nenhuma. A IA nunca calcula
 * dinheiro (regra 4); aqui só interpreta texto. É fallback do lookup determinístico (doc 08):
 * só roda quando aliases/keywords não classificaram. A resolução do nome de volta ao id e o
 * guard anti-alucinação são camada NOSSA ({@see \App\Domain\Categoria\SugerirCategoriaComIa}).
 *
 * As opções são injetadas por chamada ({@see sugerir()}) e viram tanto a instrução quanto o
 * `enum` do schema — o modelo é obrigado a devolver o nome EXATO de uma categoria da lista, ou
 * null. Escopo por usuário: a lista já chega filtrada às categorias ativas daquele usuário.
 *
 * Custo (doc 02 §3.6): escolher um item de uma lista é mecânico — modelo mais BARATO
 * (#[UseCheapestModel]) e reasoning_effort baixo na Groq. Sem #[MaxTokens]: o teto incluiria o
 * reasoning e truncaria o structured output (Groq 400). Implementado via Laravel AI SDK (regra 8).
 */
#[UseCheapestModel]
class SugeridorDeCategoria implements Agent, Conversational, HasProviderOptions, HasStructuredOutput, HasTools
{
    use Promptable;
    use UsaFailoverDeProvedores;
    use UsaRaciocinioBaixoNaGroq;

    /**
     * Nomes das categorias oferecidas nesta chamada (estado por-requisição, lido por
     * instructions()/schema() no momento do prompt).
     *
     * @var list<string>
     */
    private array $opcoes = [];

    /**
     * Sugere UMA categoria (nome exato) da lista para a descrição, ou null se nenhuma combina
     * ou se a lista está vazia (nesse caso NÃO chama o provedor — economiza token/latência).
     *
     * @param  list<string>  $categorias  nomes das categorias ativas do usuário
     */
    public function sugerir(string $descricao, array $categorias): ?string
    {
        $this->opcoes = array_values(array_filter(
            $categorias,
            fn ($nome) => is_string($nome) && trim($nome) !== '',
        ));

        if ($this->opcoes === []) {
            return null;
        }

        /** @var StructuredAgentResponse $resposta */
        $resposta = $this->prompt($descricao);

        $escolha = $resposta->toArray()['categoria'] ?? null;
        $escolha = is_string($escolha) ? trim($escolha) : null;

        return $escolha === '' ? null : $escolha;
    }

    public function instructions(): Stringable|string
    {
        $lista = implode("\n", array_map(fn (string $nome) => "- {$nome}", $this->opcoes));

        return <<<TXT
        Você classifica um gasto em UMA categoria, escolhendo da lista abaixo, em português do Brasil.

        Categorias disponíveis:
        {$lista}

        Regras:
        - Responda com o NOME EXATO de UMA categoria da lista — a que melhor combina com a descrição.
        - Apenas UMA opção. NUNCA invente uma categoria que não esteja na lista.
        - Se nenhuma categoria combinar razoavelmente com a descrição, responda null.
        TXT;
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        // Structured output ESTRITO da Groq (strict=true): o campo é required()->nullable() e o
        // null entra no enum além das opções — sem ele a saída "nenhuma categoria" violaria o
        // schema. O enum trava o modelo às categorias reais do usuário (1ª barreira anti-
        // alucinação); a 2ª é o guard determinístico que revalida o nome contra o banco.
        return [
            'categoria' => $schema->string()->enum([...$this->opcoes, null])->required()->nullable()
                ->description('O nome EXATO de UMA categoria da lista, ou null se nenhuma combinar.'),
        ];
    }
}
