<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da borda de "marcar parcela como paga" (FE §7.8). Só a data importa;
 * a parcela vem no path (token opaco). A regra de negócio — só fora de cartão,
 * não tocar nas irmãs, agregar status — é do domínio {@see App\Domain\Gasto\RegistrarPagamentoParcela}.
 */
class PagarParcelaRequest extends FormRequest
{
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
            'data_pagamento' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'data_pagamento.required' => 'Informe a data de pagamento.',
            'data_pagamento.date' => 'Informe uma data válida.',
        ];
    }
}
