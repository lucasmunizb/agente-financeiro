<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Shared\Money;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
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
        // Recorrência só existe fora de cartão (crédito usa parcelas); os campos do switch
        // ("Repete todo mês?") só são exigidos quando ligado nessa condição.
        $ehRecorrente = $this->boolean('recorrente') && ! $ehCredito;

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

            // Recorrência (§7.7, spec 10) — só fora de cartão. `dia_recorrencia` é o dia-do-mês
            // (clampado na borda do mês pelo motor); `periodicidade` é só "mensal" no MVP.
            'recorrente' => ['nullable', 'boolean'],
            'periodicidade' => [Rule::requiredIf($ehRecorrente), 'nullable', Rule::in([Recurrence::PERIODICIDADE_MENSAL])],
            'dia_recorrencia' => [Rule::requiredIf($ehRecorrente), 'nullable', 'integer', 'min:1', 'max:31'],
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
            'dia_recorrencia.required' => 'Informe o dia da recorrência.',
            'dia_recorrencia.min' => 'O dia da recorrência vai de 1 a 31.',
            'dia_recorrencia.max' => 'O dia da recorrência vai de 1 a 31.',
        ];
    }

    /**
     * Este cadastro também cria uma recorrência? Só fora de cartão e com o switch ligado
     * (crédito usa parcelas — o switch nem aparece no form nessa forma).
     */
    public function ehRecorrente(): bool
    {
        return $this->boolean('recorrente') && $this->input('forma') !== PaymentMethod::CREDITO;
    }

    /**
     * Traduz os campos do switch para o DTO da recorrência — ou null quando não é recorrente.
     * Mesma normalização de valor (centavos, regra 5) do gasto; o dia é o do form.
     */
    public function dadosRecorrencia(): ?DadosRecorrencia
    {
        if (! $this->ehRecorrente()) {
            return null;
        }

        return new DadosRecorrencia(
            userId: $this->user()->id,
            descricao: trim((string) $this->input('descricao')),
            valorCents: Money::fromHuman((string) $this->input('valor'))->cents(),
            paymentMethodId: PaymentMethod::idFor((string) $this->input('forma')),
            dia: (int) $this->input('dia_recorrencia'),
            categoriaId: $this->filled('categoria_id') ? (int) $this->input('categoria_id') : null,
            periodicidade: (string) $this->input('periodicidade', Recurrence::PERIODICIDADE_MENSAL),
        );
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
     *
     * No crédito a data-base é "hoje" no cadastro; na EDIÇÃO, `$dataCompraCredito`
     * preserva a data de compra original (senão os vencimentos seriam recalculados
     * a partir de hoje). Fora de cartão, a data-base é sempre o vencimento informado.
     */
    public function paraDominio(?CarbonImmutable $dataCompraCredito = null): DadosGastoManual
    {
        $forma = (string) $this->input('forma');
        $ehCredito = $forma === PaymentMethod::CREDITO;

        $dataCompra = $ehCredito
            ? ($dataCompraCredito ?? CarbonImmutable::now(RelativeDate::TIMEZONE))
            : CarbonImmutable::parse((string) $this->input('vencimento'), RelativeDate::TIMEZONE);

        return new DadosGastoManual(
            userId: $this->user()->id,
            descricao: trim((string) $this->input('descricao')),
            valorTotalCents: Money::fromHuman((string) $this->input('valor'))->cents(),
            dataCompra: $dataCompra,
            paymentMethodId: PaymentMethod::idFor($forma),
            // Parcelamento vale em cartão E fora de cartão (ex.: combinar pagar alguém
            // em Nx via pix — não recorrente, decisão do usuário 2026-07-08). O motor
            // (GeradorDeParcelas) gera N parcelas +1 mês independente da forma.
            parcelas: max(1, (int) $this->input('parcelas', 1)),
            cardId: $ehCredito ? (int) $this->input('card_id') : null,
            accountId: null,
            categoriaId: $this->filled('categoria_id') ? (int) $this->input('categoria_id') : null,
            origem: 'manual',
        );
    }
}
