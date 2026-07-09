<?php

declare(strict_types=1);

namespace App\Domain\Receita;

use App\Models\AuditLog;
use App\Models\Income;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edita uma receita do usuário (spec FE §7.10): descrição, valor, tipo e data. Recupera
 * ESCOPADA por usuário (findOrFail → 404 para item alheio) e audita o antes/depois. Centavos
 * (regra 5). A soma do mês ({@see ReceitasDoMes}) reflete a mudança automaticamente.
 */
final class EditarReceita
{
    public function editar(int $incomeId, DadosReceita $dados, CarbonImmutable $agora): Income
    {
        return DB::transaction(function () use ($incomeId, $dados): Income {
            /** @var Income $income */
            $income = Income::where('user_id', $dados->userId)->lockForUpdate()->findOrFail($incomeId);

            $antes = [
                'descricao' => $income->descricao,
                'valor_cents' => $income->valor_cents,
                'data' => $income->data->toDateString(),
                'tipo' => $income->tipo,
            ];

            $income->update([
                'descricao' => $dados->descricao,
                'valor_cents' => $dados->valorCents,
                'data' => $dados->data->toDateString(),
                'tipo' => $dados->tipo,
            ]);

            AuditLog::create([
                'user_id' => $dados->userId,
                'entidade' => 'income',
                'entidade_id' => $income->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => $antes,
                'depois' => [
                    'descricao' => $income->descricao,
                    'valor_cents' => $income->valor_cents,
                    'data' => $income->data->toDateString(),
                    'tipo' => $income->tipo,
                ],
                'origem' => 'manual',
            ]);

            return $income;
        });
    }
}
