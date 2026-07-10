<?php

declare(strict_types=1);

namespace App\Domain\Conta;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Atualiza o perfil do usuário (spec FE §7.17): nome, e-mail e fuso (preferência). Escrita
 * determinística — persiste os campos já validados na borda e registra auditoria SEM a senha
 * (nunca vai para a trilha). Escopo por usuário. O fuso não altera o motor (regra 5).
 */
final class AtualizarPerfil
{
    public function atualizar(DadosPerfil $dados): User
    {
        return DB::transaction(function () use ($dados): User {
            /** @var User $user */
            $user = User::findOrFail($dados->userId);

            $antes = [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
            ];

            $user->update([
                'name' => $dados->name,
                'email' => $dados->email,
                'timezone' => $dados->timezone,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'entidade' => 'user',
                'entidade_id' => $user->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => $antes,
                'depois' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'timezone' => $user->timezone,
                ],
                'origem' => 'web',
            ]);

            return $user;
        });
    }
}
