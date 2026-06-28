<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use App\Models\InvoiceImport;

/**
 * Validação do arquivo de fatura na entrada do pipeline (spec 07 §6, C1/C2).
 *
 * Calcula o hash do NOME do arquivo (não do conteúdo) para deduplicação, detecta PDF
 * protegido por senha SEM nunca pedir/usar a senha (LGPD) e checa se já houve
 * importação do mesmo nome para este usuário. Não extrai nem loga conteúdo sensível.
 */
final class ValidadorDeArquivo
{
    public function validar(int $userId, string $nomeArquivo, string $caminhoPdf): ResultadoValidacao
    {
        $hashNome = $this->hashDoNome($nomeArquivo);

        return new ResultadoValidacao(
            hashNome: $hashNome,
            protegidoPorSenha: $this->protegidoPorSenha($caminhoPdf),
            jaImportado: InvoiceImport::query()
                ->where('user_id', $userId)
                ->where('hash_arquivo_nome', $hashNome)
                ->exists(),
        );
    }

    /**
     * Hash estável do nome normalizado (minúsculas, sem espaços nas bordas). Depende
     * só do nome — o conteúdo do PDF nunca entra no hash.
     */
    private function hashDoNome(string $nomeArquivo): string
    {
        return hash('sha256', mb_strtolower(trim($nomeArquivo)));
    }

    /**
     * Detecta o dicionário de criptografia do PDF (`/Encrypt`) sem tentar abrir o
     * conteúdo. Heurística determinística sobre os bytes do arquivo.
     */
    private function protegidoPorSenha(string $caminhoPdf): bool
    {
        $bytes = @file_get_contents($caminhoPdf);

        return $bytes !== false && str_contains($bytes, '/Encrypt');
    }
}
