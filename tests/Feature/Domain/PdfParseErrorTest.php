<?php

use App\Models\Bank;
use App\Models\PdfParseError;
use Database\Seeders\BankSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * banks + pdf_parse_errors (doc 04 / spec 07 §5): registro de trechos que o parser
 * não reconheceu, para evoluir o parser. Relação N:N banco↔erro. SEM trecho sensível
 * do PDF — só a descrição não sensível do erro.
 */

uses(RefreshDatabase::class);

it('semeia o banco Itaú de forma idempotente', function () {
    (new BankSeeder)->run();
    (new BankSeeder)->run();

    expect(Bank::where('codigo', Bank::ITAU)->count())->toBe(1);
});

it('exige código de banco único', function () {
    Bank::create(['codigo' => Bank::ITAU, 'nome' => 'Itaú']);
    Bank::create(['codigo' => Bank::ITAU, 'nome' => 'Itaú 2']);
})->throws(QueryException::class);

it('relaciona erro de parsing a banco(s) em N:N', function () {
    $itau = Bank::create(['codigo' => Bank::ITAU, 'nome' => 'Itaú']);
    $outro = Bank::create(['codigo' => 'nubank', 'nome' => 'Nubank']);

    $erro = PdfParseError::create(['descricao_erro' => 'linha de lançamento não reconhecida: layout 2-colunas']);
    $erro->banks()->attach([$itau->id, $outro->id]);

    expect($erro->banks)->toHaveCount(2)
        ->and($itau->fresh()->pdfParseErrors)->toHaveCount(1);
});
