<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nome' => ucfirst(fake()->unique()->words(2, true)),
            'cor' => fake()->hexColor(),
            'icone' => fake()->randomElement(['car', 'home', 'food', 'health']),
            'arquivada' => false,
        ];
    }
}
