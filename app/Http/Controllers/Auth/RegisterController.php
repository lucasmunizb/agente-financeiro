<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Categoria\CriarCategoriasSugeridas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Criação de conta (borda web). Cria o usuário com senha em hash (cast
 * 'hashed' no model), autentica na sessão regenerada e encaminha ao onboarding,
 * onde o aceite LGPD é registrado. Apresentação (a tela) é etapa separada.
 */
class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, CriarCategoriasSugeridas $categorias): RedirectResponse
    {
        $user = User::create([
            'name' => $request->string('name')->trim()->value(),
            'email' => $request->string('email')->lower()->trim()->value(),
            'password' => $request->string('password')->value(),
        ]);

        // Categorias sugeridas para começar a classificar já no primeiro gasto (doc 08 §5).
        $categorias->para($user->id);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('onboarding');
    }
}
