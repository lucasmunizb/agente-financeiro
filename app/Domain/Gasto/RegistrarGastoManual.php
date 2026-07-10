<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Duplicidade\ChaveDeDuplicidade;
use App\Domain\Duplicidade\DetectorDeDuplicidade;
use App\Domain\Shared\Money;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de gasto manual (F2.3) — orquestra o motor determinístico do Bloco 1.
 *
 * Fluxo: resolve o vencimento (cartão usa o ciclo da fatura; fora de cartão vence
 * na data da compra) → gera as parcelas (valor derivado) → deriva o status de cada
 * parcela pela data → detecta duplicidade. O {@see self::preview()} apenas calcula
 * (não grava); o {@see self::confirmar()} persiste de forma atômica a transaction,
 * as installments e a auditoria. A origem vem do DTO
 * ({@see DadosGastoManual::$origem}, `manual` por padrão; `pdf` na importação).
 *
 * A IA nunca passa por aqui: todo cálculo é determinístico.
 */
final class RegistrarGastoManual
{
    public function preview(DadosGastoManual $dados, ?CarbonImmutable $hoje = null): PreviaGastoManual
    {
        $hoje ??= CarbonImmutable::now(RelativeDate::TIMEZONE);

        return new PreviaGastoManual(
            descricao: $dados->descricao,
            valorTotal: Money::fromCents($dados->valorTotalCents),
            origem: $dados->origem,
            ehDuplicado: $this->ehDuplicado($dados),
            parcelas: $this->montarParcelas($dados, $hoje),
            categoria: $this->nomeDaCategoria($dados),
            categoriaSugeridaPorIa: $dados->categoriaSugeridaPorIa,
        );
    }

    /**
     * Nome da categoria pré-selecionada, para a apresentação exibir a dica sem consultar o
     * banco. Escopo estrito por usuário; null quando não há categoria (ou não é do usuário).
     */
    private function nomeDaCategoria(DadosGastoManual $dados): ?string
    {
        if ($dados->categoriaId === null) {
            return null;
        }

        return Category::query()
            ->where('id', $dados->categoriaId)
            ->where('user_id', $dados->userId)
            ->value('nome');
    }

    public function confirmar(DadosGastoManual $dados, ?CarbonImmutable $hoje = null): Transaction
    {
        $hoje ??= CarbonImmutable::now(RelativeDate::TIMEZONE);
        $parcelas = $this->montarParcelas($dados, $hoje);

        return DB::transaction(function () use ($dados, $parcelas) {
            $transaction = Transaction::create([
                'user_id' => $dados->userId,
                'descricao' => $dados->descricao,
                'valor_total_cents' => $dados->valorTotalCents,
                'data_compra' => $dados->dataCompra->toDateString(),
                'payment_method_id' => $dados->paymentMethodId,
                'card_id' => $dados->cardId,
                'account_id' => $dados->accountId,
                'categoria_id' => $dados->categoriaId,
                'recurrence_id' => $dados->recurrenceId,
                'status_id' => StatusPagamento::idFor(StatusPagamento::ABERTO),
                'origem' => $dados->origem,
                'moeda' => 'BRL',
            ]);

            foreach ($parcelas as $parcela) {
                $transaction->installments()->create([
                    'numero' => $parcela->numero,
                    'total' => $parcela->total,
                    'vencimento' => $parcela->vencimento->toDateString(),
                    'status_id' => StatusPagamento::idFor($parcela->statusCodigo),
                ]);
            }

            AuditLog::create([
                'user_id' => $dados->userId,
                'entidade' => 'transaction',
                'entidade_id' => $transaction->id,
                'acao' => AuditLog::ACAO_CRIAR,
                'antes' => null,
                'depois' => [
                    'descricao' => $transaction->descricao,
                    'valor_total_cents' => $transaction->valor_total_cents,
                    'data_compra' => $transaction->data_compra->toDateString(),
                    'payment_method_id' => $transaction->payment_method_id,
                    'card_id' => $transaction->card_id,
                    'account_id' => $transaction->account_id,
                    'categoria_id' => $transaction->categoria_id,
                    'parcelas' => count($parcelas),
                ],
                'origem' => $dados->origem,
            ]);

            return $transaction->load('installments');
        });
    }

    /**
     * Calcula as parcelas (vencimento + valor derivado + status por data).
     *
     * @return array<int, ParcelaPrevia>
     */
    private function montarParcelas(DadosGastoManual $dados, CarbonImmutable $hoje): array
    {
        return (new MontadorDeParcelas)->montar($dados, $hoje);
    }

    private function ehDuplicado(DadosGastoManual $dados): bool
    {
        $candidato = ChaveDeDuplicidade::de(
            $dados->valorTotalCents,
            $dados->descricao,
            $dados->dataCompra,
            $dados->parcelas,
        );

        $existentes = Transaction::query()
            ->where('user_id', $dados->userId)
            ->with('installments')
            ->get()
            ->map(fn (Transaction $tx) => ChaveDeDuplicidade::de(
                $tx->valor_total_cents,
                $tx->descricao,
                CarbonImmutable::parse($tx->data_compra->toDateString(), RelativeDate::TIMEZONE),
                $tx->installments->count(),
            ))
            ->all();

        return DetectorDeDuplicidade::ehDuplicado($candidato, $existentes);
    }
}
