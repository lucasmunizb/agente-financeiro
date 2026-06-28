<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use Symfony\Component\Process\Process;

/**
 * Implementação do {@see ExtratorDeTexto} via `pdftotext` (poppler-utils, embutido no
 * worker — spec 00). Lê a camada de texto nativa para stdout; em falha ou ausência de
 * texto devolve vazio, sinalizando ao pipeline para acionar o OCR. Não persiste nada.
 */
final class ExtratorDeTextoPoppler implements ExtratorDeTexto
{
    public function extrair(string $caminhoPdf): TextoExtraido
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', '-q', $caminhoPdf, '-']);
        $process->run();

        $texto = $process->isSuccessful() ? $process->getOutput() : '';

        return new TextoExtraido($texto, viaOcr: false);
    }
}
