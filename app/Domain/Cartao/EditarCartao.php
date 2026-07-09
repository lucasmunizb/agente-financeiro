<?php

declare(strict_types=1);

namespace App\Domain\Cartao;

use App\Models\AuditLog;
use App\Models\Card;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edita um cartão do usuário (spec FE §7.13): descrição, 4 dígitos finais, dias de ciclo e
 * limite. Recupera ESCOPADO por usuário (findOrFail → 404 para item alheio) e audita o
 * antes/depois. Centavos (regra 5). A unicidade (usuário, final_4, descrição) entre ativos é
 * garantida pelo índice do banco; a borda valida antes (ignorando o próprio cartão).
 */
final class EditarCartao
{
    public function editar(int $cardId, DadosCartao $dados, CarbonImmutable $agora): Card
    {
        return DB::transaction(function () use ($cardId, $dados): Card {
            /** @var Card $card */
            $card = Card::where('user_id', $dados->userId)->lockForUpdate()->findOrFail($cardId);

            $antes = [
                'descricao' => $card->descricao,
                'final_4' => $card->final_4,
                'dia_fechamento' => $card->dia_fechamento,
                'dia_vencimento' => $card->dia_vencimento,
                'limite_cents' => $card->limite_cents,
            ];

            $card->update([
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
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => $antes,
                'depois' => [
                    'descricao' => $card->descricao,
                    'final_4' => $card->final_4,
                    'dia_fechamento' => $card->dia_fechamento,
                    'dia_vencimento' => $card->dia_vencimento,
                    'limite_cents' => $card->limite_cents,
                ],
                'origem' => 'manual',
            ]);

            return $card;
        });
    }
}
