<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Leitura das ocorrências REAIS de recorrência (spec 12) — substitui a antiga ponte
 * "fila ↔ tela" (`ProjetarRecorrenciasPendentes`). Agora a ocorrência já existe no banco: aqui
 * só se lê, normaliza e deriva o status de EXIBIÇÃO por data.
 *
 * Recorte por COMPETÊNCIA ({@see self::paraMes()}) para extrato/donut/disponível — é a
 * competência, não o vencimento cru, que define em que mês a conta pesa (§4.5), e no cartão as
 * duas divergem. Recorte por JANELA DE DATAS ({@see self::naJanela()}) para os quadros do
 * dashboard, que cruzam a fronteira do mês ("a vencer" = [hoje, hoje+N], "em atraso" = tudo
 * até ontem).
 *
 * Read-only, escopo ESTRITO por usuário, "agora" injetado (regras 4 e 5). Cancelada não é
 * cobrança (§4.4) e fica de fora. O id sai sempre OPACO — nenhum id real em URL.
 */
final class ConsultarOcorrencias
{
    /**
     * Ocorrências da COMPETÊNCIA pedida (YYYY-MM).
     *
     * @return list<array<string, mixed>> ordenadas por vencimento asc
     */
    public function paraMes(int $userId, string $mes, CarbonImmutable $agora): array
    {
        return $this->normalizar(
            $this->base($userId)->where('competencia', $mes)->get(),
            $agora,
        );
    }

    /**
     * Soma (centavos) das ocorrências da competência — o que a conta fixa pesa no mês. Não
     * precisa de "agora" porque não deriva status de exibição: é agregação pura, para o
     * disponível/consumo. Canceladas ficam de fora (§4.4); pagas entram (foram cobradas).
     */
    public function totalDoMes(int $userId, string $mes): int
    {
        return (int) RecurrenceOccurrence::query()
            ->where('user_id', $userId)
            ->where('competencia', $mes)
            ->where('status_id', '!=', StatusPagamento::idFor(StatusPagamento::CANCELADO))
            ->sum('valor_cents');
    }

    /**
     * Ocorrências de UM cartão numa competência — os itens que a assinatura recorrente coloca
     * na fatura (R8). A fatura é EXTRATO: a ocorrência entra mesmo já estando `pago` (o cartão
     * a liquidou sozinho, D3). Só canceladas ficam de fora. Sem status de exibição, logo sem
     * relógio (regra 4).
     *
     * @return Collection<int, RecurrenceOccurrence> ordenadas por vencimento asc
     */
    public function doCartao(int $userId, int $cardId, string $competencia)
    {
        return RecurrenceOccurrence::query()
            ->where('user_id', $userId)
            ->where('card_id', $cardId)
            ->where('competencia', $competencia)
            ->where('status_id', '!=', StatusPagamento::idFor(StatusPagamento::CANCELADO))
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get();
    }

    /**
     * Ocorrências VENCENDO dentro de uma janela de datas (inclusive nas duas pontas).
     *
     * @param  CarbonImmutable|null  $de  limite inferior; null ⇒ sem limite (tudo que já venceu)
     * @return list<array<string, mixed>> ordenadas por vencimento asc
     */
    public function naJanela(int $userId, ?CarbonImmutable $de, CarbonImmutable $ate, CarbonImmutable $agora): array
    {
        $deData = $de?->setTimezone(RelativeDate::TIMEZONE)->toDateString();
        $ateData = $ate->setTimezone(RelativeDate::TIMEZONE)->toDateString();

        return $this->normalizar(
            $this->base($userId)
                ->when($deData !== null, fn (Builder $q) => $q->where('vencimento', '>=', $deData))
                ->where('vencimento', '<=', $ateData)
                ->get(),
            $agora,
        );
    }

    /** Query-base: escopo por usuário, sem canceladas, com o que a linha precisa exibir. */
    private function base(int $userId): Builder
    {
        return RecurrenceOccurrence::query()
            ->where('user_id', $userId)
            ->where('status_id', '!=', StatusPagamento::idFor(StatusPagamento::CANCELADO))
            ->with(['categoria', 'paymentMethod', 'card'])
            ->orderBy('vencimento')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, RecurrenceOccurrence>  $ocorrencias
     * @return list<array<string, mixed>>
     */
    private function normalizar($ocorrencias, CarbonImmutable $agora): array
    {
        $hojeData = $agora->setTimezone(RelativeDate::TIMEZONE)->startOfDay();
        $pago = StatusPagamento::idFor(StatusPagamento::PAGO);

        return $ocorrencias->map(function (RecurrenceOccurrence $oc) use ($hojeData, $pago): array {
            $ehPago = $oc->status_id === $pago;

            return [
                // Id OPACO (nunca o id real na URL) — alvo do "marcar como paga".
                'ocorrenciaId' => $oc->getRouteKey(),
                'descricao' => (string) $oc->descricao,
                'vencimento' => $oc->vencimento->toDateString(),
                'dataCobranca' => $oc->data_cobranca->toDateString(),
                'cents' => (int) $oc->valor_cents,
                'competencia' => (string) $oc->competencia,
                'categoriaId' => $oc->categoria_id,
                'categoria' => $oc->categoria !== null
                    ? ['nome' => (string) $oc->categoria->nome, 'cor' => $oc->categoria->cor]
                    : null,
                'forma' => $oc->paymentMethod?->tipo,
                'cartaoId' => $oc->card_id,
                'cartaoDescricao' => $oc->card?->descricao,
                // Status de exibição por DATA (espelha o extrato): pago; senão venceu ⇒ atraso.
                'status' => match (true) {
                    $ehPago => 'pago',
                    $oc->vencimento->startOfDay()->lessThan($hojeData) => 'atraso',
                    default => 'previsto',
                },
                // Cartão liquida sozinho (D3): o botão "marcar paga" só existe fora dele.
                'pagavel' => ! $ehPago && ! $oc->ehCartao(),
                'recorrente' => true,
            ];
        })->values()->all();
    }
}
