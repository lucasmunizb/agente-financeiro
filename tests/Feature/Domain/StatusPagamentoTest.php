<?php

use App\Models\StatusPagamento;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * status_pagamento — tabela de referência (doc 03 §4.4).
 * Conjunto inicial fixo de 8 códigos; lançamentos referenciam por FK (status_id).
 */

uses(RefreshDatabase::class);

it('seed cria exatamente os oito status do escopo', function () {
    $this->seed(StatusPagamentoSeeder::class);

    expect(StatusPagamento::pluck('codigo')->sort()->values()->all())
        ->toBe([
            'aberto',
            'agendado',
            'cancelado',
            'estornado',
            'pago',
            'pago_parcial',
            'pendente_revisao',
            'vencido',
        ]);
});

it('é idempotente: rodar o seed duas vezes não duplica', function () {
    $this->seed(StatusPagamentoSeeder::class);
    $this->seed(StatusPagamentoSeeder::class);

    expect(StatusPagamento::count())->toBe(8);
});

it('não permite código duplicado (unique)', function () {
    StatusPagamento::create(['codigo' => 'aberto', 'descricao' => 'Em aberto']);

    expect(fn () => StatusPagamento::create(['codigo' => 'aberto', 'descricao' => 'Outro']))
        ->toThrow(QueryException::class);
});

it('resolve o id pelo código', function () {
    $this->seed(StatusPagamentoSeeder::class);

    $id = StatusPagamento::idFor('pendente_revisao');

    expect($id)->toBe(StatusPagamento::where('codigo', 'pendente_revisao')->value('id'));
});
