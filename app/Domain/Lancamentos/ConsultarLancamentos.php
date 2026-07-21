<?php

declare(strict_types=1);

namespace App\Domain\Lancamentos;

use App\Domain\Gastos\ConsultarGastos;
use App\Domain\Recorrencia\ConsultarOcorrencias;
use App\Domain\Recorrencia\ProjetarRecorrencias;
use App\Domain\Shared\SqlLike;
use App\Models\Installment;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de consulta de extrato `consultar_lancamentos` (FE §7.6) — varredura
 * determinística que lista os lançamentos do usuário num período, uma linha por PARCELA
 * vencendo no mês (mesma base do dashboard/{@see ConsultarGastos}: cada
 * gasto pertence a um único mês de VENCIMENTO), agrupados por dia e com o total já somado.
 *
 * Status de EXIBIÇÃO (pago | a_vencer | atraso | cancelado) é derivado por DATA, não pelo
 * rótulo cru — espelha a partição do dashboard (spec 06b): venceu e ainda em aberto → atraso.
 * "Hoje" é INJETADO (nunca o relógio global) — determinismo/testabilidade (regra 4/5).
 *
 * Filtros opcionais: busca (descrição, ilike parcial), categoria (id), forma (tipo), cartão
 * (id) e status (o bucket de exibição). Sem filtro de status, exclui o que não é lançamento
 * "vivo" do extrato: pendente de revisão (§4.4) e cancelado/estornado — igual ConsultarGastos.
 * Com filtro, mostra exatamente o bucket pedido. Escopo ESTRITO por usuário.
 */
final class ConsultarLancamentos
{
    /** Buckets de status de exibição (casam com o seletor da tela e o selo). */
    public const STATUS_PAGO = 'pago';

    public const STATUS_A_VENCER = 'a_vencer';

    public const STATUS_ATRASO = 'atraso';

    public const STATUS_CANCELADO = 'cancelado';

    /** Bucket de exibição de uma ocorrência de recorrência ainda não paga. */
    public const STATUS_PREVISTO = 'previsto';

    public function __construct(
        private readonly ProjetarRecorrencias $projetarRecorrencias = new ProjetarRecorrencias,
        private readonly ConsultarOcorrencias $ocorrencias = new ConsultarOcorrencias,
    ) {}

