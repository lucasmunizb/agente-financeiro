<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'entidade' => 'transaction',
            'entidade_id' => fake()->numberBetween(1, 1000),
            'acao' => AuditLog::ACAO_CRIAR,
            'antes' => null,
            'depois' => ['descricao' => fake()->word(), 'valor_total_cents' => fake()->numberBetween(500, 500000)],
            'origem' => 'manual',
        ];
    }
}
