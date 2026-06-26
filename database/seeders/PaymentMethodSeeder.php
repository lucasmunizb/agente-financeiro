<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Popula a tabela de referência de formas de pagamento (doc 03 §4.6).
 * Idempotente: usa upsert por `tipo`.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PaymentMethod::TIPOS as $tipo) {
            PaymentMethod::firstOrCreate(['tipo' => $tipo]);
        }
    }
}
