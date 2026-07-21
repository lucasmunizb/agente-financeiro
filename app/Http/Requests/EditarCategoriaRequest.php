<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validação da edição de categoria (§7.12). Mesmas regras do cadastro ({@see CriarCategoriaRequest}),
 * mas a unicidade do nome IGNORA a própria categoria. Usa um error bag próprio (`editarCategoria`)
 * para a tela reabrir o formulário certo — o de edição, não o de nova categoria — quando falha.
 */
class EditarCategoriaRequest extends CriarCategoriaRequest
{
    protected $errorBag = 'editarCategoria';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $categoriaId = (int) $this->route('categoria');
        $userId = $this->user()->id;

        $rules['nome'] = [
            'required', 'string', 'max:60',
            Rule::unique('categories', 'nome')->ignore($categoriaId)
                ->where(fn ($q) => $q->where('user_id', $userId)->whereNull('deleted_at')),
        ];

        return $rules;
    }
}
