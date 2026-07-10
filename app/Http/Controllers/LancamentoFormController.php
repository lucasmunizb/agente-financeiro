<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\EdicaoBloqueadaException;
use App\Domain\Gasto\EditarGastoManual;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Domain\Recorrencia\SincronizarRecorrencia;
use App\Http\Controllers\Concerns\PreparaEdicaoDeGasto;
use App\Http\Controllers\Concerns\SerializaPreviaDeGasto;
use App\Http\Requests\RegistrarGastoRequest;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $tx = Transaction::with(['installments', 'paymentMethod', 'recurrence'])
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

    public function update(
        RegistrarGastoRequest $request,
        int $transaction,
        EditarGastoManual $editar,
        RegistrarRecorrencia $recorrencias,
        SincronizarRecorrencia $sincronizar,
    ): JsonResponse {
        $tx = $this->transacaoDoUsuario($request, $transaction);

        // Edição + recorrência na MESMA transação: se a edição for bloqueada (parcela paga) nada
        // é gravado — nem a recorrência (regra 7, atômico). Dois caminhos, decididos pelo vínculo:
        //
        // • Lançamento JÁ recorrente ({@see Transaction::ehRecorrente}): o switch é ignorado (a
        //   tela o mostra travado). O usuário escolhe o ALCANCE (spec 10, "perguntar na hora"):
        //   "este e os próximos" propaga o molde à recorrência de origem ({@see SincronizarRecorrencia});
        //   "só este mês" (padrão) altera apenas este lançamento.
        // • Lançamento comum com o switch ligado: cria uma NOVA recorrência a partir do MÊS
        //   SEGUINTE — espelha o cadastro ({@see GastoController::store}), sem contar o mês em dobro.
        try {
            DB::transaction(function () use ($request, $transaction, $editar, $recorrencias, $sincronizar, $tx) {
                $dominio = $request->paraDominio($this->dataCompraOriginal($tx));
                $editar->confirmar($transaction, $dominio);

                if ($tx->ehRecorrente()) {
                    if ($request->input('escopo_recorrencia') === RegistrarGastoRequest::ESCOPO_ESTE_E_PROXIMOS
                        && $tx->recurrence !== null) {
                        $sincronizar->sincronizar($tx->recurrence, $dominio);
                    }
                } elseif ($dados = $request->dadosRecorrencia()) {
                    $hoje = CarbonImmutable::now(RelativeDate::TIMEZONE);
                    $recorrencia = $recorrencias->registrar($dados, $hoje, $hoje->startOfMonth()->addMonthNoOverflow());
                    // Passa a ser recorrente também: liga este lançamento à recorrência criada.
                    $tx->update(['recurrence_id' => $recorrencia->id]);
                }
            });
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
