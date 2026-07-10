<?php

declare(strict_types=1);

namespace App\Domain\Gastos;

use App\Domain\Calendar\RelativeDate;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Orcamento\ConsumoMensal;
use App\Domain\Recorrencia\ProjetarRecorrencias;
use App\Domain\Recorrencia\ProjetarRecorrenciasPendentes;
use App\Domain\Shared\PeriodoMensal;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de consulta `consultar_gastos` (doc 02 §3.2) — varredura determinística do
 * banco que soma os gastos do usuário num período, com filtros opcionais.
 *
 * Base = parcelas vencendo no período (mesma base do "disponível"/{@see ConsumoMensal}:
 * cada gasto pertence a um único mês de VENCIMENTO). Filtros: categoria (nome), cartão
 * (descrição ou 4 dígitos) e status. Sem filtro de status, exclui os status que não
 * entram no cálculo (§4.4); COM filtro, mostra exatamente o status pedido — o usuário
 * pediu. Escopo ESTRITO por usuário. A IA não participa — só redige sobre estes números.
 */
final class ConsultarGastos
{
    /** @var list<string> Status que não entram no cálculo por padrão (§4.4), espelha {@see ConsumoDoMes}. */
    private const STATUS_EXCLUIDOS = [
        StatusPagamento::PENDENTE_REVISAO,
        StatusPagamento::CANCELADO,
        StatusPagamento::ESTORNADO,
    ];

    public function __construct(
        private readonly ProjetarRecorrencias $projetarRecorrencias = new ProjetarRecorrencias,
        private readonly ProjetarRecorrenciasPendentes $projetarPendentes = new ProjetarRecorrenciasPendentes,
    ) {}

    /**
     * @param  CarbonImmutable|null  $agora  "hoje" real (injetado); omitido ⇒ relógio SP.
     *                                       Só habilita a projeção de recorrências em mês futuro.
     */
    public function para(
        int $userId,
        string $periodo,
        ?string $categoria = null,
        ?string $cartao = null,
        ?string $status = null,
        bool $detalhar = false,
        ?CarbonImmutable $agora = null,
    ): ResultadoConsultaGastos {
        $p = PeriodoMensal::fromString($periodo);

        $categoriaId = $categoria !== null ? $this->resolverCategoria($userId, $categoria) : null;
        $cardId = $cartao !== null ? $this->resolverCartao($userId, $cartao) : null;

        $parcelas = Installment::query()
            ->whereBetween('vencimento', [$p->inicio->toDateString(), $p->fim->toDateString()])
            ->whereHas('transaction', function (Builder $q) use ($userId, $categoriaId, $cardId) {
                $q->where('user_id', $userId);

                if ($categoriaId !== null) {
                    $q->where('categoria_id', $categoriaId);
                }

                if ($cardId !== null) {
                    $q->where('card_id', $cardId);
                }
            })
            ->tap(fn (Builder $q) => $this->aplicarStatus($q, $status))
            ->orderBy('vencimento')
            ->orderBy('id')
            ->with('transaction')
            ->get();

        $total = 0;
        $porCategoriaId = [];
        $itens = [];

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $idCategoria = $parcela->transaction->categoria_id ?? ConsumoMensal::SEM_CATEGORIA;

            $porCategoriaId[$idCategoria] = ($porCategoriaId[$idCategoria] ?? 0) + $cents;
            $total += $cents;

            if ($detalhar) {
                $itens[] = [
                    'descricao' => (string) $parcela->transaction->descricao,
                    'cents' => $cents,
                    'vencimento' => $parcela->vencimento,
                    'parcela' => $parcela->total > 1 ? "{$parcela->numero}/{$parcela->total}" : null,
                ];
            }
        }

        // Ocorrências de recorrência ainda NÃO materializadas — a soma por categoria (donut e bot)
        // tem de bater com o extrato, que já as lista. Duas fontes, sem dupla-contagem:
        //  - mês FUTURO: molde ({@see ProjetarRecorrencias}) — o guard mantém o passado/corrente vazio;
        //  - mês corrente/atrasado: a FILA ({@see ProjetarRecorrenciasPendentes}) — pendente ainda
        //    não confirmado, some quando vira lançamento real. Read-only.
        $agoraResolvido = $agora ?? CarbonImmutable::now(RelativeDate::TIMEZONE);
        $ocorrenciasPrevistas = [
            ...$this->projetarRecorrencias->para($userId, $periodo, $agoraResolvido)->ocorrencias,
            ...$this->projetarPendentes->para($userId, $periodo, $agoraResolvido),
        ];
        $registrosPrevistos = 0;

