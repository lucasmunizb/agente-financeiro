<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

/**
 * Extrai a camada de texto nativa de um PDF de fatura (spec 07 §6). Se o PDF não tiver
 * texto selecionável (fatura como imagem), devolve {@see TextoExtraido::vazio()} e o
 * pipeline cai no {@see OcrFallback}. Efêmero: o texto nunca é persistido (regra 6).
 */
interface ExtratorDeTexto
{
    public function extrair(string $caminhoPdf): TextoExtraido;
}
