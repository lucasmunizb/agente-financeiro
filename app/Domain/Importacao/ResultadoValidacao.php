<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

/**
 * Resultado da validação do arquivo de fatura (spec 07 §6). Só metadados não
 * sensíveis: o hash do nome (para dedupe), se o PDF está protegido por senha (C2) e
 * se já houve importação do mesmo arquivo (C1, apenas aviso).
 */
final class ResultadoValidacao
{
    public function __construct(
        public readonly string $hashNome,
        public readonly bool $protegidoPorSenha,
        public readonly bool $jaImportado,
    ) {}

    /**
     * Pode seguir no pipeline? PDF com senha barra (C2); dedupe é só aviso (C1) e
     * o usuário decide prosseguir no frontend.
     */
    public function podeProsseguir(): bool
    {
        return ! $this->protegidoPorSenha;
    }
}
