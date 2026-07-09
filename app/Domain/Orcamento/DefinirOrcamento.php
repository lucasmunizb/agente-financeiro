<?php

declare(strict_types=1);

namespace App\Domain\Orcamento;

use App\Models\AuditLog;
use App\Models\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Define o orçamento GERAL do mês (doc 08 §6). Escrita determinística: um limite por
 * (usuário, mês), categoria_id nulo — o por-categoria é pós-MVP (a coluna já existe). Usa
 * updateOrCreate: redefinir o mesmo mês ATUALIZA a mesma linha (nunca duplica; casa com o
 * índice único parcial). Limite em centavos inteiros (regra 5). Registra auditoria (criar/editar).
 */
final class DefinirOrcamento
{
    public function definir(int $userId, string $mes, int $limiteCents, CarbonImmutable $agora): Budget
    {
        return DB::transaction(function () use ($userId, $mes, $limiteCents): Budget {
            $budget = Budget::updateOrCreate(
                ['user_id' => $userId, 'mes' => $mes, 'categoria_id' => null],
                ['limite_cents' => $limiteCents],
            );

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'budget',
                'entidade_id' => $budget->id,
                'acao' => $budget->wasRecentlyCreated ? AuditLog::ACAO_CRIAR : AuditLog::ACAO_EDITAR,
                'antes' => null,
                'depois' => ['mes' => $mes, 'limite_cents' => $limiteCents],
                'origem' => 'manual',
            ]);

            return $budget;
        });
    }
}
