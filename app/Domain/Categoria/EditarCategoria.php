<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Domain\Categoria\Concerns\SincronizaRegras;
use App\Models\AuditLog;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edita uma categoria do usuário (spec FE §7.12): nome, cor, ícone e as regras do lookup
 * (palavras-chave + apelidos). Recupera ESCOPADO por usuário (findOrFail → 404 para item alheio),
 * re-sincroniza as regras ({@see SincronizaRegras}, gravadas normalizadas) e audita o antes/depois.
 */
final class EditarCategoria
{
    use SincronizaRegras;

    public function editar(int $categoriaId, DadosCategoria $dados, CarbonImmutable $agora): Category
    {
        return DB::transaction(function () use ($categoriaId, $dados): Category {
            /** @var Category $categoria */
            $categoria = Category::where('user_id', $dados->userId)->lockForUpdate()->findOrFail($categoriaId);

            $antes = ['nome' => $categoria->nome, 'cor' => $categoria->cor, 'icone' => $categoria->icone];

            $categoria->update([
                'nome' => $dados->nome,
                'cor' => $dados->cor,
                'icone' => $dados->icone,
            ]);

            $this->sincronizarPalavras($categoria, $dados->palavrasChave);
            $this->sincronizarApelidos($categoria, $dados->apelidos);

            AuditLog::create([
                'user_id' => $dados->userId,
                'entidade' => 'category',
                'entidade_id' => $categoria->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => $antes,
                'depois' => ['nome' => $categoria->nome, 'cor' => $categoria->cor, 'icone' => $categoria->icone],
                'origem' => 'manual',
            ]);

            return $categoria;
        });
    }
}
