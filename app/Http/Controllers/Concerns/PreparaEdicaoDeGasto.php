<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\LancamentoController;
use App\Http\Controllers\LancamentoFormController;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Dados de apoio do formulário de gasto (criar/editar) — cartões/categorias do usuário,
 * o prefill de edição e a checagem de bloqueio. Compartilhado entre a PÁGINA de edição
 * ({@see LancamentoFormController}) e o modal de edição na tela de
 * detalhe ({@see LancamentoController::show}), que renderizam o MESMO
 * componente `<x-gasto.form>`. Nada aqui calcula dinheiro (regra 4); escopo por usuário.
 */
trait PreparaEdicaoDeGasto
{
    /** Parcelas cujo status trava a edição (regenerar apagaria histórico de pagamento). */
    private const FINALIZADAS = [StatusPagamento::PAGO, StatusPagamento::PAGO_PARCIAL];

    /**
     * Cartões e categorias (não arquivadas) do usuário para alimentar o formulário.
     *
     * @return array<string, Collection<int, Model>>
     */
    protected function opcoesDoUsuario(int $userId): array
    {
        return [
            'cartoes' => Card::where('user_id', $userId)->orderBy('descricao')->get(),
            'categorias' => Category::where('user_id', $userId)
                ->where('arquivada', false)->orderBy('nome')->get(),
        ];
    }

    /**
     * Valores atuais do lançamento para preencher o formulário no modo edição. Valor em
     * pt-BR já formatado (sem o "R$"); vencimento só faz sentido fora de cartão.
     *
     * @return array<string, mixed>
     */
    protected function prefill(Transaction $tx): array
    {
        $ehCredito = $tx->paymentMethod?->tipo === PaymentMethod::CREDITO;
        $primeira = $tx->installments->sortBy('numero')->first();

        return [
            'descricao' => $tx->descricao,
            'valor' => number_format($tx->valor_total_cents / 100, 2, ',', '.'),
            'forma' => $tx->paymentMethod?->tipo ?? PaymentMethod::PIX,
            'card_id' => $tx->card_id,
            'parcelas' => max(1, $tx->installments->count()),
            'vencimento' => $ehCredito ? null : $primeira?->vencimento?->toDateString(),
            'categoria_id' => $tx->categoria_id,
            // Recorrência não vive mais em `transactions` (spec 12, D4): todo lançamento
            // editável aqui é comum. Ligar o switch CONVERTE o lançamento em recorrência (D5).
            'recorrente' => false,
            'recorrencia' => null,
        ];
    }

    protected function temParcelaFinalizada(Transaction $tx): bool
    {
        $finalizadas = StatusPagamento::query()
            ->whereIn('codigo', self::FINALIZADAS)
            ->pluck('id')
            ->all();

        return $tx->installments->whereIn('status_id', $finalizadas)->isNotEmpty();
    }
}
