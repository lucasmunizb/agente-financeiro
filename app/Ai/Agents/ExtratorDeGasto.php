<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Domain\IA\GastoExtraido;
use App\Domain\IA\ResultadoDaExtracao;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
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
 */
class ExtratorDeGasto implements Agent, Conversational, HasStructuredOutput, HasTools
{
    /** Formas de pagamento suportadas — espelha a tabela de referência `PaymentMethod` (doc 03 §4.6). */
    private const FORMAS = ['credito', 'debito', 'pix', 'dinheiro', 'boleto'];

    use Promptable;

    /**
     * Extrai um gasto do texto do usuário. Devolve o gasto cru ou os campos a esclarecer.
     */
    public function extrair(string $texto): ResultadoDaExtracao
    {
        /** @var StructuredAgentResponse $resposta */
        $resposta = $this->prompt($texto);

        $dados = $resposta->toArray();

        $descricao = self::limpar($dados['descricao'] ?? null);
        $valor = self::limpar($dados['valor'] ?? null);
        $forma = self::limpar($dados['forma_pagamento'] ?? null);
        $cartao = self::limpar($dados['cartao'] ?? null);

        $faltantes = [];

        if ($descricao === null) {
            $faltantes[] = 'descricao';
        }

        if ($valor === null) {
            $faltantes[] = 'valor';
        }

        if ($forma === null) {
            $faltantes[] = 'forma_pagamento';
        }

        if ($forma === 'credito' && $cartao === null) {
            $faltantes[] = 'cartao';
        }

        if ($faltantes !== []) {
            return new ResultadoDaExtracao(null, $faltantes);
        }

        $parcelas = $dados['parcelas'] ?? null;

        $gasto = new GastoExtraido(
            descricao: $descricao,
            valorTexto: $valor,
            formaPagamento: $forma,
            cartao: $cartao,
            categoria: self::limpar($dados['categoria'] ?? null),
            dataTexto: self::limpar($dados['data'] ?? null),
            parcelas: $parcelas !== null ? (int) $parcelas : null,
        );

        return new ResultadoDaExtracao($gasto, []);
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
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'descricao' => $schema->string()
                ->description('O que foi comprado ou pago, curto. Ex.: "mercado", "uber".'),
            'valor' => $schema->string()
                ->description('O valor EXATAMENTE como dito, em texto. Ex.: "35", "35 conto", "R$ 35,90". NUNCA calcule nem converta.'),
            'forma_pagamento' => $schema->string()
                ->enum(self::FORMAS)
                ->description('Forma de pagamento, quando dita.'),
            'cartao' => $schema->string()
                ->description('Identificação textual do cartão (ex.: "cartão pai"). Obrigatório quando forma_pagamento = credito.'),
            'categoria' => $schema->string()
                ->description('Categoria sugerida, se evidente. A classificação final é determinística.'),
            'data' => $schema->string()
                ->description('A data EXATAMENTE como dita ("hoje", "ontem", "amanhã", "mês que vem", "05/06"). NUNCA resolva a data.'),
            'parcelas' => $schema->integer()
                ->description('Número de parcelas quando parcelado (ex.: "3x" → 3). Ausente significa à vista.'),
        ];
    }
}
