<?php

namespace App\Http\Controllers;

use App\Domain\Atualizacoes\AssinaturaDeAtualizacoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assinatura de atualizações para o polling das telas (JSON). O dashboard e o
 * extrato consultam este endpoint periodicamente; quando a assinatura muda —
 * porque uma transação foi registrada/paga/editada por outro canal, como o chat
 * do Telegram — o frontend recarrega a tela (regra 3: a apresentação vem depois).
 * Escopo do usuário autenticado: não expõe estado de outra conta.
 */
class AtualizacoesController extends Controller
{
    public function assinatura(Request $request, AssinaturaDeAtualizacoes $assinatura): JsonResponse
    {
        return response()->json([
            'assinatura' => $assinatura->para($request->user()->id),
        ]);
    }
}
