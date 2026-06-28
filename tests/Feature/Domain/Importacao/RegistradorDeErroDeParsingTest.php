<?php

use App\Domain\Importacao\RegistradorDeErroDeParsing;
use App\Models\Bank;
use App\Models\PdfParseError;
use Database\Seeders\BankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * RegistradorDeErroDeParsing (spec 07 §6, C10): registra em pdf_parse_errors um trecho
 * que o parser não reconheceu, ligado ao banco (N:N), SEM nenhum dado sensível.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => (new BankSeeder)->run());

it('registra o erro ligado ao banco, sem dado sensível', function () {
    $erro = (new RegistradorDeErroDeParsing)->registrar(Bank::ITAU, 'layout de lançamento não reconhecido');

    expect($erro)->toBeInstanceOf(PdfParseError::class)
        ->and($erro->descricao_erro)->toBe('layout de lançamento não reconhecido')
        ->and($erro->banks->pluck('codigo')->all())->toBe([Bank::ITAU]);
});

it('não quebra quando o banco não está cadastrado (registra o erro solto)', function () {
    $erro = (new RegistradorDeErroDeParsing)->registrar('banco_inexistente', 'falha X');

    expect($erro->descricao_erro)->toBe('falha X')
        ->and($erro->banks)->toHaveCount(0);
});
