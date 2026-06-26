<?php

namespace Database\Seeders;

use App\Models\StatusPagamento;
use Illuminate\Database\Seeder;

/**
 * Popula a tabela de referência de status de pagamento (doc 03 §4.4).
 * Idempotente: usa firstOrCreate por `codigo`.
 */
class StatusPagamentoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (StatusPagamento::CODIGOS as $codigo => $descricao) {
            StatusPagamento::firstOrCreate(
                ['codigo' => $codigo],
                ['descricao' => $descricao],
            );
        }
    }
}