    public function para(
        int $userId,
        string $periodo,
        CarbonImmutable $hoje,
        ?string $busca = null,
        ?int $categoriaId = null,
        ?string $forma = null,
        ?int $cartaoId = null,
        ?string $status = null,
    ): ResultadoConsultaLancamentos {
        [$inicio, $fim] = $this->periodo($periodo);
        $hojeData = $hoje->setTimezone('America/Sao_Paulo')->startOfDay();

        $formaId = $forma !== null ? PaymentMethod::idFor($forma) : null;
        $pendenteId = StatusPagamento::idFor(StatusPagamento::PENDENTE_REVISAO);
        $busca = $busca !== null && trim($busca) !== '' ? trim($busca) : null;

        $parcelas = Installment::query()
            ->whereBetween('vencimento', [$inicio, $fim])
            // Pendente de revisão nunca entra no extrato (ainda não confirmado — regra 7).
            ->when($pendenteId !== null, fn (Builder $q) => $q->where('status_id', '!=', $pendenteId))
            ->whereHas('transaction', function (Builder $q) use ($userId, $categoriaId, $formaId, $cartaoId, $busca) {
                $q->where('user_id', $userId);

                if ($categoriaId !== null) {
                    $q->where('categoria_id', $categoriaId);
                }

                if ($formaId !== null) {
                    $q->where('payment_method_id', $formaId);
                }

                if ($cartaoId !== null) {
                    $q->where('card_id', $cartaoId);
                }

                if ($busca !== null) {
                    // Escapa curingas do texto do usuário (auditoria P3-3); os "%"
                    // externos (busca parcial) são intencionais.
                    $q->where('descricao', 'ilike', '%'.SqlLike::escapar($busca).'%');
                }
            })
            ->with(['transaction.categoria', 'transaction.card', 'transaction.paymentMethod', 'status'])
            ->orderBy('vencimento', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $total = 0;
        $registros = 0;
        $grupos = [];

        foreach ($parcelas as $parcela) {
            $statusExibicao = $this->statusExibicao((string) $parcela->status?->codigo, $parcela->vencimento, $hojeData);

            // Sem filtro: exclui cancelados/estornados (não são lançamento vivo do extrato).
            // Com filtro: mantém apenas o bucket pedido.
            if ($status !== null) {
                if ($statusExibicao !== $status) {
                    continue;
                }
            } elseif ($statusExibicao === self::STATUS_CANCELADO) {
                continue;
            }

            $tx = $parcela->transaction;
            $cents = $parcela->valor()->cents();
            $total += $cents;
            $registros++;

            $dia = $parcela->vencimento->toDateString();
            $grupos[$dia] ??= ['data' => $parcela->vencimento->startOfDay(), 'itens' => []];
            $grupos[$dia]['itens'][] = [
                'transactionId' => (int) $tx->id,
                'descricao' => (string) $tx->descricao,
                'cents' => $cents,
                'categoria' => $tx->categoria !== null
                    ? ['nome' => (string) $tx->categoria->nome, 'cor' => $tx->categoria->cor]
                    : null,
                'forma' => $tx->paymentMethod?->tipo,
                'cartaoDescricao' => $tx->card?->descricao,
                'parcela' => $parcela->total > 1 ? "{$parcela->numero}/{$parcela->total}" : null,
                'status' => $statusExibicao,
                'vencimento' => $parcela->vencimento,
                // Recorrência não vive mais em `transactions` (spec 12): toda linha real aqui
                // é um lançamento comum. As recorrentes vêm das duas fontes abaixo.
                'recorrente' => false,
                'prevista' => false,
                'ocorrenciaId' => null,
            ];
        }

        // Recorrências PREVISTAS: competências AINDA NÃO GERADAS (spec 12) — tipicamente meses
        // futuros. A separação é o `NOT EXISTS` sobre `recurrence_occurrences`, então uma
        // competência já materializada nunca aparece aqui e ali. Não grava nada (regra 7).
        $previsao = $this->projetarRecorrencias->para($userId, $periodo, $hoje);
        foreach ($previsao->ocorrencias as $ocorrencia) {
            if (! $this->previstaCasaFiltros($ocorrencia, $status, $cartaoId, $categoriaId, $forma, $busca)) {
                continue;
            }

            $venc = CarbonImmutable::createFromFormat('!Y-m-d', (string) $ocorrencia['vencimento'], 'America/Sao_Paulo');
            $cents = (int) $ocorrencia['cents'];
            $total += $cents;
            $registros++;

            $dia = $venc->toDateString();
            $grupos[$dia] ??= ['data' => $venc->startOfDay(), 'itens' => []];
            $grupos[$dia]['itens'][] = [
                // Previsão não tem lançamento real: sem id (a linha não abre detalhe).
                'transactionId' => null,
                'descricao' => (string) $ocorrencia['descricao'],
                'cents' => $cents,
                'categoria' => $ocorrencia['categoria'] ?? null,
                'forma' => $ocorrencia['forma'] ?? null,
                'cartaoDescricao' => $ocorrencia['cartaoDescricao'] ?? null,
                'parcela' => null,
                'status' => self::STATUS_A_VENCER,
                'vencimento' => $venc,
                'recorrente' => true,
                'prevista' => true,
                'ocorrenciaId' => null,
            ];
        }

        // Ocorrências REAIS da competência (spec 12): a conta fixa já materializada. Status de
        // exibição por data (pago/previsto/atraso) e o id OPACO da ocorrência para o botão
        // "marcar como paga" — que só existe fora de cartão (`pagavel`, D3). Sem lançamento
        // real por trás: recorrência não escreve em `transactions` (transactionId null).
        foreach ($this->ocorrencias->paraMes($userId, $periodo, $hoje) as $ocorrencia) {
            if (! $this->ocorrenciaCasaFiltros($ocorrencia, $status, $cartaoId, $categoriaId, $forma, $busca)) {
                continue;
            }

            $venc = CarbonImmutable::createFromFormat('!Y-m-d', (string) $ocorrencia['vencimento'], 'America/Sao_Paulo');
            $cents = (int) $ocorrencia['cents'];
            $total += $cents;
            $registros++;

            $dia = $venc->toDateString();
            $grupos[$dia] ??= ['data' => $venc->startOfDay(), 'itens' => []];
            $grupos[$dia]['itens'][] = [
                'transactionId' => null,
                'descricao' => (string) $ocorrencia['descricao'],
                'cents' => $cents,
                'categoria' => $ocorrencia['categoria'] ?? null,
                'forma' => $ocorrencia['forma'] ?? null,
                'cartaoDescricao' => $ocorrencia['cartaoDescricao'] ?? null,
                'parcela' => null,
                'status' => (string) $ocorrencia['status'], // pago | previsto | atraso
                'vencimento' => $venc,
                'recorrente' => true,
                // A ocorrência É real (existe no banco) — o selo "Previsto" fica só na projeção.
                'prevista' => false,
                // Alvo do "marcar como paga"; null quando é de cartão (liquida sozinha, D3).
                'ocorrenciaId' => $ocorrencia['pagavel'] === true ? $ocorrencia['ocorrenciaId'] : null,
            ];
        }

        // Ordena os grupos por dia (desc): as previstas entram no dia certo entre as reais.
        uasort($grupos, static fn (array $a, array $b): int => $b['data']->toDateString() <=> $a['data']->toDateString());

        return new ResultadoConsultaLancamentos(
            totalExibidoCents: $total,
            grupos: array_values($grupos),
            registros: $registros,
        );
    }

    /**
     * Aplica os filtros ativos do extrato a uma ocorrência PREVISTA (competência ainda não
     * gerada). Previsão é sempre "a vencer": um filtro de status diferente disso a descarta.
     * Cartão/categoria/forma/busca casam pelos próprios campos da recorrência — recorrência em
     * cartão passou a existir (spec 12, D3), então o filtro de cartão compara o id, não elimina.
     *
     * @param  array<string, mixed>  $ocorrencia
     */
    private function previstaCasaFiltros(
        array $ocorrencia,
        ?string $status,
        ?int $cartaoId,
        ?int $categoriaId,
        ?string $forma,
        ?string $busca,
    ): bool {
        if ($status !== null && $status !== self::STATUS_A_VENCER) {
            return false;
        }

        if ($cartaoId !== null && ($ocorrencia['cartaoId'] ?? null) !== $cartaoId) {
            return false;
        }

        return $this->camposCasam($ocorrencia, $categoriaId, $forma, $busca);
    }

    /**
     * Aplica os filtros ativos a uma ocorrência REAL de recorrência (spec 12). O filtro de
     * status casa pelo bucket de exibição (previsto→a_vencer, atraso→atraso, pago→pago);
     * cartão/categoria/forma/busca pelos próprios campos do snapshot da ocorrência.
     *
     * @param  array<string, mixed>  $ocorrencia
     */
    private function ocorrenciaCasaFiltros(
        array $ocorrencia,
        ?string $status,
        ?int $cartaoId,
        ?int $categoriaId,
        ?string $forma,
        ?string $busca,
    ): bool {
        if ($cartaoId !== null && ($ocorrencia['cartaoId'] ?? null) !== $cartaoId) {
            return false;
        }

        if ($status !== null) {
            $bucket = match ($ocorrencia['status']) {
                self::STATUS_PAGO => self::STATUS_PAGO,
                self::STATUS_ATRASO => self::STATUS_ATRASO,
                default => self::STATUS_A_VENCER,
            };

            if ($status !== $bucket && $status !== $ocorrencia['status']) {
                return false;
            }
        }

        return $this->camposCasam($ocorrencia, $categoriaId, $forma, $busca);
    }

    /**
     * Filtros comuns às duas fontes de recorrência (busca parcial e case-insensitive,
     * espelhando o `ilike` das linhas reais).
     *
     * @param  array<string, mixed>  $ocorrencia
     */
    private function camposCasam(array $ocorrencia, ?int $categoriaId, ?string $forma, ?string $busca): bool
    {
        if ($categoriaId !== null && ($ocorrencia['categoriaId'] ?? null) !== $categoriaId) {
            return false;
        }

        if ($forma !== null && ($ocorrencia['forma'] ?? null) !== $forma) {
            return false;
        }

        if ($busca !== null && stripos((string) $ocorrencia['descricao'], $busca) === false) {
            return false;
        }

        return true;
    }

    /**
     * Limites [início, fim] do mês YYYY-MM como strings de data (America/Sao_Paulo).
     *
     * @return array{0: string, 1: string}
     */
    private function periodo(string $periodo): array
    {
        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01', 'America/Sao_Paulo')->startOfMonth();

        return [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()];
    }

    /**
     * Deriva o status de exibição a partir do código cru + data (spec 06b):
     * pago/pago_parcial → pago; cancelado/estornado → cancelado; caso contrário,
     * venceu antes de hoje → atraso, senão → a_vencer.
     */
    private function statusExibicao(string $codigo, CarbonImmutable $vencimento, CarbonImmutable $hojeData): string
    {
        return match ($codigo) {
            StatusPagamento::PAGO, StatusPagamento::PAGO_PARCIAL => self::STATUS_PAGO,
            StatusPagamento::CANCELADO, StatusPagamento::ESTORNADO => self::STATUS_CANCELADO,
            default => $vencimento->startOfDay()->lessThan($hojeData) ? self::STATUS_ATRASO : self::STATUS_A_VENCER,
        };
    }
}
