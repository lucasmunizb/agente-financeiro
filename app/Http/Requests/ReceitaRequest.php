<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Receita\DadosReceita;
use App\Domain\Shared\Money;
use App\Models\Income;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação e tradução na borda do cadastro de receita (§7.10). Normaliza o valor pt-BR para
 * centavos (regra 5; a borda não soma, regra 4) e exige um valor POSITIVO. `tipo` é fixa/variável;
 * `data` é a data de recebimento. `confirmado` é só o flag do 2º passo (regra 7) — não é campo.
 */
class ReceitaRequest extends FormRequest
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
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'string', $this->valorMonetario()],
            'tipo' => ['required', Rule::in([Income::TIPO_FIXA, Income::TIPO_VARIAVEL])],
            'data' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'descricao.required' => 'Informe uma descrição.',
            'valor.required' => 'Informe um valor.',
            'tipo.required' => 'Escolha o tipo.',
            'tipo.in' => 'Tipo inválido.',
            'data.required' => 'Informe a data.',
        ];
    }

    public function paraDominio(): DadosReceita
    {
        return new DadosReceita(
            userId: $this->user()->id,
            descricao: trim((string) $this->input('descricao')),
            valorCents: Money::fromHuman((string) $this->input('valor'))->cents(),
            data: CarbonImmutable::parse((string) $this->input('data'), RelativeDate::TIMEZONE),
            tipo: (string) $this->input('tipo'),
        );
    }

    /** 2º passo do fluxo (regra 7): o usuário confirmou o resumo. */
    public function confirmado(): bool
    {
        return $this->boolean('confirmado');
    }

    private function valorMonetario(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                $cents = Money::fromHuman((string) $value)->cents();
            } catch (\Throwable) {
                $fail('Informe um valor válido.');

                return;
            }

            if ($cents <= 0) {
                $fail('Informe um valor maior que zero.');
            }
        };
    }
}
