<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Domain\Shared\Normalizador;
use App\Models\MerchantAlias;

/**
 * Aprendizado por correção (doc 08 §2).
 *
 * A correção do usuário vira ou atualiza um alias de estabelecimento — incremental
 * e determinístico, sem treino de modelo. O alias é gravado normalizado e é único
 * por usuário, então corrigir o mesmo estabelecimento apenas atualiza a categoria.
 */
final class AprendizadoPorCorrecao
{
    public function corrigirEstabelecimento(int $userId, string $descricao, int $categoriaId): MerchantAlias
    {
        $alias = Normalizador::texto($descricao);

        return MerchantAlias::updateOrCreate(
            ['user_id' => $userId, 'alias' => $alias],
            ['category_id' => $categoriaId],
        );
    }
}
