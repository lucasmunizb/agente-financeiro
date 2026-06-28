<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use Symfony\Component\Process\Process;

/**
 * Implementação do {@see OcrFallback} via poppler + Tesseract (pt), ambos embutidos no
 * worker (spec 00). Rasteriza as páginas do PDF (`pdftoppm`) num diretório temporário,
 * roda o `tesseract` em cada imagem e concatena o texto. Tudo é efêmero: as imagens e o
 * texto vivem só durante a chamada e são apagados ao final (regra 6).
 */
final class OcrTesseract implements OcrFallback
{
    public function ocr(string $caminhoPdf): TextoExtraido
    {
        $dir = sys_get_temp_dir().'/ocr_'.bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        try {
            // Rasteriza: gera {dir}/pagina-1.png, pagina-2.png, ...
            (new Process(['pdftoppm', '-png', '-r', '300', $caminhoPdf, $dir.'/pagina']))->run();

            $texto = '';
            foreach (glob($dir.'/pagina*.png') ?: [] as $imagem) {
                $process = new Process(['tesseract', $imagem, 'stdout', '-l', 'por']);
                $process->run();
                if ($process->isSuccessful()) {
                    $texto .= $process->getOutput()."\n";
                }
            }

            return new TextoExtraido($texto, viaOcr: true);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
