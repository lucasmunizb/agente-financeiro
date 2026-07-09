<?php

declare(strict_types=1);

namespace App\Domain\FaturaCartao;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Resultado da consulta `consultar_fatura_cartao` entregue por uma tool (doc 02 §3.2).
 *
 * Carrega a identidade do cartão, o total e o extrato itemizado JÁ calculados pelo
 * domínio ({@see ConsultarFaturaCartao}) mais o {@see TraceDaConsulta} (fonte). O
 * {@see payload()} expõe o conjunto-verdade (total + valor e vencimento de cada
 * cobrança) para o guard pós-geração (barreira 4) validar a redação da IA.
 */
final class ResultadoConsultaFaturaCartao
{
    /**
     * @param  list<array{descricao: string, vencimento: string, cents: int, numero: int, total: int, categoria_id: ?int}>  $itens  ordenada por vencimento asc
     */
    public function __construct(
        public readonly string $cartaoDescricao,
        public readonly ?string $cartaoFinal4,
        public readonly int $totalCents,
        public readonly array $itens,
        public readonly TraceDaConsulta $trace,
    ) {}

    /**
     * Conjunto que a resposta da IA pode citar (barreira 4, doc 02 §3.3): o total e o
     * valor de cada cobrança (centavos) mais os vencimentos (datas).
     */
    public function payload(): PayloadDeResposta
    {
        $valores = [$this->totalCents];
        $datas = [];

        foreach ($this->itens as $item) {
            $valores[] = $item['cents'];
            $datas[] = CarbonImmutable::parse($item['vencimento'], 'America/Sao_Paulo');
        }

        return new PayloadDeResposta(valoresEmCentavos: $valores, datas: $datas);
    }

    /**
     * Payload textual entregue ao modelo: cabeçalho do cartão + competência, total e
     * cada cobrança (parcela n/N quando parcelado, vencimento em pt-BR) + fonte.
     */
    public function paraPrompt(): string
    {
        $competencia = $this->trace->filtros['competencia'] ?? '';
        $cartao = $this->cartaoFinal4 !== null
            ? "{$this->cartaoDescricao} (final {$this->cartaoFinal4})"
            : $this->cartaoDescricao;

        $linhas = [
            "fatura_cartao: {$cartao} — {$competencia}",
            'total: '.Money::fromCents($this->totalCents)->formatBRL(),
        ];

        foreach ($this->itens as $item) {
            $vencimento = CarbonImmutable::parse($item['vencimento'])->format('d/m/Y');
            $parcela = $item['total'] > 1 ? " ({$item['numero']}/{$item['total']})" : '';
            $linhas[] = "- {$item['descricao']}{$parcela} (vence {$vencimento}): ".Money::fromCents($item['cents'])->formatBRL();
        }

        $linhas[] = $this->trace->resumo();

        return implode("\n", $linhas);
    }
}
