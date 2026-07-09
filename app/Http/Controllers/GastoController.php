<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Gasto\RegistrarGastoManual;
use App\Http\Controllers\Concerns\SerializaPreviaDeGasto;
use App\Http\Requests\RegistrarGastoRequest;
use Illuminate\Http\JsonResponse;

/**
 * Borda web do cadastro de gasto manual (modal §7.7b). Camada fina: valida na
 * borda (Form Request), delega ao domínio já testado ({@see RegistrarGastoManual})
 * e devolve JSON para o modal. Não há cálculo aqui (regra 4).
 *
 * Fluxo em dois passos (regra 7 — confirmar antes de gravar):
 * - {@see self::previa()} calcula e devolve a prévia SEM persistir;
 * - {@see self::store()} persiste após a confirmação do usuário.
 *
 * Nota: "Data de pagamento" (marcar como pago na criação) ainda não é suportada
 * pelo domínio — o campo é aceito, porém ignorado; será uma feature própria (com
 * migration + TDD) quando entrar no escopo.
 */
class GastoController extends Controller
{
    use SerializaPreviaDeGasto;

    public function previa(RegistrarGastoRequest $request, RegistrarGastoManual $registrar): JsonResponse
    {
        $previa = $registrar->preview($request->paraDominio());

        return response()->json($this->previaParaJson($previa));
    }

    public function store(RegistrarGastoRequest $request, RegistrarGastoManual $registrar): JsonResponse
    {
        $transaction = $registrar->confirmar($request->paraDominio());

        return response()->json(['ok' => true, 'id' => $transaction->id]);
    }
}
