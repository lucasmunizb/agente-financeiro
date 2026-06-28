<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Tabelas de referência (determinísticas) — sempre presentes.
        $this->call([
            PaymentMethodSeeder::class,
            StatusPagamentoSeeder::class,
            BankSeeder::class,
        ]);
    }
}
