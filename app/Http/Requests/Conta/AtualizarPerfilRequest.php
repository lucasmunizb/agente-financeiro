<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do perfil (spec FE §7.17). Nome/e-mail obrigatórios; o e-mail é único ignorando a
 * própria conta. O fuso é validado contra os identificadores válidos (regra `timezone`) — é
 * preferência do usuário; o motor financeiro segue America/Sao_Paulo (regra 5). Error bag próprio
 * para a tela reabrir a seção certa.
 */
class AtualizarPerfilRequest extends FormRequest
{
    protected $errorBag = 'perfil';

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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Este e-mail já está em uso.',
            'timezone.timezone' => 'Selecione um fuso horário válido.',
        ];
    }
}
