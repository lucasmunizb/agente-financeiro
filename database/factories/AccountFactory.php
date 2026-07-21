<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'banco' => fake()->randomElement(['Itaú', 'Nubank', 'Bradesco', 'Inter', 'Caixa']),
            'descricao' => fake()->randomElement(['Conta corrente', 'Conta poupança', 'Conta salário']),
        ];
    }
}
