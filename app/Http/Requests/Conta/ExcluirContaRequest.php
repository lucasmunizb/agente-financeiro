<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da exclusão de conta (spec FE §7.17). Dupla confirmação (regra 7): só prossegue se o
 * usuário digitar exatamente "EXCLUIR". Ação destrutiva e explícita. Error bag próprio.
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
        ];
    }
}
