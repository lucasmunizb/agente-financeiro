<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recurrence>
 */
class RecurrenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'descricao' => fake()->randomElement(['Netflix', 'Spotify', 'Aluguel', 'Internet']),
            'valor_cents' => fake()->numberBetween(1000, 200000),
            'payment_method_id' => PaymentMethod::firstOrCreate(['tipo' => PaymentMethod::PIX])->id,
            'categoria_id' => null,
            'periodicidade' => Recurrence::PERIODICIDADE_MENSAL,
            'dia' => fake()->numberBetween(1, 28),
            'status' => Recurrence::STATUS_ATIVO,
            'proxima_em' => null,
        ];
    }

    public function cancelado(): static
    {
        return $this->state(fn () => ['status' => Recurrence::STATUS_CANCELADO, 'proxima_em' => null]);
    }
}
