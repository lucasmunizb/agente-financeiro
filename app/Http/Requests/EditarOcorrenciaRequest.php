<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Recorrencia\EditarOcorrencia;
use App\Domain\Shared\Money;
use App\Domain\Shared\OpaqueId;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da borda ao editar UMA ocorrência de recorrência — o escopo "só este mês"
 * (spec 12). Normaliza o valor pt-BR para centavos (regra 5; a borda não soma, regra 4) e
 * exige valor positivo, igual ao cadastro de receita/gasto.
 *
 * `categoria` chega como token OPACO (nenhum id real em URL/payload): decodifica aqui e
 * deixa o domínio ({@see EditarOcorrencia}) conferir a posse — id de
 * categoria alheia é simplesmente ignorado lá, nunca aplicado.
 */
class EditarOcorrenciaRequest extends FormRequest
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
            'vencimento' => ['required', 'date'],
            'categoria' => ['nullable', 'string'],
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
            'vencimento.required' => 'Informe o vencimento.',
        ];
    }

    public function descricao(): string
    {
        return trim((string) $this->input('descricao'));
    }

    public function valorCents(): int
    {
        return Money::fromHuman((string) $this->input('valor'))->cents();
    }

    /** Id real da categoria, ou null quando ausente/forjada (o domínio revalida a posse). */
    public function categoriaId(): ?int
    {
        $valor = $this->input('categoria');

        return OpaqueId::decode(is_string($valor) && $valor !== '' ? $valor : null);
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
