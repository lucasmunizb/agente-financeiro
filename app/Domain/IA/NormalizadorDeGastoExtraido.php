<?php

declare(strict_types=1);

namespace App\Domain\IA;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Categoria\ResolvedorDeCategoria;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\OcorrenciaMensal;
use App\Domain\Shared\Money;
use App\Domain\Shared\Normalizador;
use App\Models\Card;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Normalização determinística da extração da IA (Bloco 4 item 3). Converte o `GastoExtraido`
 * CRU em `DadosGastoManual`, pronto para o motor financeiro do Bloco 1 — a IA NUNCA passa por
 * aqui (regra inviolável 4). Resolve, de forma determinística e testável:
 *
 * - valor: `Money::fromHuman` → centavos (>0); inválido/zero → esclarecimento `valor`.
 * - data: ausente → hoje (fuso SP); termo relativo → `RelativeDate`; data explícita (dd/mm,
 *   dd/mm/aaaa, aaaa-mm-dd) parseada no fuso SP; incompreendida → esclarecimento `data`.
 * - forma: `PaymentMethod::idFor`; não suportada → esclarecimento `forma_pagamento`.
 * - cartão (SÓ crédito): casado pelo texto contido na descrição do cartão do usuário; 0 ou
 *   ≥2 correspondências → esclarecimento `cartao`. Demais formas são "fora de cartão".
 * - categoria: lookup determinístico e, no fallback, sugestão da IA sob guard ({@see
 *   ResolvedorDeCategoria}); nunca bloqueia (null é aceitável). A sugestão da IA é só de
 *   CLASSIFICAÇÃO (regra 4: a IA nunca calcula dinheiro) e vem marcada como pré-seleção.
 */
final class NormalizadorDeGastoExtraido
{
    /** Palavras genéricas ignoradas ao casar o cartão pelo texto. */
    private const STOPWORDS_CARTAO = ['cartao', 'cartoes', 'card'];

    public function __construct(
        private readonly ResolvedorDeCategoria $categoria,
    ) {}

    public function normalizar(GastoExtraido $extraido, int $userId, CarbonImmutable $agora): ResultadoDaNormalizacao
    {
        return $extraido->recorrenciaDiaTexto !== null
            ? $this->normalizarRecorrencia($extraido, $userId)
            : $this->normalizarGasto($extraido, $userId, $agora);
    }

    private function normalizarGasto(GastoExtraido $extraido, int $userId, CarbonImmutable $agora): ResultadoDaNormalizacao
    {
        $esclarecimentos = [];

        $valorCents = $this->resolverValor($extraido->valorTexto);
        if ($valorCents === null) {
            $esclarecimentos[] = 'valor';
        }

        $dataCompra = $this->resolverData($extraido->dataTexto, $agora);
        if ($dataCompra === null) {
            $esclarecimentos[] = 'data';
        }

        $forma = Normalizador::texto($extraido->formaPagamento);

        $paymentMethodId = PaymentMethod::idFor($extraido->formaPagamento);
        if ($paymentMethodId === null) {
            $esclarecimentos[] = 'forma_pagamento';
        }

        $cardId = null;
        if ($forma === PaymentMethod::CREDITO) {
            $cardId = $this->resolverCartao($userId, $extraido->cartao);
            if ($cardId === null) {
                $esclarecimentos[] = 'cartao';
            }
        }

        // Pagamento já feito (decisão 2026-07-21): SÓ fora de cartão — crédito quita pela
        // fatura (§4.3), então um "já paguei" no crédito é ignorado em silêncio. Sem data
        // dita, o pagamento é na DATA DA COMPRA (à vista fora de cartão é o caso comum);
        // data dita e ilegível vira PERGUNTA, nunca chute (§3.4).
        if ($esclarecimentos !== []) {
            return new ResultadoDaNormalizacao(null, $esclarecimentos);
        }

        $dataPagamento = null;
        if ($extraido->pago === true && $forma !== PaymentMethod::CREDITO) {
            $dataPagamento = $extraido->dataPagamentoTexto === null
                ? $dataCompra
                : $this->resolverData($extraido->dataPagamentoTexto, $agora);

            if ($dataPagamento === null) {
                return new ResultadoDaNormalizacao(null, ['data_pagamento']);
            }
        }

        $categoria = $this->categoria->para($userId, $extraido->descricao);

        $dados = new DadosGastoManual(
            userId: $userId,
            descricao: $extraido->descricao,
            valorTotalCents: $valorCents,
            dataCompra: $dataCompra,
            paymentMethodId: $paymentMethodId,
            parcelas: max(1, $extraido->parcelas ?? 1),
            cardId: $cardId,
            accountId: null,
            categoriaId: $categoria->categoriaId,
            categoriaSugeridaPorIa: $categoria->sugeridaPorIa,
            dataPagamento: $dataPagamento,
        );

        return new ResultadoDaNormalizacao($dados, []);
    }

