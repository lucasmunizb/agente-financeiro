<?php

declare(strict_types=1);

namespace App\Domain\Conta;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Altera a senha do usuário (spec FE §7.17). A senha em claro NUNCA toca a trilha: o cast
 * 'hashed' do model gera o hash e a auditoria registra apenas o FATO da troca. A verificação
 * da senha atual acontece na borda (Form Request). Escopo por usuário.
 */
final class AlterarSenha
{
    public function alterar(int $userId, string $novaSenha): void
    {
        DB::transaction(function () use ($userId, $novaSenha): void {
            /** @var User $user */
            $user = User::findOrFail($userId);

            $user->update(['password' => $novaSenha]); // cast 'hashed' aplica o hash

            AuditLog::create([
                'user_id' => $user->id,
                'entidade' => 'user',
                'entidade_id' => $user->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => null,
                'depois' => ['senha' => 'alterada'],
                'origem' => 'web',
            ]);
        });
    }
}
