<?php

declare(strict_types=1);

namespace App\Domain\Receita;

use App\Models\AuditLog;
use App\Models\Income;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Remove uma receita por CANCELAMENTO LÓGICO (spec FE §7.10). Soft delete: a linha e o
 * histórico ficam (LGPD — auditoria preservada); a receita só some da listagem e da soma do mês.
 * Recupera ESCOPADA por usuário (findOrFail → 404 para item alheio) e audita antes de apagar.
 */
final class ExcluirReceita
{
    public function excluir(int $incomeId, int $userId, CarbonImmutable $agora): void
    {
        DB::transaction(function () use ($incomeId, $userId): void {
            /** @var Income $income */
            $income = Income::where('user_id', $userId)->lockForUpdate()->findOrFail($incomeId);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'income',
                'entidade_id' => $income->id,
                'acao' => AuditLog::ACAO_EXCLUIR,
                'antes' => [
                    'descricao' => $income->descricao,
                    'valor_cents' => $income->valor_cents,
                ],
                'depois' => null,
                'origem' => 'manual',
            ]);

            $income->delete(); // soft delete (SoftDeletes)
        });
    }
}