        foreach ($ocorrenciasPrevistas as $ocorrencia) {
            if (! $this->previstaCasaFiltros($ocorrencia, $categoriaId, $cardId, $status)) {
                continue;
            }

            $cents = (int) $ocorrencia['cents'];
            $idCategoria = $ocorrencia['categoriaId'] ?? ConsumoMensal::SEM_CATEGORIA;

            $porCategoriaId[$idCategoria] = ($porCategoriaId[$idCategoria] ?? 0) + $cents;
            $total += $cents;
            $registrosPrevistos++;

            if ($detalhar) {
                $itens[] = [
                    'descricao' => (string) $ocorrencia['descricao'],
                    'cents' => $cents,
                    'vencimento' => CarbonImmutable::createFromFormat('!Y-m-d', (string) $ocorrencia['vencimento'], RelativeDate::TIMEZONE),
                    'parcela' => null,
                ];
            }
        }

        // Reordena os itens por vencimento asc só quando previstas entraram (as reais já vêm
        // ordenadas): mantém a lista coerente e não perturba as consultas sem recorrência.
        if ($detalhar && $registrosPrevistos > 0) {
            usort($itens, static fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);
        }

        $trace = new TraceDaConsulta(
            ferramenta: 'consultar_gastos',
            filtros: [
                'periodo' => $periodo,
                'categoria' => $categoria,
                'cartao' => $cartao,
                'status' => $status,
            ],
            registros: $parcelas->count() + $registrosPrevistos,
        );

        return new ResultadoConsultaGastos(
            totalCents: $total,
            porCategoria: $this->comNomes($userId, $porCategoriaId),
            trace: $trace,
            itens: $itens,
        );
    }

    /**
     * Aplica os filtros ativos à ocorrência PREVISTA (espelha o extrato/{@see ConsultarLancamentos}):
     * recorrência é sempre fora de cartão e "a vencer" — filtro de cartão a descarta; filtro de
     * status só a mantém no bucket em aberto; categoria casa pelo próprio id da recorrência.
     *
     * @param  array<string, mixed>  $ocorrencia
     */
    private function previstaCasaFiltros(array $ocorrencia, ?int $categoriaId, ?int $cardId, ?string $status): bool
    {
        if ($cardId !== null) {
            return false;
        }

        if ($status !== null && $status !== StatusPagamento::ABERTO) {
            return false;
        }

        if ($categoriaId !== null && ($ocorrencia['categoriaId'] ?? null) !== $categoriaId) {
            return false;
        }

        return true;
    }

    /**
     * Restringe pelo status: com filtro, exatamente o pedido; sem filtro, exclui os
     * status que não entram no cálculo (§4.4).
     */
    private function aplicarStatus(Builder $query, ?string $status): void
    {
        if ($status !== null) {
            // Status desconhecido resolve para null e a query não retorna nada (filtro vazio).
            $query->where('status_id', StatusPagamento::idFor($status));

            return;
        }

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', self::STATUS_EXCLUIDOS)
            ->pluck('id')
            ->all();

        $query->whereNotIn('status_id', $excluidos);
    }

    /**
     * Resolve o nome da categoria para o seu id (escopo por usuário, case-insensitive).
     * Não encontrada → -1 (nenhum gasto casa; consulta vazia, sem vazar de outro usuário).
     */
    private function resolverCategoria(int $userId, string $categoria): int
    {
        return (int) (Category::query()
            ->where('user_id', $userId)
            ->where('nome', 'ilike', $categoria)
            ->value('id') ?? -1);
    }

    /**
     * Resolve o cartão pela descrição (case-insensitive) OU pelos 4 dígitos finais
     * (escopo por usuário). Não encontrado → -1 (consulta vazia).
     */
    private function resolverCartao(int $userId, string $cartao): int
    {
        return (int) (Card::query()
            ->where('user_id', $userId)
            ->where(fn (Builder $q) => $q
                ->where('descricao', 'ilike', $cartao)
                ->orWhere('final_4', $cartao))
            ->value('id') ?? -1);
    }

    /**
     * Converte o agregado categoria_id => centavos numa lista ordenada por valor desc,
     * com os nomes resolvidos. SEM_CATEGORIA vira "Sem categoria".
     *
     * @param  array<int, int>  $porCategoriaId
     * @return list<array{nome: string, cents: int}>
     */
    private function comNomes(int $userId, array $porCategoriaId): array
    {
        $ids = array_filter(array_keys($porCategoriaId), fn (int $id) => $id !== ConsumoMensal::SEM_CATEGORIA);

        $nomes = Category::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->pluck('nome', 'id')
            ->all();

        arsort($porCategoriaId);

        $linhas = [];
        foreach ($porCategoriaId as $id => $cents) {
            $linhas[] = [
                'nome' => $id === ConsumoMensal::SEM_CATEGORIA ? 'Sem categoria' : ($nomes[$id] ?? 'Sem categoria'),
                'cents' => $cents,
            ];
        }

        return $linhas;
    }
}
