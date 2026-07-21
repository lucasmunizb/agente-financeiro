<?php

declare(strict_types=1);

namespace App\Domain\ProximasContas;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Shared\OpaqueId;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de consulta `consultar_proximas_contas` (doc 02 §3.2) — varredura
 * determinística do banco que itemiza e soma as contas A VENCER do usuário dentro de
 * uma janela rolante a partir de "hoje".
 *
 * Janela = parcelas com `vencimento` entre hoje e hoje+`janelaDias` (inclusive). Só
 * para frente: o que já venceu não é "próxima conta". Exclui o que não é mais conta a
 * pagar: liquidado (`pago`) e os status que não entram no cálculo (§4.4:
 * `pendente_revisao`, `cancelado`, `estornado`). Sobram aberto/agendado/pago_parcial.
 *
 * "Hoje" é INJETADO (nunca lido do relógio global) — determinismo e testabilidade
 * (regra 4/5); a IA jamais resolve datas por conta própria. Escopo ESTRITO por usuário.
 */
final class ConsultarProximasContas
{
    /** @var list<string> Status que NÃO são conta a pagar: liquidado + os excluídos comuns (§4.4). */
    private const STATUS_PROPRIOS_EXCLUIDOS = [StatusPagamento::PAGO];

    public function para(int $userId, CarbonImmutable $hoje, int $janelaDias): ResultadoConsultaProximasContas
    {
        $de = $hoje->setTimezone('America/Sao_Paulo')->startOfDay();
        $ate = $de->addDays($janelaDias);

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', [...self::STATUS_PROPRIOS_EXCLUIDOS, ...StatusPagamento::EXCLUIDOS])
            ->pluck('id')
            ->all();

        $parcelas = Installment::query()
            ->whereBetween('vencimento', [$de->toDateString(), $ate->toDateString()])
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn (Builder $q) => $q->where('user_id', $userId))
            // `transaction.card` alimenta o agrupamento por fatura no quadro do dashboard
            // ({@see \App\Domain\Dashboard\AgruparContasDeCartao}) — sem N+1.
            ->with('transaction.card')
            ->orderBy('vencimento')
            ->get();

        $total = 0;
        $contas = [];

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $total += $cents;

            // Alvos das ações da linha (decisão do usuário 2026-07-21): o quadro deixou de
            // ser só leitura — dá para marcar pago e editar sem sair do dashboard. Ids sempre
            // OPACOS. Cartão fica sem alvo de pagamento: a fatura é quem quita (§4.3).
            $ehCartao = $parcela->transaction?->card_id !== null;

            $contas[] = [
                'descricao' => $parcela->transaction->descricao ?? 'Conta',
                'vencimento' => $parcela->vencimento->toDateString(),
                'cents' => $cents,
                // Recorrência não vive mais em `transactions` (spec 12): toda parcela aqui é
                // lançamento comum. As contas fixas entram pelas ocorrências, no ResumoDoMes.
                'recorrente' => false,
                // Em cartão o `vencimento` acima JÁ é o da fatura: quem consome pode somar as
                // cobranças do mesmo cartão numa linha só (o usuário paga a fatura, não a compra).
                'cartaoId' => $parcela->transaction?->card_id,
                'cartaoDescricao' => $parcela->transaction?->card?->descricao,
                'parcelaId' => $ehCartao ? null : OpaqueId::encode((int) $parcela->id),
                'transactionId' => OpaqueId::encode((int) $parcela->transaction_id),
                'pagavel' => ! $ehCartao,
            ];
        }

        $trace = new TraceDaConsulta(
            ferramenta: 'consultar_proximas_contas',
            filtros: [
                'janela_dias' => $janelaDias,
                'de' => $de->toDateString(),
                'ate' => $ate->toDateString(),
            ],
            registros: $parcelas->count(),
        );

        return new ResultadoConsultaProximasContas(
            totalCents: $total,
            contas: $contas,
            trace: $trace,
        );
    }
}
