<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\UsaFailoverDeProvedores;
use App\Ai\Concerns\UsaRaciocinioBaixoNaGroq;
use App\Domain\IA\GastoParcial;
use App\Domain\IA\ResultadoDaExtracao;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Stringable;

/**
 * Papel 2 da IA (doc 02 §3.1): extrair os campos de um gasto. Texto → JSON estruturado
 * e validado por schema. A IA NUNCA calcula nem normaliza: valor e data saem como TEXTO
 * cru; a normalização determinística (Money/RelativeDate, fuso SP) é feita FORA da IA na
 * etapa de confirmação. Campo obrigatório ausente vira esclarecimento (barreira 1, §3.3),
 * e crédito exige cartão (§3.4). Implementado via Laravel AI SDK (regra inviolável 8).
 *
 * Custo (doc 02 §3.6): extrair 7 campos para um JSON é tarefa mecânica — roda no modelo mais
 * BARATO do provedor (#[UseCheapestModel]) e pede reasoning_effort baixo à Groq (corta o
 * raciocínio dos modelos gpt-oss sem truncar o JSON). Sem #[MaxTokens]: o teto incluiria o
 * reasoning e quebraria o structured output. A normalização/valor continua determinística
 * fora da IA (regra 4).
 */
#[UseCheapestModel]
class ExtratorDeGasto implements Agent, Conversational, HasProviderOptions, HasStructuredOutput, HasTools
{
    /** Formas de pagamento suportadas — espelha a tabela de referência `PaymentMethod` (doc 03 §4.6). */
    private const FORMAS = ['credito', 'debito', 'pix', 'dinheiro', 'boleto'];

    use Promptable;
    use UsaFailoverDeProvedores;
    use UsaRaciocinioBaixoNaGroq;

    /**
     * Extrai um gasto do texto do usuário. Devolve o gasto cru ou os campos a esclarecer.
     */
    public function extrair(string $texto): ResultadoDaExtracao
    {
        $parcial = $this->extrairParcial($texto);

        return $parcial->completo()
            ? new ResultadoDaExtracao($parcial->paraExtraido(), [])
            : new ResultadoDaExtracao(null, $parcial->faltantes());
    }

    /**
     * Extração CRUA e possivelmente incompleta de UMA mensagem — sem decidir obrigatoriedade.
     * É a unidade do slot-filling multi-turno: cada mensagem é extraída isolada e depois
     * ACUMULADA ({@see GastoParcial::mesclar()}) sobre o que já se sabia, para não repetir
     * perguntas cujas respostas o usuário já deu. A IA nunca calcula/normaliza (regra 4).
     */
    public function extrairParcial(string $texto): GastoParcial
    {
        $inicio = microtime(true);

        /** @var StructuredAgentResponse $resposta */
        $resposta = $this->prompt($texto);

        // Custo visível para TODOS os agentes, não só a consulta (auditoria P3-1).
        \App\Domain\IA\Custo\LogDeUsoDeIA::registrar($resposta, \App\Domain\IA\Custo\TipoDeUsoIA::MENSAGEM, inicio: $inicio);

        $dados = $resposta->toArray();
        $parcelas = $dados['parcelas'] ?? null;

        return new GastoParcial(
            descricao: self::limpar($dados['descricao'] ?? null),
            valorTexto: self::limpar($dados['valor'] ?? null),
            formaPagamento: self::limpar($dados['forma_pagamento'] ?? null),
            cartao: self::limpar($dados['cartao'] ?? null),
            categoria: self::limpar($dados['categoria'] ?? null),
            dataTexto: self::limpar($dados['data'] ?? null),
            parcelas: $parcelas !== null ? (int) $parcelas : null,
        );
    }

    /**
     * Normaliza strings cruas: nulo/em branco vira null (campo ausente para a barreira 1).
     */
    private static function limpar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Você extrai os campos de um gasto a partir de uma mensagem em português do Brasil.

        Regras invioláveis:
        - NÃO calcule nem some nada. Copie o valor EXATAMENTE como dito, em texto ("35", "35 conto", "R$ 35,90"). A moeda é sempre BRL (reais).
        - NÃO resolva datas. Copie a data EXATAMENTE como dita ("hoje", "ontem", "amanhã", "mês que vem", "05/06"). O fuso é o de São Paulo, mas quem resolve é o sistema, não você.
        - Preencha apenas o que estiver explícito ou claramente implícito. Campo que você não souber, deixe ausente — NUNCA invente.
        - Forma de pagamento "crédito" exige a identificação do cartão (ex.: "cartão pai"); se não houver, deixe o cartão ausente.
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
     * @return Tool[]
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
        // Structured output ESTRITO da Groq (strict=true) exige TODOS os campos em `required`;
        // por isso cada um é required()->nullable(): o modelo devolve null quando não sabe, e
        // limpar() trata null como ausente (barreira 1) — mesma semântica de "campo faltante".
        return [
            'descricao' => $schema->string()->required()->nullable()
                ->description('O que foi comprado ou pago, curto. Ex.: "mercado", "uber". null se não disser.'),
            'valor' => $schema->string()->required()->nullable()
                ->description('O valor EXATAMENTE como dito, em texto. Ex.: "35", "35 conto", "R$ 35,90". NUNCA calcule nem converta. null se não disser.'),
            // null entra no enum além do type: em strict a constraint `enum` é validada mesmo
            // com type nullable, então sem o null aqui a saída "campo ausente" viola o schema.
            'forma_pagamento' => $schema->string()->enum([...self::FORMAS, null])->required()->nullable()
                ->description('Forma de pagamento, quando dita. null se não disser.'),
            'cartao' => $schema->string()->required()->nullable()
                ->description('Identificação textual do cartão (ex.: "cartão pai"). Obrigatório quando forma_pagamento = credito. null se não houver.'),
            'categoria' => $schema->string()->required()->nullable()
                ->description('Categoria sugerida, se evidente. A classificação final é determinística. null se não evidente.'),
            'data' => $schema->string()->required()->nullable()
                ->description('A data EXATAMENTE como dita ("hoje", "ontem", "amanhã", "mês que vem", "05/06"). NUNCA resolva a data. null se não disser.'),
            'parcelas' => $schema->integer()->required()->nullable()
                ->description('Número de parcelas quando parcelado (ex.: "3x" → 3). null significa à vista.'),
        ];
    }
}
