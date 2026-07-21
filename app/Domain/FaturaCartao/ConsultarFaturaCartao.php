<?php

declare(strict_types=1);

namespace App\Domain\FaturaCartao;

use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Recorrencia\ConsultarOcorrencias;
use App\Domain\Shared\PeriodoMensal;
use App\Domain\Shared\SqlLike;
use App\Models\Card;
use App\Models\Installment;
use App\Models\StatusPagamento;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de consulta `consultar_fatura_cartao` (doc 02 §3.2) — varredura determinística
 * do banco que itemiza e soma a fatura de UM cartão numa competência.
 *
 * Fatura = as cobranças (parcelas) desse cartão cujo `vencimento` cai na competência
 * (YYYY-MM) — mesma atribuição por mês de vencimento do disponível/§4.5: cada cobrança
 * pertence a um único mês. É um EXTRATO: exclui só o que não é cobrança efetiva (§4.4:
 * `pendente_revisao`, `cancelado`, `estornado`) e MANTÉM `pago` (diferente de
 * "próximas contas", o extrato lista o que foi cobrado, pago ou não).
 *
 * Cartão resolvido por descrição (ilike) OU 4 dígitos finais, SEMPRE no escopo do
 * usuário; inexistente → fatura vazia (nunca vaza dados de outro usuário). A IA não
 * participa — só redige sobre estes números.
 */
final class ConsultarFaturaCartao
{
    public function __construct(
        private readonly ConsultarOcorrencias $ocorrencias = new ConsultarOcorrencias,
    ) {}

    public function para(int $userId, string $cartao, string $competencia): ResultadoConsultaFaturaCartao
    {
        $card = $this->resolverCartao($userId, $cartao);

        // Cartão não encontrado: fatura vazia, sem tocar em dados de ninguém.
        if ($card === null) {
            return new ResultadoConsultaFaturaCartao(
                cartaoDescricao: $cartao,
                cartaoFinal4: null,
                totalCents: 0,
                itens: [],
                trace: new TraceDaConsulta(
                    ferramenta: 'consultar_fatura_cartao',
                    filtros: ['cartao' => $cartao, 'competencia' => $competencia],
                    registros: 0,
                ),
            );
        }

        return $this->montar($userId, $card, $competencia, $cartao);
    }

    /**
     * Fatura de um cartão JÁ resolvido (por id) — usado pela tela §7.13, que seleciona o cartão
     * pelo token opaco (não por descrição/final ambíguos). Mesma itemização do {@see self::para()}.
     */
    public function paraCartao(int $userId, Card $card, string $competencia): ResultadoConsultaFaturaCartao
    {
        return $this->montar($userId, $card, $competencia, $card->descricao);
    }

    private function montar(int $userId, Card $card, string $competencia, string $cartaoLabel): ResultadoConsultaFaturaCartao
    {
        $p = PeriodoMensal::fromString($competencia);

        $excluidos = StatusPagamento::query()
            ->whereIn('codigo', StatusPagamento::EXCLUIDOS)
            ->pluck('id')
            ->all();

        $parcelas = Installment::query()
            ->whereBetween('vencimento', [$p->inicio->toDateString(), $p->fim->toDateString()])
            ->whereNotIn('status_id', $excluidos)
            ->whereHas('transaction', fn (Builder $q) => $q
                ->where('user_id', $userId)
                ->where('card_id', $card->id))
            ->with('transaction')
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get();

        $total = 0;
        $itens = [];

        foreach ($parcelas as $parcela) {
            $cents = $parcela->valor()->cents();
            $total += $cents;

            $itens[] = [
                'descricao' => $parcela->transaction->descricao ?? 'Compra',
                'vencimento' => $parcela->vencimento->toDateString(),
                'cents' => $cents,
                'numero' => $parcela->numero,
                'total' => $parcela->total,
                'categoria_id' => $parcela->transaction->categoria_id,
                'recorrente' => false,
            ];
        }

        // Assinaturas recorrentes no cartão (spec 12, R8): a ocorrência é a cobrança daquela
        // fatura — entra itemizada e soma no total MESMO já estando `pago`, porque a fatura é
        // extrato do que foi cobrado (§4.4), não lista do que falta pagar.
        $recorrentes = $this->ocorrencias->doCartao($userId, (int) $card->id, $competencia);

        foreach ($recorrentes as $ocorrencia) {
            $total += (int) $ocorrencia->valor_cents;

            $itens[] = [
                'descricao' => (string) $ocorrencia->descricao,
                'vencimento' => $ocorrencia->vencimento->toDateString(),
                'cents' => (int) $ocorrencia->valor_cents,
                'numero' => 1,
                'total' => 1,
                'categoria_id' => $ocorrencia->categoria_id,
                'recorrente' => true,
            ];
        }

        if ($recorrentes->isNotEmpty()) {
            // As parcelas já vinham ordenadas; reordena só quando entrou recorrência.
            usort($itens, static fn (array $a, array $b): int => [$a['vencimento'], $a['descricao']] <=> [$b['vencimento'], $b['descricao']]);
        }

        return new ResultadoConsultaFaturaCartao(
            cartaoDescricao: $card->descricao,
            cartaoFinal4: $card->final_4,
            totalCents: $total,
            itens: $itens,
            trace: new TraceDaConsulta(
                ferramenta: 'consultar_fatura_cartao',
                filtros: ['cartao' => $cartaoLabel, 'competencia' => $competencia],
                registros: count($itens),
            ),
        );
    }

    /**
     * Resolve o cartão pela descrição (case-insensitive) OU pelos 4 dígitos finais
     * (escopo por usuário). Não encontrado → null (fatura vazia).
     */
    private function resolverCartao(int $userId, string $cartao): ?Card
    {
        return Card::query()
            ->where('user_id', $userId)
            ->where(fn (Builder $q) => $q
                // Escapa curingas: texto da IA não vira padrão LIKE (auditoria P3-3).
                ->where('descricao', 'ilike', SqlLike::escapar($cartao))
                ->orWhere('final_4', $cartao))
            ->first();
    }
}
