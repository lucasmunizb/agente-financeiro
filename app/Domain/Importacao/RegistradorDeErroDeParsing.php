<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use App\Models\Bank;
use App\Models\PdfParseError;

/**
 * Registra trechos que o parser não reconheceu (spec 07 §6, C10) para evoluir o
 * parser. Liga o erro ao banco (N:N) quando ele existe. NUNCA recebe/grava o trecho
 * do PDF nem dado sensível — só uma descrição não sensível do erro.
 */
final class RegistradorDeErroDeParsing
{
    public function registrar(string $codigoBanco, string $descricaoErro): PdfParseError
    {
        $erro = PdfParseError::create(['descricao_erro' => $descricaoErro]);

        $bank = Bank::where('codigo', $codigoBanco)->first();
        if ($bank !== null) {
            $erro->banks()->attach($bank->id);
        }

        return $erro->load('banks');
    }
}
