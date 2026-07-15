<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validação da criação de conta (borda web). A regra vive aqui; o controller só
 * orquestra. Termos são obrigatórios (consentimento — regra inviolável nº 7).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // uncompromised(): recusa senhas já vazadas (HIBP k-anonymity, nativo). Falha
            // aberta se a API estiver inacessível — não trava o cadastro (pentest 2026-07 L2).
            'password' => ['required', 'string', 'confirmed', Password::min(8)->uncompromised()],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => 'Use um e-mail válido.',
            'email.unique' => 'Este e-mail já está cadastrado.',
            'password.confirmed' => 'As senhas não conferem.',
            'password.min' => 'A senha deve ter ao menos 8 caracteres.',
            'terms.accepted' => 'Aceite os termos para continuar.',
        ];
    }
}
