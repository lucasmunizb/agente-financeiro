<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mes' => fake()->numberBetween(2026, 2026).'-'.str_pad((string) fake()->numberBetween(1, 12), 2, '0', STR_PAD_LEFT),
            'limite_cents' => fake()->numberBetween(100000, 1000000),
            'categoria_id' => null,
        ];
    }
}
