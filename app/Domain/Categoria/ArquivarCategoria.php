<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

use App\Models\AuditLog;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Arquiva uma categoria (spec FE §7.12). Arquivar é LÓGICO, não exclusão: a linha, o histórico de
 * lançamentos e as regras do lookup permanecem — a categoria só sai da grade de ativas e deixa de
 * participar da classificação (o lookup já filtra `arquivada = false`). Recupera ESCOPADO por
 * usuário (findOrFail → 404 para item alheio) e audita. Idempotente (arquivar de novo não muda nada).
 */
final class ArquivarCategoria
{
    public function arquivar(int $categoriaId, int $userId, CarbonImmutable $agora): Category
    {
        return DB::transaction(function () use ($categoriaId, $userId): Category {
            /** @var Category $categoria */
            $categoria = Category::where('user_id', $userId)->lockForUpdate()->findOrFail($categoriaId);

            if ($categoria->arquivada) {
                return $categoria;
            }

            $categoria->update(['arquivada' => true]);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'category',
                'entidade_id' => $categoria->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => ['arquivada' => false],
                'depois' => ['arquivada' => true],
                'origem' => 'manual',
            ]);

            return $categoria;
        });
    }
}
