<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use App\Models\StatusPagamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurrenceOccurrence>
 */
class RecurrenceOccurrenceFactory extends Factory
{
    public function definition(): array
    {
        $vencimento = fake()->dateTimeBetween('-2 months', '+2 months')->format('Y-m-d');

        return [
            'user_id' => User::factory(),
            'recurrence_id' => Recurrence::factory(),
            'competencia' => substr($vencimento, 0, 7),
            'descricao' => fake()->randomElement(['Netflix', 'Spotify', 'Aluguel', 'Internet']),
            'valor_cents' => fake()->numberBetween(1000, 200000),
            'data_cobranca' => $vencimento,
            'vencimento' => $vencimento,
            'payment_method_id' => PaymentMethod::firstOrCreate(['tipo' => PaymentMethod::PIX])->id,
            'card_id' => null,
            'categoria_id' => null,
            // firstOrCreate: nem toda suíte semeia a tabela de referência antes de usar a factory.
            'status_id' => fn () => StatusPagamento::firstOrCreate(
                ['codigo' => StatusPagamento::ABERTO],
                ['descricao' => StatusPagamento::CODIGOS[StatusPagamento::ABERTO]],
            )->id,
            'data_pagamento' => null,
        ];
    }

    public function pago(): static
    {
        return $this->state(fn (array $attrs) => [
            'status_id' => StatusPagamento::idFor(StatusPagamento::PAGO),
            'data_pagamento' => $attrs['data_cobranca'],
        ]);
    }
}
