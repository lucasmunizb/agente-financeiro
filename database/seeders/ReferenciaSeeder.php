<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Tabelas de REFERÊNCIA (determinísticas, sem dado de usuário): conjuntos fixos que o domínio
 * pressupõe existir. As migrations as criam VAZIAS — sem este seed, `PaymentMethod::idFor()` e
 * `StatusPagamento::idFor()` devolvem null e o registro de qualquer gasto trava.
 *
 * É o ÚNICO seeder que roda em PRODUÇÃO (job `migrate` do `docker-stack.yml`), por isso vive
 * separado do {@see DatabaseSeeder}: este pode ganhar dado de demonstração no futuro, e nada
 * disso pode vazar para produção. Todos os seeders chamados aqui são idempotentes
 * (`firstOrCreate`) — o job roda a cada deploy.
 *
 * Incidente 2026-07-16: o deploy rodava só `migrate --force`, nunca um seed. A tabela
 * `payment_methods` estava vazia em produção e o bot pedia a forma de pagamento em loop
 * infinito — mesmo com o usuário respondendo "pix". Ver `docs/specs/10c-recorrencia-via-bot.md`.
 */
class ReferenciaSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PaymentMethodSeeder::class,
            StatusPagamentoSeeder::class,
            BankSeeder::class,
        ]);
    }
}
