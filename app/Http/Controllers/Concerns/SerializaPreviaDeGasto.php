<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Gasto\ParcelaPrevia;
use App\Domain\Gasto\PreviaGastoManual;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\LancamentoFormController;

/**
 * Serializa uma {@see PreviaGastoManual} para o JSON que o formulário de gasto
 * (modal §7.7b e página §7.7) exibe na etapa de confirmação. Formatação pt-BR só
 * na borda (regra 5); a tela apenas mostra — o cálculo é do domínio (regra 4).
 *
 * Compartilhado entre o cadastro ({@see GastoController}) e a
 * edição ({@see LancamentoFormController}) — mesma prévia.
 */
trait SerializaPreviaDeGasto
{
    /**
     * @return array<string, mixed>
     */
    protected function previaParaJson(PreviaGastoManual $previa): array
    {
        return [
            'descricao' => $previa->descricao,
            'valorTotal' => $previa->valorTotal->formatBRL(),
            'ehDuplicado' => $previa->ehDuplicado,
            // Data em que a 1ª parcela nascerá PAGA (null quando não é o caso). A confirmação
            // precisa dizer isso ANTES do "sim" (regra 7); formatação pt-BR só aqui (regra 5).
            'dataPagamento' => $previa->dataPagamento?->format('d/m/Y'),
            'parcelas' => array_map(
                fn (ParcelaPrevia $p): array => [
                    'numero' => $p->numero,
                    'total' => $p->total,
                    'label' => sprintf('%d/%d', $p->numero, $p->total),
                    'valor' => $p->valor->formatBRL(),
                    'vencimento' => $p->vencimento->format('d/m/Y'),
                    'status' => $p->statusCodigo,
                ],
                $previa->parcelas,
            ),
        ];
    }
}
