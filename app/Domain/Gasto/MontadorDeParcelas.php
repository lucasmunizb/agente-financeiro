<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Domain\Parcelamento\GeradorDeParcelas;
use App\Domain\Vencimento\CalculadoraDeVencimento;
use App\Models\Card;
use Carbon\CarbonImmutable;

/**
 * Monta as parcelas (vencimento → valor derivado → status por data) a partir
 * dos dados de um gasto. Determinístico: o "hoje" é injetado. Compartilhado
 * entre o cadastro e a edição de gastos manuais.
 */
final class MontadorDeParcelas
{
    /**
     * @return array<int, ParcelaPrevia>
     */
    public function montar(DadosGastoManual $dados, CarbonImmutable $hoje): array
    {
        $primeiroVencimento = $this->primeiroVencimento($dados);
        $parcelas = GeradorDeParcelas::gerar($dados->valorTotalCents, $dados->parcelas, $primeiroVencimento);

        return array_map(
            fn ($parcela) => new ParcelaPrevia(
                numero: $parcela->numero,
                total: $parcela->total,
                vencimento: $parcela->vencimento,
                valor: $parcela->valor,
                statusCodigo: StatusDaParcela::para($parcela->vencimento, $hoje),
            ),
            $parcelas,
        );
    }

    /**
     * Resolve o vencimento da 1ª parcela: cartão respeita o ciclo da fatura;
     * fora de cartão vence na data da compra.
     */
    private function primeiroVencimento(DadosGastoManual $dados): CarbonImmutable
    {
        if ($dados->cardId !== null) {
            $card = Card::findOrFail($dados->cardId);

            return CalculadoraDeVencimento::cartao($dados->dataCompra, $card->dia_fechamento, $card->dia_vencimento);
        }

        return CalculadoraDeVencimento::foraDeCartao($dados->dataCompra);
    }
}
