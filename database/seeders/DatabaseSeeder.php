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
        // Tabelas de referência (determinísticas) — sempre presentes. Fonte única:
        // ReferenciaSeeder, o MESMO que o job de migrate roda em produção. Não duplique a
        // lista aqui: se dev e produção seedarem coisas diferentes, o bug volta.
        $this->call(ReferenciaSeeder::class);
    }
}
