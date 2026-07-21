<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Models\AuditLog;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ligar o switch "Repete todo mês?" num lançamento que já existe (spec 12, D5): o lançamento é
 * SUBSTITUÍDO pela recorrência, em vez de coexistir com ela.
 *
 * Antes da spec 12 os dois viviam lado a lado (a transaction ganhava um `recurrence_id`) e o
 * mês exibia as duas linhas. Agora a conta fixa de um mês é a {@see RecurrenceOccurrence}
 * dele: o molde nasce ({@see RegistrarRecorrencia}, que já gera a ocorrência do mês corrente —
 * D2) e a transaction original, com as suas parcelas, é REMOVIDA. É remoção física mesmo: a
 * linha não representa mais nada, e o rastro fica no `audit_log` (LGPD).
 *
 * Tudo na mesma transação: ou a conversão acontece inteira, ou nada muda.
 */
final class ConverterLancamentoEmRecorrencia
{
    public function __construct(
        private readonly RegistrarRecorrencia $registrar = new RegistrarRecorrencia,
    ) {}

    public function converter(Transaction $tx, DadosRecorrencia $dados, CarbonImmutable $hoje): Recurrence
    {
        return DB::transaction(function () use ($tx, $dados, $hoje): Recurrence {
            $recorrencia = $this->registrar->registrar($dados, $hoje);

            AuditLog::create([
                'user_id' => $tx->user_id,
                'entidade' => 'transaction',
                'entidade_id' => $tx->id,
                'acao' => AuditLog::ACAO_EXCLUIR,
                'antes' => [
                    'descricao' => $tx->descricao,
                    'valor_total_cents' => $tx->valor_total_cents,
                    'data_compra' => $tx->data_compra?->toDateString(),
                ],
                'depois' => ['convertido_em_recorrencia' => $recorrencia->id],
                'origem' => 'recorrencia',
            ]);

            $tx->installments()->forceDelete();
            $tx->forceDelete();

            return $recorrencia;
        });
    }
}