    /**
     * Recorrência mensal (spec 10c): o usuário disse que aquilo REPETE todo mês. Aqui NÃO
     * existe data de compra — existe dia-do-mês —, então `data` é deliberadamente ignorada
     * (o modelo às vezes ecoa "todo dia 10" nos dois campos; o dia é a verdade). O clamp ao
     * fim do mês e o cálculo de `proxima_em` são do domínio ({@see OcorrenciaMensal}), não
     * daqui e muito menos da IA (regra 4).
     */
    private function normalizarRecorrencia(GastoExtraido $extraido, int $userId): ResultadoDaNormalizacao
    {
        $esclarecimentos = [];

        $valorCents = $this->resolverValor($extraido->valorTexto);
        if ($valorCents === null) {
            $esclarecimentos[] = 'valor';
        }

        $dia = $this->resolverDia($extraido->recorrenciaDiaTexto);
        if ($dia === null) {
            $esclarecimentos[] = 'recorrencia_dia';
        }

        // "Todo dia 10 em 3x" é contraditório: ou repete todo mês, ou é parcelado. Perguntar
        // é mais barato que gravar a interpretação errada de um compromisso mensal.
        if (($extraido->parcelas ?? 1) > 1) {
            $esclarecimentos[] = 'recorrencia_dia';
        }

        // Recorrência em cartão passou a ser permitida (spec 12, D3), mas exige SABER QUAL
        // cartão — e o bot não tem como resolver isso a partir de texto livre sem chutar (e a
        // competência da ocorrência depende do ciclo daquele cartão). Então pelo canal do chat
        // a recorrência segue fora de cartão: pedimos esclarecimento em vez de adivinhar.
        $forma = Normalizador::texto($extraido->formaPagamento);
        $paymentMethodId = PaymentMethod::idFor($extraido->formaPagamento);

        if ($paymentMethodId === null || $forma === PaymentMethod::CREDITO) {
            $esclarecimentos[] = 'forma_pagamento';
        }

        if ($esclarecimentos !== []) {
            return new ResultadoDaNormalizacao(null, array_values(array_unique($esclarecimentos)));
        }

        $categoria = $this->categoria->para($userId, $extraido->descricao);

        $dados = new DadosRecorrencia(
            userId: $userId,
            descricao: $extraido->descricao,
            valorCents: $valorCents,
            paymentMethodId: $paymentMethodId,
            dia: $dia,
            categoriaId: $categoria->categoriaId,
        );

        return new ResultadoDaNormalizacao(null, [], $dados);
    }

    /**
     * Dia-do-mês (1..31) a partir do texto cru da IA ("10", "todo dia 10"), ou null quando
     * não há dia válido — vira PERGUNTA, nunca chute (§3.4). Um número colado a "-" não conta
     * ("-3" não é dia 3). O dia 31 é aceito como está: o clamp é do {@see OcorrenciaMensal}.
     */
    private function resolverDia(?string $texto): ?int
    {
        if ($texto === null) {
            return null;
        }

        if (preg_match('/(?<![\d-])(\d{1,2})(?!\d)/', $texto, $m) !== 1) {
            return null;
        }

        $dia = (int) $m[1];

        return $dia >= 1 && $dia <= 31 ? $dia : null;
    }

    /**
     * Centavos inteiros (>0) ou null quando o texto não é um valor monetário válido.
     */
    private function resolverValor(string $texto): ?int
    {
        try {
            $cents = Money::fromHuman($texto)->cents();
        } catch (InvalidArgumentException) {
            return null;
        }

        return $cents > 0 ? $cents : null;
    }

    /**
     * Resolve a data no fuso de São Paulo. Ausente → hoje; termo relativo → RelativeDate;
     * data explícita dd/mm[/aaaa] ou aaaa-mm-dd → parse. Incompreendida → null.
     */
    private function resolverData(?string $texto, CarbonImmutable $agora): ?CarbonImmutable
    {
        $hoje = $agora->setTimezone(RelativeDate::TIMEZONE)->startOfDay();

        if ($texto === null || trim($texto) === '') {
            return $hoje;
        }

        $texto = trim($texto);

        try {
            return RelativeDate::resolve($texto, $agora);
        } catch (InvalidArgumentException) {
            // Não é termo relativo conhecido; tenta data explícita.
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$#', $texto, $m) === 1) {
            return $this->montarData((int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : $hoje->year);
        }

        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $texto, $m) === 1) {
            return $this->montarData((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function montarData(int $dia, int $mes, int $ano): ?CarbonImmutable
    {
        if (! checkdate($mes, $dia, $ano)) {
            return null;
        }

        return CarbonImmutable::create($ano, $mes, $dia, 0, 0, 0, RelativeDate::TIMEZONE);
    }

    /**
     * Casa o cartão do usuário pelo texto. Compara por tokens significativos contidos na
     * descrição normalizada do cartão; exige correspondência única. 0 ou ≥2 → null.
     */
    private function resolverCartao(int $userId, ?string $texto): ?int
    {
        $tokens = $this->tokensDoCartao($texto);
        if ($tokens === []) {
            return null;
        }

        $candidatos = Card::query()
            ->where('user_id', $userId)
            ->get(['id', 'descricao'])
            ->filter(function (Card $card) use ($tokens) {
                $descricao = Normalizador::texto((string) $card->descricao);

                foreach ($tokens as $token) {
                    if (str_contains($descricao, $token)) {
                        return true;
                    }
                }

                return false;
            });

        return $candidatos->count() === 1 ? (int) $candidatos->first()->id : null;
    }

    /**
     * @return list<string>
     */
    private function tokensDoCartao(?string $texto): array
    {
        if ($texto === null) {
            return [];
        }

        $tokens = array_filter(
            explode(' ', Normalizador::texto($texto)),
            fn (string $token) => mb_strlen($token) >= 3 && ! in_array($token, self::STOPWORDS_CARTAO, true),
        );

        return array_values($tokens);
    }
}
