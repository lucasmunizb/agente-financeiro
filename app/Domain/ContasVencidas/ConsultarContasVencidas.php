<?php

declare(strict_types=1);

namespace App\Domain\ContasVencidas;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\ProximasContas\ConsultarProximasContas;
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
    /** @var list<string> Status que NÃO são conta a pagar (liquidado + §4.4). */
    private const STATUS_EXCLUIDOS = [
        StatusPagamento::PAGO,
        StatusPagamento::PENDENTE_REVISAO,
        StatusPagamento::CANCELADO,
        StatusPagamento::ESTORNADO,
    ];

    public function para(int $userId, CarbonImmutable $hoje, ?int $janelaDias = null): ResultadoConsultaContasVencidas
    {
        $hojeData = $hoje->setTimezone('America/Sao_Paulo')->startOfDay();
        $ontem = $hojeData->subDay();
        $de = $janelaDias !== null ? $hojeData->subDays($janelaDias) : null;

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', self::STATUS_EXCLUIDOS)
            ->pluck('id')
            ->all();

        $parcelas = Installment::query()
            ->where('vencimento', '<', $hojeData->toDateString())
            ->when($de !== null, fn (Builder $q) => $q->where('vencimento', '>=', $de->toDateString()))
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn (Builder $q) => $q->where('user_id', $userId))
            ->with('transaction')
            ->orderBy('vencimento')
            ->get();

        $total = 0;
        $contas = [];

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $total += $cents;

            $contas[] = [
                'descricao' => $parcela->transaction->descricao ?? 'Conta',
                'vencimento' => $parcela->vencimento->toDateString(),
                'cents' => $cents,
                // "Verdade" de recorrente = transactions.recurrence_id (spec 10). Marca a
                // conta materializada para o selo/ícone no dashboard (etapa de frontend).
                'recorrente' => $parcela->transaction->recurrence_id !== null,
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
