<?php

declare(strict_types=1);

namespace App\Domain\Gastos;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Orcamento\ConsumoMensal;
use App\Domain\Shared\PeriodoMensal;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\StatusPagamento;
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

    public function para(
        int $userId,
        string $periodo,
        ?string $categoria = null,
        ?string $cartao = null,
        ?string $status = null,
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
            ->with('transaction')
            ->get();

        $total = 0;
        $porCategoriaId = [];

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $idCategoria = $parcela->transaction->categoria_id ?? ConsumoMensal::SEM_CATEGORIA;

            $porCategoriaId[$idCategoria] = ($porCategoriaId[$idCategoria] ?? 0) + $cents;
            $total += $cents;
        }

        $trace = new TraceDaConsulta(
            ferramenta: 'consultar_gastos',
            filtros: [
                'periodo' => $periodo,
                'categoria' => $categoria,
                'cartao' => $cartao,
                'status' => $status,
            ],
            registros: $parcelas->count(),
        );

        return new ResultadoConsultaGastos(
            totalCents: $total,
            porCategoria: $this->comNomes($userId, $porCategoriaId),
            trace: $trace,
        );
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
