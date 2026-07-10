<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

/**
 * Resolve a categoria de um gasto pela descrição, com precedência (doc 08 §1 + fallback de IA).
 *
 * 1º) Lookup determinístico ({@see LookupDeCategoria}): aliases/keywords que o usuário treinou
 *     por correção — barato, instantâneo, de maior confiança. Se casar, é a resposta.
 * 2º) Só quando o lookup não classifica, cai na IA ({@see SugerirCategoriaComIa}), que escolhe
 *     uma das categorias do usuário sob guard anti-alucinação. O resultado vem MARCADO como
 *     `sugeridaPorIa` — pré-seleção a confirmar, não classificação assentada.
 *
 * Sem lookup e sem sugestão → nenhuma categoria (null; a confirmação segue sem categoria).
 */
final class ResolvedorDeCategoria
{
    public function __construct(
        private readonly LookupDeCategoria $lookup,
        private readonly SugerirCategoriaComIa $ia,
    ) {}

    public function para(int $userId, string $descricao): CategoriaResolvida
    {
        $determinada = $this->lookup->para($userId, $descricao);
        if ($determinada !== null) {
            return new CategoriaResolvida($determinada, sugeridaPorIa: false);
        }

        $sugerida = $this->ia->sugerir($userId, $descricao);

        return new CategoriaResolvida($sugerida, sugeridaPorIa: $sugerida !== null);
    }
}
