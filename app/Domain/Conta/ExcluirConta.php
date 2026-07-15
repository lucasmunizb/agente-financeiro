<?php

declare(strict_types=1);

namespace App\Domain\Conta;

use App\Models\AuditLog;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Exclusão de conta (LGPD art. 18 — direito ao esquecimento). SOFT DELETE do usuário
 * (login bloqueado pelo escopo de SoftDeletes) + ANONIMIZAÇÃO dos identificadores do
 * titular (auditoria P2-10): nome, e-mail, senha e o vínculo Telegram (telefone/id)
 * não ficam retidos em claro por tempo indeterminado. Os registros financeiros
 * permanecem (auditoria/integridade), mas sem elo com uma pessoa identificável. A
 * trilha de auditoria é PRESERVADA e registra apenas o fato — sem reexpor PII.
 * O encerramento da sessão é responsabilidade da borda.
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

            // Anonimização: placeholder inequívoco e não colidível no lugar do e-mail
            // (a coluna é unique); senha aleatória inutiliza qualquer credencial vazada.
            $user->forceFill([
                'name' => null,
                'email' => "excluido-{$user->id}@anonimizado.invalid",
                'password' => Hash::make(Str::random(48)),
                'remember_token' => null,
            ])->save();

            // Vínculo Telegram: revoga e apaga telefone/id — sem finalidade após a
            // exclusão, não há base legal para reter (minimização).
            TelegramLink::where('user_id', $user->id)->update([
                'status' => TelegramLink::REVOGADO,
                'telefone' => null,
                'telegram_user_id' => null,
                'token_hash' => null,
            ]);

            $user->delete(); // soft delete (SoftDeletes)
        });
    }
}
