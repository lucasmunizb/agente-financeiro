<?php

declare(strict_types=1);

use App\Models\Bank;
use App\Models\PaymentMethod;
use App\Models\StatusPagamento;
use Database\Seeders\ReferenciaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tabelas de referência em PRODUÇÃO (incidente 2026-07-16).
 *
 * As migrations criam `payment_methods`/`status_pagamento`/`banks` VAZIAS — quem as popula é
 * seeder. O job de deploy rodava só `php artisan migrate --force`, sem seed algum: em produção
 * a tabela ficava vazia, `PaymentMethod::idFor('pix')` devolvia null SEMPRE e o bot pedia a
 * forma de pagamento em loop, independente do que o usuário escrevesse.
 *
 * Estes testes cobrem o seeder que o deploy passa a chamar. Note o que eles NÃO provam: que o
 * pipeline realmente o executa — isso é o `command` do `docker-stack.yml`, verificado abaixo, e
 * em última instância só um smoke contra produção fecha (ver spec 10c §2).
 */

uses(RefreshDatabase::class);

it('popula as três tabelas de referência a partir das migrations vazias', function () {
    // Sem seed no beforeEach: é exatamente o estado de produção após `migrate --force`.
    expect(PaymentMethod::count())->toBe(0);

    (new ReferenciaSeeder)->run();

    expect(PaymentMethod::pluck('tipo')->all())->toEqualCanonicalizing(PaymentMethod::TIPOS)
        ->and(StatusPagamento::count())->toBe(count(StatusPagamento::CODIGOS))
        ->and(Bank::count())->toBeGreaterThan(0);
});

it('resolve a forma de pagamento depois de seedado — o que quebrava o bot', function () {
    (new ReferenciaSeeder)->run();

    expect(PaymentMethod::idFor('pix'))->not->toBeNull();
});

it('é idempotente: roda a cada deploy sem duplicar nem estourar o unique', function () {
    (new ReferenciaSeeder)->run();
    (new ReferenciaSeeder)->run();
    (new ReferenciaSeeder)->run();

    expect(PaymentMethod::count())->toBe(count(PaymentMethod::TIPOS))
        ->and(StatusPagamento::count())->toBe(count(StatusPagamento::CODIGOS));
});

it('o job de migrate do stack de produção seeda as tabelas de referência', function () {
    // Guarda o CAMINHO DE DEPLOY, não o domínio: sem isto, todo o resto passa verde e a
    // produção continua com a tabela vazia. Foi essa a lacuna do incidente.
    $stack = file_get_contents(base_path('docker-stack.yml'));

    expect($stack)->toContain('ReferenciaSeeder');
});
