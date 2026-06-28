<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

/**
 * Texto cru extraído de um PDF de fatura (spec 07 §6). Efêmero: vive só durante o
 * job e é descartado ao final (regra inviolável 6) — nunca persiste. `viaOcr`
 * indica se veio da camada de texto nativa (false) ou do fallback Tesseract (true).
 */
final class TextoExtraido
{
    public function __construct(
        public readonly string $conteudo,
        public readonly bool $viaOcr,
    ) {}

    public function vazio(): bool
    {
        return trim($this->conteudo) === '';
    }
}
