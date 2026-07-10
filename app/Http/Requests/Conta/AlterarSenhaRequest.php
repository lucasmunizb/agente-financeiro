<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validação da troca de senha (spec FE §7.17). Exige a senha ATUAL correta (`current_password`,
 * defesa contra sequestro de sessão) e a nova confirmada. A senha em claro nunca é logada nem
 * auditada — só o hash é persistido. Error bag próprio.
 */
class AlterarSenhaRequest extends FormRequest
{
    protected $errorBag = 'senha';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'senha_atual' => ['required', 'current_password'],
            'senha' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'senha_atual.current_password' => 'A senha atual está incorreta.',
            'senha.confirmed' => 'A confirmação da nova senha não confere.',
        ];
    }
}
