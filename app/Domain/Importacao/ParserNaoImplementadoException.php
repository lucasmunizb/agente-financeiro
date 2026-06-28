<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use RuntimeException;

/**
 * Sinaliza que a regra de extração de lançamentos de um banco ainda não foi escrita.
 * Usada pelo {@see ParserItau} enquanto a identificação dos itens da fatura está
 * deliberadamente pendente (a ser feita após o frontend). O job trata como erro de
 * parsing — registra em `pdf_parse_errors` e marca a importação como `erro`, sem
 * vazar dado sensível.
 */
final class ParserNaoImplementadoException extends RuntimeException
{
    public static function paraBanco(string $banco): self
    {
        return new self("Parser de fatura do banco '{$banco}' ainda não implementado.");
    }
}
