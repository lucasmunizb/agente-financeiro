<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Card>
 */
class CardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'descricao' => fake()->randomElement(['cartão pai', 'cartão mãe', 'Nubank', 'Inter']),
            'final_4' => (string) fake()->numerify('####'),
            'limite_cents' => fake()->randomElement([null, 500000, 1000000, 300000]),
            'dia_fechamento' => fake()->numberBetween(1, 28),
            'dia_vencimento' => fake()->numberBetween(1, 28),
        ];
    }
}
