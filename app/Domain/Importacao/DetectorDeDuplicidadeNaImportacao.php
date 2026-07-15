<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Duplicidade\ChaveDeDuplicidade;
use App\Domain\Duplicidade\DetectorDeDuplicidade;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Detecção de duplicidade na importação de fatura (spec 07 §6, C7). Reusa o detector
 * determinístico do Bloco 1: a chave é valor + descrição + data + nº de parcelas —
 * NUNCA a parcela atual. Concilia com os lançamentos já salvos do usuário (qualquer
 * origem: telegram, manual ou pdf). Escopo estrito por `user_id`.
 */
final class DetectorDeDuplicidadeNaImportacao
{
    /**
     * Marca cada lançamento como duplicado (true) ou novo (false), na mesma ordem.
     *
     * @param  array<int, LancamentoExtraido>  $lancamentos
     * @return array<int, bool>
     */
    public function marcar(int $userId, array $lancamentos): array
    {
        $existentes = $this->chavesExistentes($userId, $lancamentos);

        return array_map(
            fn (LancamentoExtraido $l) => DetectorDeDuplicidade::ehDuplicado(
                ChaveDeDuplicidade::de($l->valorCents, $l->descricao, $l->data, $l->parcelas),
                $existentes,
            ),
            $lancamentos,
        );
    }

    /**
     * Chaves de duplicidade dos lançamentos já salvos do usuário (nº de parcelas =
     * total de installments, nunca a parcela vigente). Pré-filtrado em SQL pelos
     * valores/datas do lote (componentes exatos da chave) — carregar TODO o histórico
     * em memória cresce sem limite (auditoria P2-4).
     *
     * @param  array<int, LancamentoExtraido>  $lancamentos
     * @return array<int, ChaveDeDuplicidade>
     */
    private function chavesExistentes(int $userId, array $lancamentos): array
    {
        if ($lancamentos === []) {
            return [];
        }

        $valores = array_values(array_unique(array_map(fn (LancamentoExtraido $l) => $l->valorCents, $lancamentos)));
        $datas = array_values(array_unique(array_map(fn (LancamentoExtraido $l) => $l->data->toDateString(), $lancamentos)));

        return Transaction::query()
            ->where('user_id', $userId)
            ->whereIn('valor_total_cents', $valores)
            ->whereIn('data_compra', $datas)
            ->withCount('installments')
            ->get()
            ->map(fn (Transaction $tx) => ChaveDeDuplicidade::de(
                $tx->valor_total_cents,
                $tx->descricao,
                CarbonImmutable::parse($tx->data_compra->toDateString(), RelativeDate::TIMEZONE),
                $tx->installments_count,
            ))
            ->all();
    }
}
