<?php

declare(strict_types=1);

namespace App\Domain\Gastos;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\IA\Guard\PayloadDeResposta;
use App\Domain\Shared\Money;
use Carbon\CarbonInterface;

/**
 * Resultado da consulta `consultar_gastos` entregue por uma tool (doc 02 §3.2).
 *
 * Carrega o total e a quebra por categoria JÁ calculados pelo domínio
 * ({@see ConsultarGastos}) mais o {@see TraceDaConsulta} (fonte). O {@see payload()}
 * expõe o conjunto-verdade (total + subtotais) para o guard pós-geração (barreira 4)
 * validar a redação da IA.
 */
final class ResultadoConsultaGastos
{
    /**
     * @param  list<array{nome: string, cents: int}>  $porCategoria  quebra ordenada por valor desc
     * @param  list<array{descricao: string, cents: int, vencimento: CarbonInterface, parcela: ?string}>  $itens  lançamentos individuais (só quando `detalhar`), ordenados por vencimento
     */
    public function __construct(
        public readonly int $totalCents,
        public readonly array $porCategoria,
        public readonly TraceDaConsulta $trace,
        public readonly array $itens = [],
    ) {}

    /**
     * Conjunto de valores/datas que a resposta da IA pode citar (barreira 4, doc 02 §3.3):
     * o total, cada subtotal por categoria e — quando detalhado — o valor e o vencimento de
     * cada lançamento individual (senão a lista seria barrada como número/data inventados).
     */
    public function payload(): PayloadDeResposta
    {
        $valores = [$this->totalCents];
        $datas = [];

        foreach ($this->porCategoria as $linha) {
            $valores[] = $linha['cents'];
        }

        foreach ($this->itens as $item) {
            $valores[] = $item['cents'];
            $datas[] = $item['vencimento'];
        }

        return new PayloadDeResposta(valoresEmCentavos: $valores, datas: $datas);
    }

    /**
     * Payload textual entregue ao modelo: total + quebra por categoria em pt-BR + fonte.
     * Quando detalhado, adiciona a lista dos lançamentos individuais (descrição, valor,
     * vencimento e parcela) para o modelo poder discriminá-los na resposta.
     */
    public function paraPrompt(): string
    {
        $periodo = $this->trace->filtros['periodo'] ?? '';

        $linhas = [
            "gastos_periodo: {$periodo}",
            'total: '.Money::fromCents($this->totalCents)->formatBRL(),
        ];

        foreach ($this->porCategoria as $linha) {
            $linhas[] = "- {$linha['nome']}: ".Money::fromCents($linha['cents'])->formatBRL();
        }

        if ($this->itens !== []) {
            $linhas[] = 'lancamentos:';
            foreach ($this->itens as $item) {
                $valor = Money::fromCents($item['cents'])->formatBRL();
                $data = $item['vencimento']->format('d/m/Y');
                $parcela = $item['parcela'] !== null ? " (parcela {$item['parcela']})" : '';
                $linhas[] = "- {$item['descricao']}{$parcela}: {$valor} em {$data}";
            }
        }

        $linhas[] = $this->trace->resumo();

        return implode("\n", $linhas);
    }
}
