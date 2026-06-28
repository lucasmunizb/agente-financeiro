<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Importacao\ExtratorDeTexto;
use App\Domain\Importacao\MontadorDePreImportacao;
use App\Domain\Importacao\OcrFallback;
use App\Domain\Importacao\ParserDeFatura;
use App\Domain\Importacao\RegistradorDeErroDeParsing;
use App\Domain\Importacao\ResultadoValidacao;
use App\Domain\Importacao\ValidadorDeArquivo;
use App\Domain\Telegram\Resposta\RespostaAoUsuario;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Models\Bank;
use App\Models\InvoiceImport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Pipeline efêmero de importação de fatura, no worker, fora do request (spec 07 §6/§7).
 *
 * Orquestra: Validador (hash do nome, senha, dedupe) → Extrator de texto → OCR de
 * fallback → Parser do banco → MontadorDePreImportacao, e ENTREGA o resultado à porta de
 * saída {@see RespostaAoUsuario} (notificação de conclusão; a redação/envio é frontend,
 * regra 3). A REGRA de identificar os lançamentos (ParserDeFatura) é a única peça
 * pendente — o resto do pipeline já está pronto.
 *
 * Invariantes: o PDF e o texto extraído NUNCA são retidos — o arquivo temporário é
 * apagado SEMPRE, inclusive em erro (`finally`, regra 6). PDF com senha não é aberto
 * (C2). Erros de parsing viram registro não sensível em `pdf_parse_errors` (C10). Tudo
 * escopado por `user_id`. O job não recebe nem grava dado sensível.
 */
final class ImportarFaturaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $nomeArquivo,
        public readonly string $caminhoPdf,
        public readonly ?int $cardId = null,
        public readonly string $codigoBanco = Bank::ITAU,
    ) {}

    public function handle(
        ValidadorDeArquivo $validador,
        ExtratorDeTexto $extrator,
        OcrFallback $ocr,
        ParserDeFatura $parser,
        MontadorDePreImportacao $montador,
        RegistradorDeErroDeParsing $registradorErro,
        RespostaAoUsuario $saida,
    ): void {
        $user = User::findOrFail($this->userId);

        try {
            $validacao = $validador->validar($this->userId, $this->nomeArquivo, $this->caminhoPdf);

            // C2: PDF com senha — não abrimos o conteúdo nem persistimos nada; pedimos
            // uma versão sem senha (a redação amigável é frontend).
            if (! $validacao->podeProsseguir()) {
                $saida->entregar($user, ResultadoDaInteracao::importacaoProtegidaPorSenha());

                return;
            }

            // Camada de texto nativa; se vazia (fatura como imagem), cai no OCR (C3/C4).
            $texto = $extrator->extrair($this->caminhoPdf);
            if ($texto->vazio()) {
                $texto = $ocr->ocr($this->caminhoPdf);
            }

            try {
                $lancamentos = $parser->interpretar($texto);
            } catch (Throwable $e) {
                $this->registrarFalha($validacao, $registradorErro, $saida, $user, $e);

                return;
            }

            $import = InvoiceImport::create([
                'user_id' => $this->userId,
                'card_id' => $this->cardId,
                'hash_arquivo_nome' => $validacao->hashNome,
                'status' => InvoiceImport::PENDENTE_REVISAO,
            ]);

            $pre = $montador->montar($this->userId, $import->id, $lancamentos);

            $saida->entregar($user, ResultadoDaInteracao::importacaoPronta($pre));
        } finally {
            // Regra 6: o PDF e o texto nunca são retidos — descarta SEMPRE, inclusive em erro.
            if (is_file($this->caminhoPdf)) {
                @unlink($this->caminhoPdf);
            }
        }
    }

    /**
     * C10: registra a falha de parsing (descrição NÃO sensível — só a classe do erro,
     * nunca o trecho do PDF), marca a importação como `erro` e notifica.
     */
    private function registrarFalha(
        ResultadoValidacao $validacao,
        RegistradorDeErroDeParsing $registradorErro,
        RespostaAoUsuario $saida,
        User $user,
        Throwable $e,
    ): void {
        $registradorErro->registrar($this->codigoBanco, 'Falha ao interpretar a fatura ('.class_basename($e).')');

        InvoiceImport::create([
            'user_id' => $this->userId,
            'card_id' => $this->cardId,
            'hash_arquivo_nome' => $validacao->hashNome,
            'status' => InvoiceImport::ERRO,
        ]);

        $saida->entregar($user, ResultadoDaInteracao::importacaoFalhou());
    }
}
