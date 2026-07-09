<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validação da edição de cartão (§7.13). Mesmas regras do cadastro ({@see CriarCartaoRequest}),
 * mas a unicidade (usuário, final_4, descrição) IGNORA o próprio cartão. Usa um error bag
 * próprio (`editarCartao`) para a tela reabrir o formulário certo — o de edição, não o de
 * adicionar — quando a validação falha.
 */
class EditarCartaoRequest extends CriarCartaoRequest
{
    protected $errorBag = 'editarCartao';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $cardId = (int) $this->route('cartao');
        $userId = $this->user()->id;

        $rules['final_4'] = [
            'required', 'digits:4',
            Rule::unique('cards', 'final_4')->ignore($cardId)->where(fn ($q) => $q
                ->where('user_id', $userId)
                ->where('descricao', (string) $this->input('descricao'))
                ->whereNull('deleted_at')),
        ];

        return $rules;
    }
}
