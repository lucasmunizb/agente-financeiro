<?php

namespace Database\Factories;

use App\Models\Income;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Income>
 */
class IncomeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'descricao' => fake()->randomElement(['Salário', 'Freela', 'PIX recebido']),
            'valor_cents' => fake()->numberBetween(50000, 1000000),
            'data' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'tipo' => fake()->randomElement([Income::TIPO_FIXA, Income::TIPO_VARIAVEL]),
        ];
    }
}
