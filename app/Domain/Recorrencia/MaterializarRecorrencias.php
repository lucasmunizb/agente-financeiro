<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Confirmacao\EnfileirarConfirmacao;
use App\Domain\Gasto\DadosGastoManual;
use App\Models\PendingConfirmation;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Motor do comando agendado (spec 10). Para cada recorrência `ativo` cuja ocorrência já chegou
 * (`proxima_em <= hoje`), ENFILEIRA UMA confirmação pendente (regra 7, sem auto-save) via
 * {@see EnfileirarConfirmacao} — origem `recorrencia`, ligada à recorrência (`recurrence_id`)
 * para a cascata "rejeitar → cancela" (C7) — e AVANÇA o ponteiro para o mês seguinte (idempotente:
 * rodar de novo no mesmo dia não reenfileira). Nunca grava lançamento: isso só nasce no "sim"
 * do usuário, que reusa RegistrarGastoManual sem recalcular (regra 4). Confirmações de
 * recorrência não expiram (`expira_em = null`): uma conta fixa esquecida espera na fila.
 */
final class MaterializarRecorrencias
{
    public function __construct(
        private readonly EnfileirarConfirmacao $enfileirar = new EnfileirarConfirmacao,
    ) {}

    public function paraTodos(CarbonImmutable $hoje): int
    {
        $hojeData = $hoje->setTimezone(RelativeDate::TIMEZONE)->startOfDay();

        $recorrencias = Recurrence::query()
            ->where('status', Recurrence::STATUS_ATIVO)
            ->whereNotNull('proxima_em')
            ->whereDate('proxima_em', '<=', $hojeData->format('Y-m-d'))
            ->get();

        $enfileiradas = 0;

        foreach ($recorrencias as $recorrencia) {
            if ($this->materializarUma($recorrencia, $hojeData)) {
                $enfileiradas++;
            }
        }

        return $enfileiradas;
    }

    /**
     * Materializa UMA recorrência de forma concorrência-segura: re-lê a linha SOB LOCK dentro
     * da transação e re-verifica status/ponteiro — o scheduler pode rodar em mais de um worker
     * e a listagem de {@see paraTodos()} pode estar defasada. Sem isso, duas execuções
     * simultâneas enfileiram a MESMA ocorrência e avançam o ponteiro duas vezes (mês pulado).
     * Devolve true só quando esta chamada de fato enfileirou.
     */
    public function materializarUma(Recurrence $recorrencia, CarbonImmutable $hojeData): bool
    {
        return DB::transaction(function () use ($recorrencia, $hojeData): bool {
            /** @var Recurrence|null $atual */
            $atual = Recurrence::query()
                ->whereKey($recorrencia->id)
                ->where('status', Recurrence::STATUS_ATIVO)
                ->whereNotNull('proxima_em')
                ->lockForUpdate()
                ->first();

            if ($atual === null || $atual->proxima_em->toDateString() > $hojeData->toDateString()) {
                return false; // outra execução já materializou (ou o estado mudou)
            }

            $ocorrencia = $atual->proxima_em; // CarbonImmutable (immutable_date)

            $this->enfileirar->enfileirar(
                new DadosGastoManual(
                    userId: $atual->user_id,
                    descricao: $atual->descricao,
                    valorTotalCents: $atual->valor_cents,
                    dataCompra: $ocorrencia,
                    paymentMethodId: $atual->payment_method_id,
                    parcelas: 1,
                    categoriaId: $atual->categoria_id,
                    origem: 'recorrencia',
                    recurrenceId: $atual->id,
                ),
                PendingConfirmation::ORIGEM_RECORRENCIA,
                expiraEm: null,
            );

            $atual->update([
                'proxima_em' => OcorrenciaMensal::aPartirDe(
                    $atual->dia,
                    $ocorrencia->addDay(),
                )->format('Y-m-d'),
            ]);

            return true;
        });
    }
}
