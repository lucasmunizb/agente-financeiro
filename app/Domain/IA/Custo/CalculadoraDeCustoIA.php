<?php

declare(strict_types=1);

namespace App\Domain\IA\Custo;

/**
 * Custo estimado de uma chamada de IA (doc 02 §3.6). Determinístico: tokens × preço da
 * tabela (centavos por 1.000.000 de tokens) → centavos inteiros (regra inviolável 5).
 *
 * É uma ESTIMATIVA de governança de custo — não é dinheiro do usuário, mas segue a mesma
 * regra de centavos inteiros. Provedor/modelo fora da tabela → 0 (nunca inventa custo).
 *
 * Tabela: `['provider' => ['model' => ['entrada' => cents/Mtok, 'saida' => cents/Mtok]]]`.
 */
final class CalculadoraDeCustoIA
{
    private const TOKENS_POR_UNIDADE = 1_000_000;

    /**
     * @param  array<string, array<string, array{entrada: int, saida: int}>>  $tabela
     */
    public function __construct(private readonly array $tabela) {}

    public function centavos(string $provider, string $model, int $tokensEntrada, int $tokensSaida): int
    {
        $preco = $this->tabela[$provider][$model] ?? null;

        if ($preco === null) {
            return 0;
        }

        $bruto = $tokensEntrada * $preco['entrada'] + $tokensSaida * $preco['saida'];

        return (int) round($bruto / self::TOKENS_POR_UNIDADE);
    }
}
