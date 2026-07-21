<?php

declare(strict_types=1);

namespace App\Domain\Atualizacoes;

use App\Models\Installment;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\Transaction;

/**
 * Assinatura determinística do estado financeiro de um usuário — o "carimbo" que
 * o frontend usa para saber se a tela (dashboard/extrato) ficou desatualizada
 * depois de uma confirmação por outro canal (ex.: o chat do Telegram). A
 * apresentação (polling + reload) é etapa separada (regra 3): aqui só produzimos
 * o opaco.
 *
 * É um hash de agregados das cinco tabelas que alimentam os quadros — `transactions`,
 * `installments`, `recurrences`, `recurrence_occurrences` e `pending_confirmations` — por contagem (total e por
 * status), somatório de valor/status e maior id. Muda a cada registro, edição (as parcelas
 * são regeneradas, então o max id sobe), pagamento, cancelamento (o status da parcela
 * muda), e também quando o agendador enfileira uma ocorrência, quando um pendente é
 * confirmado/rejeitado ou quando uma recorrência é criada/cancelada. Propriedades desta
 * escolha:
 *   - determinística e independente do relógio (dois cálculos sem mudança batem;
 *     não sofre com fuso nem com "hoje" congelado nos testes);
 *   - escopo estrito por usuário (regra de isolamento): a query filtra por
 *     `user_id`, então a assinatura de um nunca reflete o dado do outro;
 *   - não vaza número calculado para o cliente (regra 4): sai só um hash opaco,
 *     nunca saldo/valor.
 */
final class AssinaturaDeAtualizacoes
{
    public function para(int $userId): string
    {
        $tx = Transaction::query()
            ->where('user_id', $userId)
            ->selectRaw('count(*) as n, coalesce(sum(valor_total_cents), 0) as soma, coalesce(max(id), 0) as maxid')
            ->first();

        $inst = Installment::query()
            ->whereHas('transaction', fn ($q) => $q->where('user_id', $userId))
            ->selectRaw('count(*) as n, coalesce(sum(status_id), 0) as soma, coalesce(max(id), 0) as maxid')
            ->first();

        // Os quadros não mostram só lançamento real: uma conta fixa aparece pela FILA (enfileirada
        // pelo agendador, à espera do "sim") ou pelo MOLDE (dia ainda não chegou). Sem estas duas
        // agregações a tela não recarrega quando o agendador enfileira, quando o usuário rejeita
        // um pendente ou quando uma recorrência é criada/cancelada — nenhum desses toca
        // transactions/installments.
        //
        // A contagem POR STATUS é o que capta as mudanças in-place, que não movem contagem nem
        // max id: cancelar a recorrência e confirmar/rejeitar o pendente tiram a linha do quadro
        // sem apagar nada. (`updated_at` não serve: a coluna tem precisão de segundo, então uma
        // criação seguida de update no mesmo segundo devolveria o mesmo carimbo.) `sum(valor_cents)`
        // e `sum(dia)` cobrem a edição do molde, que muda o valor e a data exibidos.
        $rec = Recurrence::query()
            ->where('user_id', $userId)
            ->selectRaw(
                'count(*) as n, coalesce(sum(valor_cents), 0) as soma, coalesce(sum(dia), 0) as dias,'
                .' coalesce(max(id), 0) as maxid, count(*) filter (where status = ?) as ativos',
                [Recurrence::STATUS_ATIVO],
            )
            ->first();

        // A conta fixa de um mês é uma OCORRÊNCIA (spec 12): sem esta agregação a tela não
        // recarrega quando o agendador gera o mês, quando o cartão liquida sozinho nem quando
        // o usuário marca a ocorrência como paga — nada disso toca transactions/installments.
        // A soma de `status_id` capta justamente as mudanças in-place (aberto → pago/cancelado).
        $oc = RecurrenceOccurrence::query()
            ->where('user_id', $userId)
            ->selectRaw(
                'count(*) as n, coalesce(sum(valor_cents), 0) as soma,'
                .' coalesce(sum(status_id), 0) as status, coalesce(max(id), 0) as maxid'
            )
            ->first();

        $fila = PendingConfirmation::query()
            ->where('user_id', $userId)
            ->selectRaw(
                'count(*) as n, coalesce(max(id), 0) as maxid, count(*) filter (where status = ?) as pendentes',
                [PendingConfirmation::STATUS_PENDENTE],
            )
            ->first();

        return hash('sha256', implode('|', [
            $tx->n, $tx->soma, $tx->maxid,
            $inst->n, $inst->soma, $inst->maxid,
            $rec->n, $rec->soma, $rec->dias, $rec->maxid, $rec->ativos,
            $oc->n, $oc->soma, $oc->status, $oc->maxid,
            $fila->n, $fila->maxid, $fila->pendentes,
        ]));
    }
}
