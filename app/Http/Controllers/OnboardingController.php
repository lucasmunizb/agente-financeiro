<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Onboarding + consentimento LGPD. Persiste o aceite (aceite_lgpd_em) no
 * usuário autenticado. Sem consentimento explícito, não avança (regra
 * inviolável nº 6/7). O timestamp é gravado em America/Sao_Paulo (fuso base).
 */
class OnboardingController extends Controller
{
    public function show(): View
    {
        return view('onboarding');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['consent' => ['accepted']],
            ['consent.accepted' => 'É preciso concordar para continuar.'],
        );

        $request->user()->forceFill(['aceite_lgpd_em' => now()])->save();

        return redirect('/');
    }
}
