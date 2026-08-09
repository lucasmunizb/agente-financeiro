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
use Illuminate\Contracts\Validation\Validator;
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
    /** Escopo da edição de um lançamento recorrente ("perguntar na hora", spec 10). */
    public const ESCOPO_ESTE = 'este';

    public const ESCOPO_ESTE_E_PROXIMOS = 'este_e_proximos';

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
        // Recorrência vale em qualquer forma, inclusive crédito (spec 12, D3 — assinatura no
        // cartão é o caso comum). Os campos do switch só são exigidos quando ele está ligado.
        $ehRecorrente = $this->boolean('recorrente');

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
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:999'],

            // Fora de cartão vence na data informada; no crédito o vencimento é
            // calculado pelo cartão (campo ignorado).
            'vencimento' => [Rule::requiredIf(! $ehCredito), 'nullable', 'date'],

            // Data da compra no crédito (opcional): permite lançar uma compra RETROATIVA —
            // o motor gera todas as parcelas a partir do ciclo do cartão, inclusive as já
            // vencidas (doc 03 §4.1). Omitida ⇒ hoje. Ignorada fora de cartão (usa `vencimento`).
            'data_compra' => ['nullable', 'date'],

            // Gasto que o usuário já pagou antes de cadastrar (decisão 2026-07-21): a data em
            // que pagou. Só FORA de cartão (no crédito quem se quita é a fatura, §4.3) e nunca
            // no futuro — é um pagamento JÁ feito, não um agendamento. O domínio marca só a 1ª
            // parcela; as demais seguem abertas.
            'data_pagamento' => [
                'nullable', 'date',
                'before_or_equal:'.CarbonImmutable::now(RelativeDate::TIMEZONE)->toDateString(),
            ],

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

            // Recorrência (§7.7, spec 10/12) — permitida também em cartão. `dia_recorrencia` é
            // o dia-do-mês (clampado na borda do mês pelo motor); `periodicidade` só "mensal".
            'recorrente' => ['nullable', 'boolean'],
            'periodicidade' => [Rule::requiredIf($ehRecorrente), 'nullable', Rule::in([Recurrence::PERIODICIDADE_MENSAL])],
            'dia_recorrencia' => [Rule::requiredIf($ehRecorrente), 'nullable', 'integer', 'min:1', 'max:31'],

            // Edição de um lançamento JÁ recorrente: até onde a mudança vale (spec 10). Só na
            // edição; ausente ⇒ "só este mês" (padrão conservador — não reescreve a regra sem pedir).
            'escopo_recorrencia' => ['nullable', Rule::in([self::ESCOPO_ESTE, self::ESCOPO_ESTE_E_PROXIMOS])],
        ];
    }

    /**
     * Regra cruzada: parcelamento e recorrência são mutuamente exclusivos. Dividir um gasto
     * em N parcelas é diferente de repeti-lo todo mês (spec §7.7) — combinar os dois não tem
     * sentido de negócio. A tela já impede (JS desabilita o switch com 2+ parcelas), mas a
     * borda é a fonte da verdade: barra o POST e devolve um erro genérico (banner geral).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->ehRecorrente() && (int) $this->input('parcelas', 1) >= 2) {
                $validator->errors()->add('recorrente', 'Um lançamento parcelado não pode repetir todo mês — escolha parcelas ou recorrência.');
            }

            // "Já paguei" não combina com cartão nem com recorrência. No crédito, o que se
            // paga é a FATURA (§4.3) — marcar a compra como paga mentiria sobre o fluxo de
            // caixa. Na recorrência, a cobrança do mês tem o seu próprio "marcar como pago"
            // (spec 12). Recusar é mais honesto que ignorar em silêncio o que o usuário pediu.
            if (! $this->filled('data_pagamento')) {
                return;
            }

            if ($this->input('forma') === PaymentMethod::CREDITO) {
                $validator->errors()->add('data_pagamento', 'Compra no cartão não se marca como paga aqui — quem se paga é a fatura.');
            }

            if ($this->ehRecorrente()) {
                $validator->errors()->add('data_pagamento', 'Numa conta que repete todo mês, marque o pagamento na cobrança do mês.');
            }
        });
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
            'data_pagamento.date' => 'Informe uma data de pagamento válida.',
            'data_pagamento.before_or_equal' => 'A data do pagamento não pode ser no futuro.',
            'dia_recorrencia.required' => 'Informe o dia da recorrência.',
            'dia_recorrencia.min' => 'O dia da recorrência vai de 1 a 31.',
            'dia_recorrencia.max' => 'O dia da recorrência vai de 1 a 31.',
        ];
    }

    /**
     * Este cadastro é uma recorrência? Basta o switch ligado — crédito passou a ser permitido
     * (spec 12, D3). Quando ligado, NENHUM lançamento é criado: nasce o molde e a ocorrência
     * do mês (R1).
     */
    public function ehRecorrente(): bool
    {
        return $this->boolean('recorrente');
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
            // Crédito exige cartão (validado acima); fora dele o domínio ignora o campo.
            cardId: $this->filled('card_id') ? (int) $this->input('card_id') : null,
            periodicidade: (string) $this->input('periodicidade', Recurrence::PERIODICIDADE_MENSAL),
        );
    }

    /**
     * Data-base da compra no crédito, no CADASTRO: a `data_compra` informada pelo usuário
     * (permite lançar retroativo — o motor gera todas as parcelas, inclusive as já
     * vencidas) ou hoje quando omitida. Na EDIÇÃO, o chamador passa a data original como
     * override em {@see self::paraDominio()} para preservar o ciclo do cartão.
     */
    private function dataCompraDoCredito(): CarbonImmutable
    {
        return $this->filled('data_compra')
            ? CarbonImmutable::parse((string) $this->input('data_compra'), RelativeDate::TIMEZONE)
            : CarbonImmutable::now(RelativeDate::TIMEZONE);
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
     * No crédito a data-base é a `data_compra` informada (compra retroativa) ou hoje
     * quando omitida; na EDIÇÃO, `$dataCompraCredito` preserva a data de compra original
     * (senão os vencimentos seriam recalculados a partir de hoje) e tem precedência.
     * Fora de cartão, a data-base é sempre o vencimento informado.
     */
    public function paraDominio(?CarbonImmutable $dataCompraCredito = null): DadosGastoManual
    {
        $forma = (string) $this->input('forma');
        $ehCredito = $forma === PaymentMethod::CREDITO;

        $dataCompra = $ehCredito
            ? ($dataCompraCredito ?? $this->dataCompraDoCredito())
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
            // Gasto já pago no ato do cadastro: o domínio marca só a 1ª parcela nesta data
            // (decisão 2026-07-21). Validado acima como fora de cartão e não-futura.
            dataPagamento: $this->filled('data_pagamento')
                ? CarbonImmutable::parse((string) $this->input('data_pagamento'), RelativeDate::TIMEZONE)
                : null,
        );
    }
}
