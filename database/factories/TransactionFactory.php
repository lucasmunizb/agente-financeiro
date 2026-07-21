<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'descricao' => fake()->randomElement(['Mercado', 'Uber', 'iFood', 'Farmácia', 'Posto']),
            'valor_total_cents' => fake()->numberBetween(500, 500000),
            'data_compra' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'payment_method_id' => PaymentMethod::firstOrCreate(['tipo' => PaymentMethod::PIX])->id,
            'card_id' => null,
            'account_id' => null,
            'categoria_id' => null,
            'status_id' => StatusPagamento::firstOrCreate(
                ['codigo' => StatusPagamento::ABERTO],
                ['descricao' => StatusPagamento::CODIGOS[StatusPagamento::ABERTO]],
            )->id,
            'origem' => 'manual',
            'moeda' => 'BRL',
        ];
    }
}
