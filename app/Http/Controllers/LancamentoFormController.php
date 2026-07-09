<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\EdicaoBloqueadaException;
use App\Domain\Gasto\EditarGastoManual;
use App\Http\Controllers\Concerns\SerializaPreviaDeGasto;
use App\Http\Requests\RegistrarGastoRequest;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Página de criar/editar lançamento (spec FE §7.7). Usa o MESMO formulário do modal
 * rápido (§7.7b) — componente `<x-gasto.form>` — como página cheia. Camada fina: valida
 * na borda (Form Request), delega ao domínio determinístico e devolve JSON para o fluxo
 * em dois passos (regra 7). A UI nunca calcula dinheiro (regra 4); escopo por usuário.
 *
 * - {@see self::create()} / {@see self::edit()} renderizam a página (criar × editar).
 * - Criar reaproveita os endpoints do modal ({@see GastoController::previa()}/`store()`).
 * - Editar tem os seus: {@see self::previa()} recalcula sem gravar; {@see self::update()}
 *   regenera as parcelas ({@see EditarGastoManual}) — bloqueando se houver parcela paga.
 */
class LancamentoFormController extends Controller
{
    use SerializaPreviaDeGasto;

    /** Parcelas cujo status trava a edição (regenerar apagaria histórico de pagamento). */
    private const FINALIZADAS = [StatusPagamento::PAGO, StatusPagamento::PAGO_PARCIAL];

    public function create(Request $request): View
    {
        return view('lancamentos.form', [
            'mode' => 'create',
            'transaction' => null,
            'dados' => null,
            'bloqueado' => false,
        ] + $this->opcoesDoUsuario($request->user()->id));
    }

    public function edit(Request $request, int $transaction): View
    {
        $tx = Transaction::with(['installments', 'paymentMethod'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($transaction);

        return view('lancamentos.form', [
            'mode' => 'edit',
            'transaction' => $tx,
            'dados' => $this->prefill($tx),
            'bloqueado' => $this->temParcelaFinalizada($tx),
        ] + $this->opcoesDoUsuario($request->user()->id));
    }

    public function previa(RegistrarGastoRequest $request, int $transaction, EditarGastoManual $editar): JsonResponse
    {
        $tx = $this->transacaoDoUsuario($request, $transaction);

        $previa = $editar->preview($transaction, $request->paraDominio($this->dataCompraOriginal($tx)));

        return response()->json($this->previaParaJson($previa));
    }

    public function update(RegistrarGastoRequest $request, int $transaction, EditarGastoManual $editar): JsonResponse
    {
        $tx = $this->transacaoDoUsuario($request, $transaction);

        try {
            $editar->confirmar($transaction, $request->paraDominio($this->dataCompraOriginal($tx)));
        } catch (EdicaoBloqueadaException $e) {
            return response()->json(['errors' => ['geral' => [$e->getMessage()]]], 422);
        }

        return response()->json(['ok' => true, 'redirect' => route('lancamentos')]);
    }

    /**
     * Cartões e categorias (não arquivadas) do usuário para alimentar o formulário.
     *
     * @return array<string, Collection<int, Model>>
     */
    private function opcoesDoUsuario(int $userId): array
    {
        return [
            'cartoes' => Card::where('user_id', $userId)->orderBy('descricao')->get(),
            'categorias' => Category::where('user_id', $userId)
                ->where('arquivada', false)->orderBy('nome')->get(),
        ];
    }

    /**
     * Valores atuais do lançamento para preencher o formulário no modo edição.
     * Valor em pt-BR já formatado (sem o "R$"); vencimento só faz sentido fora de cartão.
     *
     * @return array<string, mixed>
     */
    private function prefill(Transaction $tx): array
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
        ];
    }

    private function temParcelaFinalizada(Transaction $tx): bool
    {
        $finalizadas = StatusPagamento::query()
            ->whereIn('codigo', self::FINALIZADAS)
            ->pluck('id')
            ->all();

        return $tx->installments->whereIn('status_id', $finalizadas)->isNotEmpty();
    }

    /** Data de compra original (preserva o ciclo do cartão ao reeditar um crédito). */
    private function dataCompraOriginal(Transaction $tx): CarbonImmutable
    {
        return CarbonImmutable::parse($tx->data_compra->toDateString(), RelativeDate::TIMEZONE);
    }

    private function transacaoDoUsuario(Request $request, int $transaction): Transaction
    {
        return Transaction::where('user_id', $request->user()->id)->findOrFail($transaction);
    }
}
