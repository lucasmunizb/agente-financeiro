<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\EdicaoBloqueadaException;
use App\Domain\Gasto\EditarGastoManual;
use App\Http\Controllers\Concerns\PreparaEdicaoDeGasto;
use App\Http\Controllers\Concerns\SerializaPreviaDeGasto;
use App\Http\Requests\RegistrarGastoRequest;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    use PreparaEdicaoDeGasto, SerializaPreviaDeGasto;

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
