<?php

use App\Domain\Importacao\ResultadoValidacao;
use App\Domain\Importacao\ValidadorDeArquivo;
use App\Models\InvoiceImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * ValidadorDeArquivo (spec 07 §6, C1/C2): calcula o hash do NOME do arquivo, detecta
 * PDF protegido por senha (sem pedir a senha — LGPD) e checa dedupe contra
 * invoice_imports do usuário. Não abre conteúdo sensível.
 */

uses(RefreshDatabase::class);

function pdfTemp(string $conteudo): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'fatura_').'.pdf';
    file_put_contents($caminho, $conteudo);

    return $caminho;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/fatura_*') as $f) {
        @unlink($f);
    }
});

it('calcula um hash estável do nome do arquivo (não do conteúdo)', function () {
    $user = User::factory()->create();
    $validador = new ValidadorDeArquivo;

    $a = $validador->validar($user->id, 'Fatura Junho.pdf', pdfTemp('%PDF-1.4 conteudo A'));
    $b = $validador->validar($user->id, 'Fatura Junho.pdf', pdfTemp('%PDF-1.4 conteudo B totalmente diferente'));

    expect($a)->toBeInstanceOf(ResultadoValidacao::class)
        ->and($a->hashNome)->toBe($b->hashNome) // mesmo nome ⇒ mesmo hash, independe do conteúdo
        ->and($a->hashNome)->toHaveLength(64);
});

it('recusa PDF protegido por senha sem pedir a senha (C2)', function () {
    $user = User::factory()->create();

    $protegido = (new ValidadorDeArquivo)->validar($user->id, 'fatura.pdf', pdfTemp("%PDF-1.4\n/Encrypt 12 0 R\n..."));
    $aberto = (new ValidadorDeArquivo)->validar($user->id, 'fatura.pdf', pdfTemp("%PDF-1.4\nsem cofre\n..."));

    expect($protegido->protegidoPorSenha)->toBeTrue()
        ->and($protegido->podeProsseguir())->toBeFalse()
        ->and($aberto->protegidoPorSenha)->toBeFalse()
        ->and($aberto->podeProsseguir())->toBeTrue();
});

it('sinaliza dedupe quando já houve importação do mesmo nome, isolado por usuário (C1)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $validador = new ValidadorDeArquivo;

    $hash = $validador->validar($user->id, 'Fatura Junho.pdf', pdfTemp('%PDF-1.4'))->hashNome;
    InvoiceImport::create(['user_id' => $user->id, 'hash_arquivo_nome' => $hash, 'status' => InvoiceImport::CONFIRMADA]);

    $doDono = $validador->validar($user->id, 'Fatura Junho.pdf', pdfTemp('%PDF-1.4'));
    $deOutro = $validador->validar($outro->id, 'Fatura Junho.pdf', pdfTemp('%PDF-1.4'));

    expect($doDono->jaImportado)->toBeTrue()
        ->and($doDono->podeProsseguir())->toBeTrue() // dedupe é aviso, não bloqueio
        ->and($deOutro->jaImportado)->toBeFalse();
});
