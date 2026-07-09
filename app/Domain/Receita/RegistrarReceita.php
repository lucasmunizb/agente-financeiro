<?php

declare(strict_types=1);

namespace App\Domain\Receita;

use App\Models\AuditLog;
use App\Models\Income;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra uma receita do usuário (spec FE §7.10). Escrita determinística: persiste os campos já
 * validados na borda (centavos, regra 5) e registra auditoria. A receita alimenta a soma do mês
 * ({@see ReceitasDoMes}) e, por ela, o "disponível do mês" (§4.5). Escopo por usuário.
 */
final class RegistrarReceita
{
    public function registrar(DadosReceita $dados, CarbonImmutable $agora): Income
    {
        return DB::transaction(function () use ($dados): Income {
            $income = Income::create([
                'user_id' => $dados->userId,
                'descricao' => $dados->descricao,
                'valor_cents' => $dados->valorCents,
                'data' => $dados->data->toDateString(),
                'tipo' => $dados->tipo,
            ]);

            AuditLog::create([
                'user_id' => $dados->userId,
                'entidade' => 'income',
                'entidade_id' => $income->id,
                'acao' => AuditLog::ACAO_CRIAR,
                'antes' => null,
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
