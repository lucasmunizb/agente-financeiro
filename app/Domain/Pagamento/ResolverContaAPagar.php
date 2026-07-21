<?php

declare(strict_types=1);

namespace App\Domain\Pagamento;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Shared\SqlLike;
use App\Models\Installment;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Descobre QUAL conta o usuário quis dizer ao falar "paguei a luz" (bot) — a peça
 * determinística do fluxo. A IA extrai apenas o termo ("luz"); a identidade da conta, o valor
 * e o vencimento saem daqui, do banco (regra 4: a IA nunca calcula nem escolhe dinheiro).
 *
 * Varre as DUAS fontes de conta a pagar, que não compartilham tabela: parcela de lançamento e
 * ocorrência de recorrência (spec 12). Em ambas, só o que é FORA DE CARTÃO — cobrança em
 * cartão é quitada pela fatura (§4.3 / D3), e marcá-la aqui pagaria a mesma conta duas vezes —
 * e só o que ainda não foi pago/cancelado.
 *
 * Devolver MAIS DE UM candidato é resposta legítima: quem desempata é o usuário, na conversa.
 * A ordem é por vencimento ascendente — a conta mais atrasada é a que se costuma estar
 * quitando. Escopo ESTRITO por usuário; "hoje" é injetado (regras 4 e 5).
 */
final class ResolverContaAPagar
{
    /** Teto de candidatos oferecidos: uma lista maior que isso não se escolhe por chat. */
    private const MAX_CANDIDATOS = 5;

    /**
     * Janela de busca em torno de hoje. Sem ela, "internet" casaria com a parcela de
     * dezembro de um parcelamento longo — que não é "a que paguei hoje". Larga o bastante
     * para cobrir a conta esquecida de meses atrás e a que vence semana que vem.
     */
    private const DIAS_PARA_TRAS = 180;

    private const DIAS_PARA_FRENTE = 45;

    /**
     * @return list<ContaPagavel> ordenados por vencimento asc; vazio quando nada casa
     */
    public function para(int $userId, string $termo, CarbonImmutable $hoje): array
    {
        $termo = trim($termo);

        // Termo vazio não é "traga tudo": sem o que buscar, não há candidato.
        if ($termo === '') {
            return [];
        }

        $hojeData = $hoje->setTimezone(RelativeDate::TIMEZONE)->startOfDay();
        $de = $hojeData->subDays(self::DIAS_PARA_TRAS)->toDateString();
        $ate = $hojeData->addDays(self::DIAS_PARA_FRENTE)->toDateString();

        // Curinga digitado pelo usuário é TEXTO (auditoria P3-3): só os "%" externos são
        // nossos. Sem isso, um "%" solto listaria todas as contas do usuário.
        $like = '%'.SqlLike::escapar($termo).'%';

        $contas = [...$this->parcelas($userId, $like, $de, $ate), ...$this->ocorrencias($userId, $like, $de, $ate)];

        usort($contas, static fn (ContaPagavel $a, ContaPagavel $b): int => $a->vencimento->toDateString() <=> $b->vencimento->toDateString());

        return array_slice($contas, 0, self::MAX_CANDIDATOS);
    }

    /**
     * Parcelas de lançamento fora de cartão que seguem em aberto. `pago` entra nos excluídos
     * junto de cancelado/estornado/pendente — nenhum deles é conta a pagar (§4.4).
     *
     * @return list<ContaPagavel>
     */
    private function parcelas(int $userId, string $like, string $de, string $ate): array
    {
        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', [StatusPagamento::PAGO, ...StatusPagamento::EXCLUIDOS])
            ->pluck('id')
            ->all();

        return Installment::query()
            ->whereBetween('vencimento', [$de, $ate])
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn (Builder $q) => $q
                ->where('user_id', $userId)
                ->whereNull('card_id')
                ->where('descricao', 'ilike', $like))
            ->with('transaction')
            ->orderBy('vencimento')
            ->get()
            ->map(fn (Installment $p): ContaPagavel => new ContaPagavel(
                tipo: ContaPagavel::TIPO_PARCELA,
                id: (int) $p->id,
                descricao: (string) $p->transaction->descricao,
                cents: $p->valor()->cents(),
                vencimento: $p->vencimento->startOfDay(),
            ))
            ->all();
    }

    /**
     * Ocorrências de recorrência fora de cartão ainda não pagas (spec 12). Cartão sai por
     * `card_id`: ele liquida sozinho na data de cobrança (D3).
     *
     * @return list<ContaPagavel>
     */
    private function ocorrencias(int $userId, string $like, string $de, string $ate): array
    {
        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', [StatusPagamento::PAGO, ...StatusPagamento::EXCLUIDOS])
            ->pluck('id')
            ->all();

        return RecurrenceOccurrence::query()
            ->where('user_id', $userId)
            ->whereNull('card_id')
            ->whereBetween('vencimento', [$de, $ate])
            ->whereNotIn('status_id', $excluidos)
            ->where('descricao', 'ilike', $like)
            ->orderBy('vencimento')
            ->get()
            ->map(fn (RecurrenceOccurrence $oc): ContaPagavel => new ContaPagavel(
                tipo: ContaPagavel::TIPO_OCORRENCIA,
                id: (int) $oc->id,
                descricao: (string) $oc->descricao,
                cents: (int) $oc->valor_cents,
                vencimento: $oc->vencimento->startOfDay(),
            ))
            ->all();
    }
}
