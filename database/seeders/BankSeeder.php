<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

/**
 * Popula os bancos suportados pela importação de fatura (doc 04 / spec 07 §5).
 * Idempotente: firstOrCreate por `codigo`. MVP: apenas Itaú.
 */
class BankSeeder extends Seeder
{
    /** @var array<string, string> */
    private const BANCOS = [
        Bank::ITAU => 'Itaú',
    ];

    public function run(): void
    {
        foreach (self::BANCOS as $codigo => $nome) {
            Bank::firstOrCreate(['codigo' => $codigo], ['nome' => $nome]);
        }
    }
}
