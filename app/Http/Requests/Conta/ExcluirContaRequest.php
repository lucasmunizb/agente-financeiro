<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da exclusão de conta (spec FE §7.17). Dupla confirmação (regra 7): só prossegue se o
 * usuário digitar exatamente "EXCLUIR" E provar a identidade com a senha atual (`current_password`).
 * A confirmação textual mostra intenção; a senha impede que uma sessão sequestrada/estação
 * desbloqueada dispare uma ação destrutiva e irreversível (pentest 2026-07 M1). Error bag próprio.
 */
class ExcluirContaRequest extends FormRequest
{
    protected $errorBag = 'excluir';

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
            'confirmacao' => ['required', Rule::in(['EXCLUIR'])],
            'senha_atual' => ['required', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmacao.required' => 'Digite EXCLUIR para confirmar.',
            'confirmacao.in' => 'Digite EXCLUIR (em maiúsculas) para confirmar.',
            'senha_atual.required' => 'Confirme sua senha atual para excluir a conta.',
            'senha_atual.current_password' => 'A senha atual está incorreta.',
        ];
    }
}
