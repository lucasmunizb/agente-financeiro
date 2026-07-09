<?php

declare(strict_types=1);

namespace App\Domain\Cartao;

use App\Models\AuditLog;
use App\Models\Card;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra um cartão do usuário (spec FE §7.13). Escrita determinística: persiste os campos já
 * validados na borda e registra auditoria. A unicidade (usuário, final_4, descrição) entre
 * cartões ativos é garantida pelo índice parcial no banco; a borda valida antes para uma
 * mensagem amigável. Escopo por usuário.
 */
final class CriarCartao
{
    public function criar(DadosCartao $dados, CarbonImmutable $agora): Card
    {
        return DB::transaction(function () use ($dados): Card {
            $card = Card::create([
                'user_id' => $dados->userId,
                'descricao' => $dados->descricao,
                'final_4' => $dados->final4,
                'dia_fechamento' => $dados->diaFechamento,
                'dia_vencimento' => $dados->diaVencimento,
                'limite_cents' => $dados->limiteCents,
            ]);

            AuditLog::create([
                'user_id' => $dados->userId,
                'entidade' => 'card',
                'entidade_id' => $card->id,
                'acao' => AuditLog::ACAO_CRIAR,
                'antes' => null,
                'depois' => [
                    'descricao' => $card->descricao,
                    'final_4' => $card->final_4,
                    'dia_fechamento' => $card->dia_fechamento,
                    'dia_vencimento' => $card->dia_vencimento,
                ],
                'origem' => 'manual',
            ]);

            return $card;
        });
    }
}
