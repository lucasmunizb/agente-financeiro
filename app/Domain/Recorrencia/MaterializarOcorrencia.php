<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Materializa SOB DEMANDA a ocorrência que ainda é só previsão (spec 12 + spec 13).
 *
 * O quadro do dashboard e o extrato mostram a conta fixa do mês antes de o agendador gerá-la
 * ({@see ProjetarRecorrencias}) — e uma projeção não tem id, logo não tinha como ser paga. Quem
 * paga adiantado precisa que a ocorrência PASSE A EXISTIR primeiro; a partir daí o pagamento
 * segue pelo caminho já testado ({@see PagarOcorrencia}), sem regra nova de dinheiro.
 *
 * Reusa {@see GerarOcorrencias::paraMes()} — o mesmo snapshot do molde que o agendador grava,
 * para que a linha criada aqui seja indistinguível da gerada por ele. O ponteiro `proxima_em`
 * NÃO se move: ele é do agendador, e a competência já materializada cai fora dele pela UNIQUE
 * `(recurrence_id, competencia)` / `NOT EXISTS` da projeção — sem dupla contagem.
 *
 * Escopo ESTRITO por usuário (404 para molde alheio ou cancelado) e "hoje" injetado (regras 4 e 5).
 */
final class MaterializarOcorrencia
{
    public function __construct(
        private readonly GerarOcorrencias $gerar = new GerarOcorrencias,
    ) {}

    /**
     * @param  string  $competencia  YYYY-MM do mês que a linha prevista representa
     *
     * @throws ModelNotFoundException molde inexistente, alheio ou cancelado
     * @throws PagamentoNaoPermitidoException cartão, mês passado, competência anterior ao início ou mal formada
     */
    public function para(int $recorrenciaId, int $userId, string $competencia, CarbonImmutable $hoje): RecurrenceOccurrence
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia) !== 1) {
            throw new PagamentoNaoPermitidoException('Competência inválida.');
        }

        $mesCorrente = $hoje->setTimezone(RelativeDate::TIMEZONE)->format('Y-m');

        // Mês passado é retrato fechado: a projeção não existe lá (ProjetarRecorrencias devolve
        // vazio), então materializar seria inventar uma cobrança que o agendador nunca gerou.
        if ($competencia < $mesCorrente) {
            throw new PagamentoNaoPermitidoException('Esta conta fixa não está prevista para um mês já encerrado.');
        }

        return DB::transaction(function () use ($recorrenciaId, $userId, $competencia, $hoje): RecurrenceOccurrence {
            /** @var Recurrence $molde */
            $molde = Recurrence::query()
                ->where('user_id', $userId)
                ->where('status', Recurrence::STATUS_ATIVO)
                ->with('card')
                ->lockForUpdate()
                ->findOrFail($recorrenciaId);

            // Cartão liquida sozinho pela data de cobrança (D3): materializar para "pagar" aqui
            // quitaria a mesma conta duas vezes — uma na linha, outra na fatura (§4.3).
            if ($molde->card_id !== null) {
                throw PagamentoNaoPermitidoException::ehCartao();
            }

            // Antes do começo da regra não há previsão: `proxima_em` é o primeiro mês ainda não
            // gerado — o mesmo corte que a projeção aplica.
            if ($molde->proxima_em === null || $molde->proxima_em->format('Y-m') > $competencia) {
                throw new PagamentoNaoPermitidoException('Esta conta fixa ainda não vale para o mês escolhido.');
            }

            // Fora de cartão a competência É o mês de origem ({@see CalcularOcorrencia}).
            $existente = RecurrenceOccurrence::query()
                ->where('recurrence_id', $molde->id)
                ->where('competencia', $competencia)
                ->first();

            // Idempotência: já materializada (pelo agendador ou por um clique anterior) devolve
            // a linha como está — pagar/reabrir é decisão do serviço de pagamento, não daqui.
            return $existente
                ?? $this->gerar->paraMes($molde, $competencia, $hoje)
                ?? RecurrenceOccurrence::query()
                    ->where('recurrence_id', $molde->id)
                    ->where('competencia', $competencia)
                    ->firstOrFail();
        });
    }
}
