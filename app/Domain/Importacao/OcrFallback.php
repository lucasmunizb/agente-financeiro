<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

/**
 * Fallback de OCR (spec 07 §6, C4): quando o PDF não tem camada de texto, transforma
 * as páginas em texto via Tesseract (pt). Efêmero: nada é persistido (regra 6).
 */
interface OcrFallback
{
    public function ocr(string $caminhoPdf): TextoExtraido;
}
