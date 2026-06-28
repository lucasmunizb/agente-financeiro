<?php

use App\Domain\Importacao\ExtratorDeTexto;
use App\Domain\Importacao\LancamentoExtraido;
use App\Domain\Importacao\OcrFallback;
use App\Domain\Importacao\ParserDeFatura;
use App\Domain\Importacao\ParserNaoImplementadoException;
use App\Domain\Importacao\TextoExtraido;
use App\Domain\Telegram\Resposta\RespostaAoUsuario;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Domain\Telegram\Resposta\TipoDeInteracao;
use App\Jobs\ImportarFaturaJob;
use App\Models\Bank;
use App\Models\InvoiceImport;
use App\Models\PdfParseError;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BankSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * ImportarFaturaJob (spec 07 §6/§7): pipeline efêmero no worker. Orquestra
 * Validador → Extrator/OCR → Parser → PreImportacao e ENTREGA o resultado à porta de
 * saída (notificação de conclusão; redação é frontend). O PDF/texto NUNCA são retidos:
 * o arquivo temporário é apagado SEMPRE, inclusive em erro (regra 6). Testado com
 * extrator/OCR/parser FAKE (sem binários, sem PDF real); a regra do ParserItau fica
 * pendente para depois do frontend.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class, BankSeeder::class]);
    $this->saida = Mockery::spy(RespostaAoUsuario::class);
    app()->instance(RespostaAoUsuario::class, $this->saida);
});

function pdfTempJob(string $conteudo = '%PDF-1.4 conteudo'): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'jobfatura_').'.pdf';
    file_put_contents($caminho, $conteudo);

    return $caminho;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/jobfatura_*') as $f) {
        @unlink($f);
    }
});

/** Extrator fake que devolve um texto fixo (viaOcr=false). */
function fakeExtrator(string $texto): void
{
    app()->instance(ExtratorDeTexto::class, new class($texto) implements ExtratorDeTexto
    {
        public function __construct(private string $texto) {}

        public function extrair(string $caminhoPdf): TextoExtraido
        {
            return new TextoExtraido($this->texto, viaOcr: false);
        }
    });
}

/** Parser fake que devolve um lançamento fixo. */
function fakeParser(): void
{
    app()->instance(ParserDeFatura::class, new class implements ParserDeFatura
    {
        public function interpretar(TextoExtraido $texto): array
        {
            return [new LancamentoExtraido('Mercado', 5000, CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'), 1)];
        }
    });
}

it('C3 — texto nativo: extrai, monta a pré-importação e NÃO aciona OCR', function () {
    $user = User::factory()->create();
    fakeExtrator('LANC MERCADO 50,00'); // não vazio ⇒ sem OCR
    fakeParser();
    $ocr = Mockery::spy(OcrFallback::class);
    app()->instance(OcrFallback::class, $ocr);
    $pdf = pdfTempJob();

    ImportarFaturaJob::dispatchSync($user->id, 'fatura-junho.pdf', $pdf, null, Bank::ITAU);

    $ocr->shouldNotHaveReceived('ocr');
    expect(InvoiceImport::where('user_id', $user->id)->where('status', InvoiceImport::PENDENTE_REVISAO)->count())->toBe(1)
        ->and(is_file($pdf))->toBeFalse(); // descarte (C9, regra 6)

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $u->is($user)
            && $r->tipo === TipoDeInteracao::IMPORTACAO_PRONTA
            && $r->preImportacao->itens[0]->lancamento->descricao === 'Mercado',
    );
});

it('C4 — sem texto selecionável: aciona o OCR e segue', function () {
    $user = User::factory()->create();
    fakeExtrator('   '); // vazio ⇒ cai no OCR
    fakeParser();
    app()->instance(OcrFallback::class, new class implements OcrFallback
    {
        public function ocr(string $caminhoPdf): TextoExtraido
        {
            return new TextoExtraido('texto via ocr', viaOcr: true);
        }
    });
    $pdf = pdfTempJob();

    ImportarFaturaJob::dispatchSync($user->id, 'fatura.pdf', $pdf, null, Bank::ITAU);

    expect(InvoiceImport::where('user_id', $user->id)->where('status', InvoiceImport::PENDENTE_REVISAO)->count())->toBe(1);
    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::IMPORTACAO_PRONTA,
    );
});

it('C2 — PDF com senha: não persiste nada e pede versão sem senha', function () {
    $user = User::factory()->create();
    fakeExtrator('nunca chega aqui');
    fakeParser();
    $pdf = pdfTempJob("%PDF-1.4\n/Encrypt 12 0 R");

    ImportarFaturaJob::dispatchSync($user->id, 'fatura.pdf', $pdf, null, Bank::ITAU);

    expect(InvoiceImport::count())->toBe(0)   // nada persistido
        ->and(is_file($pdf))->toBeFalse();    // mesmo assim descarta o arquivo

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::IMPORTACAO_PROTEGIDA_POR_SENHA,
    );
});

it('C9/C10 — erro de parsing: registra em pdf_parse_errors, marca erro e DESCARTA o arquivo', function () {
    $user = User::factory()->create();
    fakeExtrator('texto qualquer');
    app()->instance(ParserDeFatura::class, new class implements ParserDeFatura
    {
        public function interpretar(TextoExtraido $texto): array
        {
            throw ParserNaoImplementadoException::paraBanco(Bank::ITAU);
        }
    });
    $pdf = pdfTempJob();

    ImportarFaturaJob::dispatchSync($user->id, 'fatura.pdf', $pdf, null, Bank::ITAU);

    expect(is_file($pdf))->toBeFalse()                       // descarte mesmo em erro (regra 6)
        ->and(Transaction::count())->toBe(0)                 // nada efetivado
        ->and(InvoiceImport::where('status', InvoiceImport::ERRO)->count())->toBe(1)
        ->and(PdfParseError::count())->toBe(1)
        ->and(PdfParseError::first()->banks->pluck('codigo')->all())->toBe([Bank::ITAU]);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::IMPORTACAO_FALHOU,
    );
});

it('registra o erro de parsing sem vazar conteúdo do PDF (regra 6)', function () {
    $user = User::factory()->create();
    fakeExtrator('NUMERO DO CARTAO 1234 NOME DO TITULAR CPF 000');
    app()->instance(ParserDeFatura::class, new class implements ParserDeFatura
    {
        public function interpretar(TextoExtraido $texto): array
        {
            throw ParserNaoImplementadoException::paraBanco(Bank::ITAU);
        }
    });

    ImportarFaturaJob::dispatchSync($user->id, 'fatura.pdf', pdfTempJob(), null, Bank::ITAU);

    $descricao = PdfParseError::first()->descricao_erro;
    expect($descricao)->not->toContain('CPF')
        ->and($descricao)->not->toContain('TITULAR')
        ->and($descricao)->not->toContain('1234');
});
