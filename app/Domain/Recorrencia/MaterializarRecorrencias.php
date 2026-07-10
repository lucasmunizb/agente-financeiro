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
            DB::transaction(function () use ($recorrencia): void {
                $ocorrencia = $recorrencia->proxima_em; // CarbonImmutable (immutable_date)

                $this->enfileirar->enfileirar(
                    new DadosGastoManual(
                        userId: $recorrencia->user_id,
                        descricao: $recorrencia->descricao,
                        valorTotalCents: $recorrencia->valor_cents,
                        dataCompra: $ocorrencia,
                        paymentMethodId: $recorrencia->payment_method_id,
                        parcelas: 1,
                        categoriaId: $recorrencia->categoria_id,
                        origem: 'recorrencia',
                        recurrenceId: $recorrencia->id,
                    ),
                    PendingConfirmation::ORIGEM_RECORRENCIA,
                    expiraEm: null,
                );

                $recorrencia->update([
                    'proxima_em' => OcorrenciaMensal::aPartirDe(
                        $recorrencia->dia,
                        $ocorrencia->addDay(),
                    )->format('Y-m-d'),
                ]);
            });

            $enfileiradas++;
        }

        return $enfileiradas;
    }
}
