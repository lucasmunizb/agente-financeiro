<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Gasto\DadosGastoManual;
use App\Models\AuditLog;
use App\Models\Recurrence;
use Illuminate\Support\Facades\DB;

/**
 * Propaga uma edição para o MOLDE da recorrência — a escolha "este e os próximos" (spec 10,
 * revista pela spec 12). As competências ainda não geradas passam a usar os novos valores:
 * descrição, valor (centavos, regra 5), categoria, forma e o `dia` (derivado do novo
 * vencimento, com clamp de fim de mês via {@see OcorrenciaMensal}).
 *
 * Não recusa mais cartão de crédito (spec 12, D3 — recorrência em cartão é permitida). Não
 * mexe no ponteiro `proxima_em`: ele passou a ser o 1º dia do primeiro MÊS de origem não
 * gerado, então trocar o dia-do-mês não move o mês. Também não reescreve as ocorrências já
 * geradas — elas são snapshots auto-contidos, e mudar "só este mês" é
 * {@see EditarOcorrencia}.
 *
 * No-op seguro (devolve `false`) quando a recorrência não está ATIVA — cancelada não tem
 * futuro a alterar. Determinístico (regra 4); registra auditoria antes/depois.
 */
final class SincronizarRecorrencia
{
    public function sincronizar(Recurrence $rec, DadosGastoManual $novos): bool
    {
        if ($rec->status !== Recurrence::STATUS_ATIVO) {
            return false;
        }

        return DB::transaction(function () use ($rec, $novos): bool {
            // O dia do molde só muda quando o usuário escolheu DE FATO outro dia. Se o dia
            // da ocorrência editada é exatamente o clamp do dia do molde naquele mês (regra
            // "dia 31" resolvida em 28/fev), a data não foi alterada pelo usuário — o
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
                'card_id' => $rec->card_id,
                'dia' => $rec->dia,
            ];

            $rec->update([
                'descricao' => $novos->descricao,
                'valor_cents' => $novos->valorTotalCents,
                'categoria_id' => $novos->categoriaId,
                'payment_method_id' => $novos->paymentMethodId,
                'card_id' => $novos->cardId,
                'dia' => $dia,
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
                    'card_id' => $rec->card_id,
                    'dia' => $rec->dia,
                ],
                'origem' => 'recorrencia',
            ]);

            return true;
        });
    }
}
