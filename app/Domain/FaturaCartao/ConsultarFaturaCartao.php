<?php

declare(strict_types=1);

namespace App\Domain\FaturaCartao;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Shared\PeriodoMensal;
use App\Models\Card;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de consulta `consultar_fatura_cartao` (doc 02 §3.2) — varredura determinística
 * do banco que itemiza e soma a fatura de UM cartão numa competência.
 *
 * Fatura = as cobranças (parcelas) desse cartão cujo `vencimento` cai na competência
 * (YYYY-MM) — mesma atribuição por mês de vencimento do disponível/§4.5: cada cobrança
 * pertence a um único mês. É um EXTRATO: exclui só o que não é cobrança efetiva (§4.4:
 * `pendente_revisao`, `cancelado`, `estornado`) e MANTÉM `pago` (diferente de
 * "próximas contas", o extrato lista o que foi cobrado, pago ou não).
 *
 * Cartão resolvido por descrição (ilike) OU 4 dígitos finais, SEMPRE no escopo do
 * usuário; inexistente → fatura vazia (nunca vaza dados de outro usuário). A IA não
 * participa — só redige sobre estes números.
 */
final class ConsultarFaturaCartao
{
    /** @var list<string> Status que não são cobrança efetiva (§4.4). */
    private const STATUS_EXCLUIDOS = [
        StatusPagamento::PENDENTE_REVISAO,
        StatusPagamento::CANCELADO,
        StatusPagamento::ESTORNADO,
    ];

    public function para(int $userId, string $cartao, string $competencia): ResultadoConsultaFaturaCartao
    {
        $p = PeriodoMensal::fromString($competencia);
        $card = $this->resolverCartao($userId, $cartao);

        $trace = fn (int $registros): TraceDaConsulta => new TraceDaConsulta(
            ferramenta: 'consultar_fatura_cartao',
            filtros: ['cartao' => $cartao, 'competencia' => $competencia],
            registros: $registros,
        );

        // Cartão não encontrado: fatura vazia, sem tocar em dados de ninguém.
        if ($card === null) {
            return new ResultadoConsultaFaturaCartao(
                cartaoDescricao: $cartao,
                cartaoFinal4: null,
                totalCents: 0,
                itens: [],
                trace: $trace(0),
            );
        }

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', self::STATUS_EXCLUIDOS)
            ->pluck('id')
            ->all();

        $parcelas = Installment::query()
            ->whereBetween('vencimento', [$p->inicio->toDateString(), $p->fim->toDateString()])
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn (Builder $q) => $q
                ->where('user_id', $userId)
                ->where('card_id', $card->id))
            ->with('transaction')
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get();

        $total = 0;
        $itens = [];

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $total += $cents;

            $itens[] = [
                'descricao' => $parcela->transaction->descricao ?? 'Compra',
                'vencimento' => $parcela->vencimento->toDateString(),
                'cents' => $cents,
                'numero' => $parcela->numero,
                'total' => $parcela->total,
            ];
        }

        return new ResultadoConsultaFaturaCartao(
            cartaoDescricao: $card->descricao,
            cartaoFinal4: $card->final_4,
            totalCents: $total,
            itens: $itens,
            trace: $trace($parcelas->count()),
        );
    }

    /**
     * Resolve o cartão pela descrição (case-insensitive) OU pelos 4 dígitos finais
     * (escopo por usuário). Não encontrado → null (fatura vazia).
     */
    private function resolverCartao(int $userId, string $cartao): ?Card
    {
        return Card::query()
            ->where('user_id', $userId)
            ->where(fn (Builder $q) => $q
                ->where('descricao', 'ilike', $cartao)
                ->orWhere('final_4', $cartao))
            ->first();
    }
}
