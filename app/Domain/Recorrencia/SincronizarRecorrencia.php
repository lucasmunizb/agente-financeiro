<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Gasto\DadosGastoManual;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use Illuminate\Support\Facades\DB;

/**
 * Propaga a edição de um lançamento recorrente para o MOLDE da recorrência de origem — a
 * escolha "este e os próximos" (spec 10). Os meses ainda não materializados passam a usar os
 * novos valores: descrição, valor (centavos, regra 5), categoria, forma e o `dia` (derivado do
 * novo vencimento), recalculando `proxima_em` para o novo dia DENTRO do mês da próxima
 * ocorrência (mesmo mês, dia novo — reusa {@see OcorrenciaMensal}, com clamp de fim de mês).
 *
 * No-op seguro (devolve `false`, sem tocar a regra) quando a recorrência não está ATIVA
 * (cancelada não tem futuro a alterar) ou quando a forma virou cartão de crédito (recorrência
 * é sempre fora de cartão) — nesses casos só o lançamento do mês muda. Determinístico (regra 4);
 * registra auditoria antes/depois.
 */
final class SincronizarRecorrencia
{
    public function sincronizar(Recurrence $rec, DadosGastoManual $novos): bool
    {
        if ($rec->status !== Recurrence::STATUS_ATIVO) {
            return false;
        }

        // Recorrência não vive em cartão de crédito (crédito usa parcelas). Se a edição mudou a
        // forma para crédito, não há molde recorrente coerente a sincronizar — só o mês muda.
        if (PaymentMethod::whereKey($novos->paymentMethodId)->value('tipo') === PaymentMethod::CREDITO) {
            return false;
        }

        return DB::transaction(function () use ($rec, $novos): bool {
            // O dia do molde só muda quando o usuário escolheu DE FATO outro dia. Se o dia
            // da ocorrência editada é exatamente o clamp do dia do molde naquele mês (regra
            // "dia 31" materializada em 28/fev), a data não foi alterada pelo usuário — o
            // molde continua "todo dia 31" (auditoria P2-1).
            $diaClampadoDoMolde = OcorrenciaMensal::aPartirDe(
                $rec->dia,
                $novos->dataCompra->startOfMonth(),
            )->day;

            $dia = $novos->dataCompra->day === $diaClampadoDoMolde
                ? $rec->dia
                : $novos->dataCompra->day;

            $antes = [
                'descricao' => $rec->descricao,
                'valor_cents' => $rec->valor_cents,
                'categoria_id' => $rec->categoria_id,
                'payment_method_id' => $rec->payment_method_id,
                'dia' => $rec->dia,
                'proxima_em' => $rec->proxima_em?->format('Y-m-d'),
            ];

            // Mantém o mês da próxima ocorrência, só troca o dia (clampado ao fim do mês).
            $proximaEm = $rec->proxima_em !== null
                ? OcorrenciaMensal::aPartirDe($dia, $rec->proxima_em->startOfMonth())->format('Y-m-d')
                : null;

            $rec->update([
                'descricao' => $novos->descricao,
                'valor_cents' => $novos->valorTotalCents,
                'categoria_id' => $novos->categoriaId,
                'payment_method_id' => $novos->paymentMethodId,
                'dia' => $dia,
                'proxima_em' => $proximaEm,
            ]);

            AuditLog::create([
                'user_id' => $rec->user_id,
                'entidade' => 'recurrence',
                'entidade_id' => $rec->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => $antes,
                'depois' => [
                    'descricao' => $rec->descricao,
                    'valor_cents' => $rec->valor_cents,
                    'categoria_id' => $rec->categoria_id,
                    'payment_method_id' => $rec->payment_method_id,
                    'dia' => $rec->dia,
                    'proxima_em' => $proximaEm,
                ],
                'origem' => 'recorrencia',
            ]);

            return true;
        });
    }
}
