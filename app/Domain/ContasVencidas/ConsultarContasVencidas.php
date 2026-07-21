<?php

declare(strict_types=1);

namespace App\Domain\ContasVencidas;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\ProximasContas\ConsultarProximasContas;
use App\Domain\Shared\OpaqueId;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de consulta `consultar_contas_vencidas` (spec 06b) — espelho RETROSPECTIVO de
 * {@see ConsultarProximasContas}: varredura determinística que
 * itemiza e soma as contas EM ATRASO do usuário — parcelas com `vencimento` ANTERIOR a
 * "hoje" que ainda são conta a pagar.
 *
 * Fronteira EXCLUSIVA: hoje NÃO está em atraso (é o limite inferior que próximas contas
 * inclui). Assim "em atraso" (`vencimento < hoje`) e "a vencer" (`vencimento >= hoje`) são
 * partições disjuntas e completas em torno de hoje — sem dupla contagem. Atraso é definido
 * por DATA, não pelo rótulo de status (que pode estar defasado): exclui apenas o que já não
 * é conta a pagar — liquidado (`pago`) e os status da §4.4 (`pendente_revisao`, `cancelado`,
 * `estornado`). Sobram aberto/agendado/vencido/pago_parcial.
 *
 * Janela retrospectiva OPCIONAL: sem `janelaDias`, entram TODAS as vencidas em aberto (sem
 * limite inferior); com `janelaDias`, só `[hoje − janelaDias, ontem]`. "Hoje" é INJETADO
 * (nunca o relógio global) — determinismo e testabilidade (regra 4/5). Escopo ESTRITO por
 * usuário.
 */
final class ConsultarContasVencidas
{
    /** @var list<string> Status que NÃO são conta a pagar: liquidado + os excluídos comuns (§4.4). */
    private const STATUS_PROPRIOS_EXCLUIDOS = [StatusPagamento::PAGO];

    public function para(int $userId, CarbonImmutable $hoje, ?int $janelaDias = null): ResultadoConsultaContasVencidas
    {
        $hojeData = $hoje->setTimezone('America/Sao_Paulo')->startOfDay();
        $ontem = $hojeData->subDay();
        $de = $janelaDias !== null ? $hojeData->subDays($janelaDias) : null;

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', [...self::STATUS_PROPRIOS_EXCLUIDOS, ...StatusPagamento::EXCLUIDOS])
            ->pluck('id')
            ->all();

        $parcelas = Installment::query()
            ->where('vencimento', '<', $hojeData->toDateString())
            ->when($de !== null, fn (Builder $q) => $q->where('vencimento', '>=', $de->toDateString()))
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn (Builder $q) => $q->where('user_id', $userId))
            // `transaction.card` alimenta o agrupamento por fatura no quadro do dashboard
            // ({@see \App\Domain\Dashboard\AgruparContasDeCartao}) — sem N+1.
            ->with('transaction.card')
            ->orderBy('vencimento')
            ->get();

        $total = 0;
        $contas = [];

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $total += $cents;

            // Alvos das ações da linha (decisão do usuário 2026-07-21): o quadro deixou de
            // ser só leitura — dá para marcar pago e editar sem sair do dashboard. Ids sempre
            // OPACOS. Cartão fica sem alvo de pagamento: a fatura é quem quita (§4.3).
            $ehCartao = $parcela->transaction?->card_id !== null;

            $contas[] = [
                'descricao' => $parcela->transaction->descricao ?? 'Conta',
                'vencimento' => $parcela->vencimento->toDateString(),
                'cents' => $cents,
                // Recorrência não vive mais em `transactions` (spec 12): toda parcela aqui é
                // lançamento comum. As contas fixas entram pelas ocorrências, no ResumoDoMes.
                'recorrente' => false,
                // Em cartão o `vencimento` acima JÁ é o da fatura: quem consome pode somar as
                // cobranças do mesmo cartão numa linha só (o usuário paga a fatura, não a compra).
                'cartaoId' => $parcela->transaction?->card_id,
                'cartaoDescricao' => $parcela->transaction?->card?->descricao,
                'parcelaId' => $ehCartao ? null : OpaqueId::encode((int) $parcela->id),
                'transactionId' => OpaqueId::encode((int) $parcela->transaction_id),
                'pagavel' => ! $ehCartao,
            ];
        }

        $trace = new TraceDaConsulta(
            ferramenta: 'consultar_contas_vencidas',
            filtros: [
                'janela_dias' => $janelaDias,
                'de' => $de?->toDateString(),
                'ate' => $ontem->toDateString(),
            ],
            registros: $parcelas->count(),
        );

        return new ResultadoConsultaContasVencidas(
            totalCents: $total,
            contas: $contas,
            trace: $trace,
        );
    }
}
