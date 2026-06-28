<?php

use App\Models\Card;
use App\Models\InvoiceImport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * invoice_imports (doc 04 / spec 07 §5): controle da pré-importação de fatura.
 * SÓ metadados — hash do NOME do arquivo (não do conteúdo) + status próprio.
 * O PDF e o texto extraído NUNCA são persistidos (regra 6).
 */

uses(RefreshDatabase::class);

it('pertence a um usuário e a um cartão opcional', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $import = InvoiceImport::create([
        'user_id' => $user->id,
        'card_id' => $card->id,
        'hash_arquivo_nome' => hash('sha256', 'fatura-junho.pdf'),
        'status' => InvoiceImport::PENDENTE_REVISAO,
    ]);

    expect($import->user->id)->toBe($user->id)
        ->and($import->card->id)->toBe($card->id);
});

it('aceita importação sem cartão (card_id nulo)', function () {
    $user = User::factory()->create();

    $import = InvoiceImport::create([
        'user_id' => $user->id,
        'hash_arquivo_nome' => hash('sha256', 'fatura.pdf'),
        'status' => InvoiceImport::PENDENTE_REVISAO,
    ]);

    expect($import->card_id)->toBeNull();
});

it('só aceita status do conjunto da importação (CHECK)', function () {
    $user = User::factory()->create();

    foreach (InvoiceImport::STATUSES as $status) {
        $import = InvoiceImport::create([
            'user_id' => $user->id,
            'hash_arquivo_nome' => hash('sha256', "f-{$status}.pdf"),
            'status' => $status,
        ]);
        expect($import->status)->toBe($status);
    }

    InvoiceImport::create([
        'user_id' => $user->id,
        'hash_arquivo_nome' => hash('sha256', 'x.pdf'),
        'status' => 'inexistente',
    ]);
})->throws(QueryException::class);

it('permite consultar importação anterior pelo hash do nome, isolada por usuário (dedupe C1)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $hash = hash('sha256', 'fatura-junho.pdf');

    InvoiceImport::create([
        'user_id' => $userA->id,
        'hash_arquivo_nome' => $hash,
        'status' => InvoiceImport::PENDENTE_REVISAO,
    ]);

    $aTem = InvoiceImport::where('user_id', $userA->id)->where('hash_arquivo_nome', $hash)->exists();
    $bTem = InvoiceImport::where('user_id', $userB->id)->where('hash_arquivo_nome', $hash)->exists();

    expect($aTem)->toBeTrue()->and($bTem)->toBeFalse();
});

it('permite reimportar o mesmo arquivo (dedupe é aviso, não bloqueio rígido)', function () {
    $user = User::factory()->create();
    $hash = hash('sha256', 'fatura-junho.pdf');

    InvoiceImport::create(['user_id' => $user->id, 'hash_arquivo_nome' => $hash, 'status' => InvoiceImport::CONFIRMADA]);
    InvoiceImport::create(['user_id' => $user->id, 'hash_arquivo_nome' => $hash, 'status' => InvoiceImport::PENDENTE_REVISAO]);

    expect(InvoiceImport::where('user_id', $user->id)->where('hash_arquivo_nome', $hash)->count())->toBe(2);
});
