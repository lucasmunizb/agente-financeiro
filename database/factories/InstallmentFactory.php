<?php

namespace Database\Factories;

use App\Models\Installment;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'numero' => 1,
            'total' => 1,
            'vencimento' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'),
            'status_id' => StatusPagamento::firstOrCreate(
                ['codigo' => StatusPagamento::AGENDADO],
                ['descricao' => StatusPagamento::CODIGOS[StatusPagamento::AGENDADO]],
            )->id,
        ];
    }
}
