<?php

declare(strict_types=1);

namespace App\Domain\Conta;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Exclusão de conta (LGPD art. 18 — direito ao esquecimento). Decisão do usuário: SOFT DELETE do
 * usuário (login bloqueado pelo escopo de SoftDeletes); os dados ficam intactos porém inacessíveis
 * (todo acesso é escopado por user_id). A trilha de auditoria é PRESERVADA e registra apenas o
 * fato — sem reexpor PII em claro. O encerramento da sessão é responsabilidade da borda.
 */
final class ExcluirConta
{
    public function excluir(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            /** @var User $user */
            $user = User::findOrFail($userId);

            AuditLog::create([
                'user_id' => $user->id,
                'entidade' => 'user',
                'entidade_id' => $user->id,
                'acao' => AuditLog::ACAO_EXCLUIR,
                'antes' => null,
                'depois' => null,
                'origem' => 'web',
            ]);

            $user->delete(); // soft delete (SoftDeletes)
        });
    }
}
