<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Cartao\DadosCartao;
use App\Domain\Shared\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação e tradução na borda do cadastro de cartão (§7.13). Cartão é identificado só por
 * descrição + 4 dígitos finais (nunca o número completo — LGPD/§4.6). Dias de ciclo em 1..31;
 * limite opcional em pt-BR → centavos (regra 5). Unicidade (usuário, final_4, descrição) entre
 * cartões ativos — espelha o índice parcial do banco, com mensagem amigável.
 */
class CriarCartaoRequest extends FormRequest
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
        $userId = $this->user()->id;

        return [
            'descricao' => ['required', 'string', 'max:255'],
            'final_4' => [
                'required', 'digits:4',
                Rule::unique('cards', 'final_4')->where(fn ($q) => $q
                    ->where('user_id', $userId)
                    ->where('descricao', (string) $this->input('descricao'))
                    ->whereNull('deleted_at')),
            ],
            'dia_fechamento' => ['required', 'integer', 'between:1,31'],
            'dia_vencimento' => ['required', 'integer', 'between:1,31'],
            'limite' => ['nullable', 'string', $this->limiteMonetario()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'descricao.required' => 'Informe uma descrição.',
            'final_4.required' => 'Informe os 4 dígitos finais.',
            'final_4.digits' => 'Use exatamente 4 dígitos.',
            'final_4.unique' => 'Você já tem um cartão com essa descrição e final.',
            'dia_fechamento.required' => 'Informe o dia de fechamento.',
            'dia_fechamento.between' => 'O dia de fechamento vai de 1 a 31.',
            'dia_vencimento.required' => 'Informe o dia de vencimento.',
            'dia_vencimento.between' => 'O dia de vencimento vai de 1 a 31.',
        ];
    }

    public function paraDominio(): DadosCartao
    {
        return new DadosCartao(
            userId: $this->user()->id,
            descricao: trim((string) $this->input('descricao')),
            final4: (string) $this->input('final_4'),
            diaFechamento: (int) $this->input('dia_fechamento'),
            diaVencimento: (int) $this->input('dia_vencimento'),
            limiteCents: $this->filled('limite') ? Money::fromHuman((string) $this->input('limite'))->cents() : null,
        );
    }

    private function limiteMonetario(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            try {
                Money::fromHuman((string) $value)->cents();
            } catch (\Throwable) {
                $fail('Informe um limite válido.');
            }
        };
    }
}
