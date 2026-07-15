<?php

declare(strict_types=1);

namespace App\Domain\ProximasContas;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de consulta `consultar_proximas_contas` (doc 02 §3.2) — varredura
 * determinística do banco que itemiza e soma as contas A VENCER do usuário dentro de
 * uma janela rolante a partir de "hoje".
 *
 * Janela = parcelas com `vencimento` entre hoje e hoje+`janelaDias` (inclusive). Só
 * para frente: o que já venceu não é "próxima conta". Exclui o que não é mais conta a
 * pagar: liquidado (`pago`) e os status que não entram no cálculo (§4.4:
 * `pendente_revisao`, `cancelado`, `estornado`). Sobram aberto/agendado/pago_parcial.
 *
 * "Hoje" é INJETADO (nunca lido do relógio global) — determinismo e testabilidade
 * (regra 4/5); a IA jamais resolve datas por conta própria. Escopo ESTRITO por usuário.
 */
final class ConsultarProximasContas
{
    /** @var list<string> Status que NÃO são conta a pagar: liquidado + os excluídos comuns (§4.4). */
    private const STATUS_PROPRIOS_EXCLUIDOS = [StatusPagamento::PAGO];

    public function para(int $userId, CarbonImmutable $hoje, int $janelaDias): ResultadoConsultaProximasContas
    {
        $de = $hoje->setTimezone('America/Sao_Paulo')->startOfDay();
        $ate = $de->addDays($janelaDias);

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', [...self::STATUS_PROPRIOS_EXCLUIDOS, ...StatusPagamento::EXCLUIDOS])
            ->pluck('id')
            ->all();

        $parcelas = Installment::query()
            ->whereBetween('vencimento', [$de->toDateString(), $ate->toDateString()])
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
            ferramenta: 'consultar_proximas_contas',
            filtros: [
                'janela_dias' => $janelaDias,
                'de' => $de->toDateString(),
                'ate' => $ate->toDateString(),
            ],
            registros: $parcelas->count(),
        );

        return new ResultadoConsultaProximasContas(
            totalCents: $total,
            contas: $contas,
            trace: $trace,
        );
    }
}
