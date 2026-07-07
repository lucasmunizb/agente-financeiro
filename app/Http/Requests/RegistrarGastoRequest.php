<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Shared\Money;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação e tradução na borda web do cadastro de gasto manual (modal §7.7b).
 *
 * Traduz a entrada do formulário para o DTO {@see DadosGastoManual} que o domínio
 * ({@see RegistrarGastoManual}) consome. A borda NÃO calcula
 * dinheiro nem vencimento (regra 4): apenas normaliza o valor pt-BR para centavos
 * e escolhe a data-base (compra hoje no crédito; a data informada fora de cartão)
 * — o cálculo das parcelas/vencimentos é do motor determinístico.
 *
 * `cardId`/`categoriaId`/`vencimento` são condicionais à forma de pagamento e
 * validados com escopo no usuário autenticado (nada de terceiros).
 */
class RegistrarGastoRequest extends FormRequest
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
        $ehCredito = $this->input('forma') === PaymentMethod::CREDITO;

        return [
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'string', $this->valorMonetario()],
            'forma' => ['required', Rule::in(PaymentMethod::TIPOS)],

            // Crédito é a única forma em cartão; o cartão precisa ser do usuário.
            'card_id' => [
                Rule::requiredIf($ehCredito),
                'nullable', 'integer',
                Rule::exists('cards', 'id')->where('user_id', $userId)->whereNull('deleted_at'),
            ],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],

            // Fora de cartão vence na data informada; no crédito o vencimento é
            // calculado pelo cartão (campo ignorado).
            'vencimento' => [Rule::requiredIf(! $ehCredito), 'nullable', 'date'],

            'categoria_id' => [
                'nullable', 'integer',
                // Closure: o boolean `arquivada` precisa do query builder real —
                // a forma ->where('arquivada', false) do presence verifier bind
                // `false` como '' e o Postgres rejeita (invalid boolean).
                Rule::exists('categories', 'id')->where(function ($query) use ($userId) {
                    $query->where('user_id', $userId)
                        ->where('arquivada', false)
                        ->whereNull('deleted_at');
                }),
            ],

            // Aceito para compatibilidade com o formulário; marcar como pago ainda
            // não é suportado pelo domínio (ver nota no controller). Não persistido.
            'pagamento' => ['nullable', 'date'],
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
            'forma.required' => 'Escolha a forma de pagamento.',
            'forma.in' => 'Forma de pagamento inválida.',
            'card_id.required' => 'Crédito exige um cartão.',
            'card_id.exists' => 'Selecione um cartão válido.',
            'categoria_id.exists' => 'Selecione uma categoria válida.',
            'parcelas.min' => 'As parcelas vão de 1 a 24.',
            'parcelas.max' => 'As parcelas vão de 1 a 24.',
            'vencimento.required' => 'Informe a data de vencimento.',
        ];
    }

    /**
     * Regra de valor monetário pt-BR: precisa ser interpretável e maior que zero.
     */
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

    /**
     * Traduz a entrada validada para o DTO do domínio.
     */
    public function paraDominio(): DadosGastoManual
    {
        $forma = (string) $this->input('forma');
        $ehCredito = $forma === PaymentMethod::CREDITO;

        $dataCompra = $ehCredito
            ? CarbonImmutable::now(RelativeDate::TIMEZONE)
            : CarbonImmutable::parse((string) $this->input('vencimento'), RelativeDate::TIMEZONE);

        return new DadosGastoManual(
            userId: $this->user()->id,
            descricao: trim((string) $this->input('descricao')),
            valorTotalCents: Money::fromHuman((string) $this->input('valor'))->cents(),
            dataCompra: $dataCompra,
            paymentMethodId: PaymentMethod::idFor($forma),
            parcelas: $ehCredito ? max(1, (int) $this->input('parcelas', 1)) : 1,
            cardId: $ehCredito ? (int) $this->input('card_id') : null,
            accountId: null,
            categoriaId: $this->filled('categoria_id') ? (int) $this->input('categoria_id') : null,
            origem: 'manual',
        );
    }
}
