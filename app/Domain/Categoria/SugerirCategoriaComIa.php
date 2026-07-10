<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Ai\Agents\SugeridorDeCategoria;
use App\Domain\Shared\Normalizador;
use App\Models\Category;

/**
 * Sugestão de categoria via IA com GUARD determinístico (doc 02 / regra 4).
 *
 * Carrega as categorias ATIVAS do usuário (escopo estrito por user_id), pede ao
 * {@see SugeridorDeCategoria} UMA opção e resolve o nome de volta ao `category_id`. O guard é a
 * barreira anti-alucinação: só confia no id se o nome devolvido casar (texto normalizado — sem
 * acento/caixa) com uma categoria real e ativa daquele usuário. Qualquer nome inventado, de
 * terceiro ou arquivado → null. Sem categorias ativas, nem chama a IA.
 *
 * É a IA apenas CLASSIFICANDO (nunca calculando dinheiro). Usado só como fallback do lookup
 * determinístico ({@see ResolvedorDeCategoria}); a categoria resultante é pré-seleção a confirmar.
 */
final class SugerirCategoriaComIa
{
    public function __construct(
        private readonly SugeridorDeCategoria $agente,
    ) {}

    public function sugerir(int $userId, string $descricao): ?int
    {
        $categorias = Category::query()
            ->where('user_id', $userId)
            ->where('arquivada', false)
            ->get(['id', 'nome']);

        if ($categorias->isEmpty()) {
            return null;
        }

        $nome = $this->agente->sugerir($descricao, $categorias->pluck('nome')->all());
        if ($nome === null) {
            return null;
        }

        $alvo = Normalizador::texto($nome);
        if ($alvo === '') {
            return null;
        }

        $match = $categorias->first(
            fn (Category $c) => Normalizador::texto((string) $c->nome) === $alvo,
        );

        return $match !== null ? (int) $match->id : null;
    }
}
