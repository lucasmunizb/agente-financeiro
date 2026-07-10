<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Confirmacao\ConfirmarPendente;
use App\Domain\Gasto\RegistrarPagamentoParcela;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Marcar como pago" uma ocorrência de recorrência que está na fila (confirmação pendente de
 * origem `recorrencia`, spec 10). Fila e extrato COEXISTEM: pagar aqui resolve a confirmação.
 *
 * Não recalcula nem cria caminho novo (regra 4): COMPÕE o que já existe —
 *  1. {@see ConfirmarPendente} materializa o lançamento a partir do payload (com o
 *     `recurrence_id`) e resolve o pendente (`confirmado`), tudo escopado por usuário;
 *  2. {@see RegistrarPagamentoParcela} marca a única parcela (recorrência é sempre 1x, fora de
 *     cartão) como `pago` na data de "agora", reavaliando o status agregado da transação.
 *
 * Idempotente: um segundo "pagar" acha o pendente já resolvido e devolve null (nenhum segundo
 * lançamento). A recorrência segue ativa — o avanço do ponteiro do próximo mês é do agendador
 * ({@see MaterializarRecorrencias}); aqui não se recua nem cancela nada.
 */
final class PagarRecorrenciaPendente
{
    public function __construct(
        private readonly ConfirmarPendente $confirmar = new ConfirmarPendente,
        private readonly RegistrarPagamentoParcela $pagar = new RegistrarPagamentoParcela,
    ) {}

    public function pagar(int $pendenteId, int $userId, CarbonImmutable $agora): ?Transaction
    {
        return DB::transaction(function () use ($pendenteId, $userId, $agora): ?Transaction {
            $transaction = $this->confirmar->confirmar($pendenteId, $userId, $agora);

            // Já confirmado/expirado (uso único) ⇒ idempotente: nada a pagar.
            if ($transaction === null) {
                return null;
            }

            $parcela = $transaction->installments->first();
            $this->pagar->confirmar($parcela->id, $userId, $agora);

            return $transaction->fresh(['installments']);
        });
    }
}
