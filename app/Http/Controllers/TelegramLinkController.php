<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela de vínculo com o Telegram (apresentação — regra inviolável 3). O backend
 * do vínculo seguro (token de uso único, expiração, confirmação de contato) é
 * etapa separada e posterior (doc 06). Aqui só renderizamos os estados da tela
 * com dados de exemplo; ?preview=pendente|expirado|vinculado alterna o estado
 * para revisão de design, no mesmo espírito das telas de login/onboarding.
 */
class TelegramLinkController extends Controller
{
    public function show(Request $request): View
    {
        $estado = in_array($request->query('preview'), ['expirado', 'vinculado'], true)
            ? $request->query('preview')
            : 'pendente';

        return view('telegram', ['estado' => $estado]);
    }
}
