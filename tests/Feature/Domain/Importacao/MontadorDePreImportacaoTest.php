<?php

use App\Domain\Importacao\LancamentoExtraido;
use App\Domain\Importacao\MontadorDePreImportacao;
use App\Domain\Importacao\PreImportacao;
use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\InvoiceImport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * MontadorDePreImportacao (spec 07 §6, C6): monta a PreImportacao inerte —
 * marca duplicados (reuso do detector) e sugere categoria (lookup determinístico),
 * status pendente_revisao. NÃO persiste nenhum lançamento (inerte até confirmação).
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]));

it('monta pré-importação pendente_revisao, sugere categoria e não persiste nada (C6)', function () {
    $user = User::factory()->create();
    $categoria = Category::create(['user_id' => $user->id, 'nome' => 'Alimentação']);
    CategoryKeyword::create(['category_id' => $categoria->id, 'palavra_chave' => 'padaria']);

    $data = CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo');
    $pre = (new MontadorDePreImportacao)->montar($user->id, importId: 99, lancamentos: [
        new LancamentoExtraido('Padaria do Zé', 1500, $data, 1),
        new LancamentoExtraido('Posto Shell', 20000, $data, 1),
    ]);

    expect($pre)->toBeInstanceOf(PreImportacao::class)
        ->and($pre->status)->toBe(InvoiceImport::PENDENTE_REVISAO)
        ->and($pre->importId)->toBe(99)
        ->and($pre->itens)->toHaveCount(2)
        ->and($pre->itens[0]->categoriaIdSugerida)->toBe($categoria->id) // "padaria" casa
        ->and($pre->itens[1]->categoriaIdSugerida)->toBeNull()
        ->and(Transaction::count())->toBe(0); // inerte: nada gravado
});
