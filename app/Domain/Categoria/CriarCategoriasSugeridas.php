<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Models\Category;

/**
 * Cria as categorias sugeridas de um usuário novo (doc 08 §5). Idempotente: usa
 * firstOrCreate por (user_id, nome), então rodar de novo não duplica nem estoura o
 * índice único — serve tanto para o cadastro quanto para backfill de contas antigas.
 *
 * Cor e ícone saem do design system (tokens "caderno de contas"); o ícone casa com o
 * conjunto SVG inline do frontend (cai em "tag" quando não há correspondência).
 */
final class CriarCategoriasSugeridas
{
    /** @var array<string, array{cor: string, icone: string}> nome => cor/ícone sugeridos (doc 08 §5). */
    private const SUGERIDAS = [
        'Alimentação' => ['cor' => '#1F6E5A', 'icone' => 'food'],
        'Apostas' => ['cor' => '#B4452F', 'icone' => 'tag'],
        'Futebol' => ['cor' => '#2E8B72', 'icone' => 'tag'],
        'Moradia' => ['cor' => '#875300', 'icone' => 'home'],
        'Transporte' => ['cor' => '#005543', 'icone' => 'car'],
        'Saúde' => ['cor' => '#8A2714', 'icone' => 'tag'],
        'Lazer' => ['cor' => '#C9852A', 'icone' => 'tag'],
        'Educação' => ['cor' => '#1F6E5A', 'icone' => 'tag'],
        'Assinaturas' => ['cor' => '#6B6F66', 'icone' => 'tag'],
        'Cartão/Taxas' => ['cor' => '#B4452F', 'icone' => 'tag'],
        'Outros' => ['cor' => '#6B6F66', 'icone' => 'tag'],
    ];

    public function para(int $userId): void
    {
        foreach (self::SUGERIDAS as $nome => $estilo) {
            Category::firstOrCreate(
                ['user_id' => $userId, 'nome' => $nome],
                ['cor' => $estilo['cor'], 'icone' => $estilo['icone'], 'arquivada' => false],
            );
        }
    }
}
