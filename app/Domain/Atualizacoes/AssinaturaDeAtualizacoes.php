<?php

declare(strict_types=1);

namespace App\Domain\Atualizacoes;

use App\Models\Installment;
use App\Models\Transaction;

/**
 * Assinatura determinística do estado financeiro de um usuário — o "carimbo" que
 * o frontend usa para saber se a tela (dashboard/extrato) ficou desatualizada
 * depois de uma confirmação por outro canal (ex.: o chat do Telegram). A
 * apresentação (polling + reload) é etapa separada (regra 3): aqui só produzimos
 * o opaco.
 *
 * É um hash de agregados de `transactions` + `installments` do usuário — contagem,
 * somatório de valor, somatório de status e maior id. Muda a cada registro, edição
 * (as parcelas são regeneradas, então o max id sobe), pagamento ou cancelamento
 * (o status da parcela muda). Propriedades desta escolha:
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

        return hash('sha256', implode('|', [
            $tx->n, $tx->soma, $tx->maxid,
            $inst->n, $inst->soma, $inst->maxid,
        ]));
    }
}
