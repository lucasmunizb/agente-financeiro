<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;

/**
 * Liquidação automática das ocorrências EM CARTÃO (spec 12, D3). Uma cobrança no cartão não
 * espera o vencimento da fatura: assim que a DATA DE COBRANÇA chega, o cartão debitou e, do
 * ponto de vista do usuário, a conta já foi paga — vira `pago` sozinha, sem botão.
 *
 * `data_pagamento` recebe a PRÓPRIA data de cobrança (verdade histórica), não "hoje": se o
 * agendador ficou parado três dias, a cobrança continua tendo acontecido no dia dela.
 *
 * Fora de cartão NUNCA passa por aqui (R9c): PIX/débito/boleto dependem do "marcar como paga"
 * do usuário ({@see PagarOcorrencia}). Idempotente — só toca o que está `aberto`. "Hoje" é
 * injetado; nenhum relógio global é lido (regras 4 e 5).
 */
final class LiquidarOcorrenciasDeCartao
{
    /** @return int quantas ocorrências foram liquidadas nesta execução */
    public function paraTodos(CarbonImmutable $hoje): int
    {
        $hojeData = $hoje->setTimezone(RelativeDate::TIMEZONE)->startOfDay();

        $ocorrencias = RecurrenceOccurrence::query()
            ->whereNotNull('card_id')
            ->where('status_id', StatusPagamento::idFor(StatusPagamento::ABERTO))
            ->whereDate('data_cobranca', '<=', $hojeData->toDateString())
            ->get();

        $liquidadas = 0;

        foreach ($ocorrencias as $ocorrencia) {
            if ($this->paraOcorrencia($ocorrencia, $hojeData)) {
                $liquidadas++;
            }
        }

        return $liquidadas;
    }

    /**
     * Liquida UMA ocorrência, se ela for de cartão, estiver `aberto` e a cobrança já tiver
     * acontecido. Usada tanto pelo agendador quanto pelo cadastro ({@see RegistrarRecorrencia}),
     * para a regra viver num lugar só. Devolve `false` quando não havia o que liquidar.
     */
    public function paraOcorrencia(RecurrenceOccurrence $ocorrencia, CarbonImmutable $hoje): bool
    {
        $hojeData = $hoje->setTimezone(RelativeDate::TIMEZONE)->startOfDay();

        if (! $ocorrencia->ehCartao()
            || $ocorrencia->status_id !== StatusPagamento::idFor(StatusPagamento::ABERTO)
            || $ocorrencia->data_cobranca->toDateString() > $hojeData->toDateString()) {
            return false;
        }

        $ocorrencia->update([
            'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
            // Instante do débito = início do dia da cobrança em SP, convertido para UTC antes
            // de gravar (a coluna é timestamptz e o driver descarta o offset se não converter).
            'data_pagamento' => $ocorrencia->data_cobranca
                ->startOfDay()
                ->shiftTimezone(RelativeDate::TIMEZONE)
                ->setTimezone('UTC'),
        ]);

        return true;
    }
}
