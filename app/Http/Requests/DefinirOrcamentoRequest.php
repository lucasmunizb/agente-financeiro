<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação e tradução na borda da definição do orçamento geral (§7.11). Normaliza o valor
 * pt-BR para centavos (regra 5; a borda não calcula, regra 4) e exige um limite POSITIVO — um
 * limite de R$ 0 seria "sem orçamento", representado pela ausência da linha. `mes` é opcional
 * (default = mês atual); não é id, vai em claro na URL (mesma convenção da lista/dashboard).
 */
class DefinirOrcamentoRequest extends FormRequest
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
            'mes' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'valor' => ['required', 'string', $this->valorMonetario()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valor.required' => 'Informe o limite do mês.',
            'mes.regex' => 'Mês inválido.',
        ];
    }

    /** Limite em centavos, já normalizado (regra 5). */
    public function limiteCents(): int
    {
        return Money::fromHuman((string) $this->input('valor'))->cents();
    }

    /** Competência alvo (YYYY-MM); default = mês atual no fuso base. */
    public function mesAlvo(): string
    {
        $mes = (string) $this->input('mes', '');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1) {
            return $mes;
        }

        return CarbonImmutable::now(RelativeDate::TIMEZONE)->format('Y-m');
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
