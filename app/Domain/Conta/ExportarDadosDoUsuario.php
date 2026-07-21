<?php

declare(strict_types=1);

namespace App\Domain\Conta;

use App\Domain\Calendar\RelativeDate;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Card;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\Income;
use App\Models\Recurrence;
use App\Models\TelegramLink;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Portabilidade / acesso (LGPD art. 18). Reúne os dados estruturados APENAS do próprio user_id em
 * um array serializável. Só colunas explícitas e não sensíveis: nunca inclui senha, nem dado
 * sensível/efêmero (PDF/texto de fatura — regra 6, que sequer é retido). Auditável.
 */
final class ExportarDadosDoUsuario
{
    public function exportar(int $userId): array
    {
        /** @var User $user */
        $user = User::findOrFail($userId);

        $export = [
            'gerado_em' => CarbonImmutable::now(RelativeDate::TIMEZONE)->toIso8601String(),
            'cadastro' => [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'aceite_lgpd_em' => optional($user->aceite_lgpd_em)->toIso8601String(),
                'criado_em' => optional($user->created_at)->toIso8601String(),
            ],
            'lancamentos' => Transaction::where('user_id', $userId)
                ->with(['installments:id,transaction_id,numero,total,vencimento,data_pagamento,status_id'])
                ->get(['id', 'descricao', 'valor_total_cents', 'data_compra', 'payment_method_id', 'card_id', 'categoria_id', 'status_id', 'origem', 'moeda'])
                ->toArray(),
            'receitas' => Income::where('user_id', $userId)
                ->get(['id', 'descricao', 'valor_cents', 'data', 'tipo'])->toArray(),
            'cartoes' => Card::where('user_id', $userId)
                ->get(['id', 'descricao', 'final_4', 'limite_cents', 'dia_fechamento', 'dia_vencimento'])->toArray(),
            'categorias' => Category::where('user_id', $userId)
                ->get(['id', 'nome', 'cor', 'icone', 'arquivada'])->toArray(),
            'orcamentos' => Budget::where('user_id', $userId)
                ->get(['id', 'mes', 'limite_cents', 'categoria_id'])->toArray(),
            'recorrencias' => Recurrence::where('user_id', $userId)
                ->get(['id', 'descricao', 'valor_cents', 'payment_method_id', 'categoria_id', 'periodicidade', 'dia', 'status', 'proxima_em'])->toArray(),
            // Histórico do chat também é dado do titular (portabilidade completa,
            // auditoria P2-10) — o expurgo de 60 dias limita a janela retida.
            'chat' => ChatMessage::where('user_id', $userId)
                ->orderBy('created_at')
                ->get(['id', 'role', 'body', 'aprovado', 'tem_anexo', 'created_at'])->toArray(),
            'telegram' => $this->telegram($userId),
            'transparencia_ia' => 'A IA classifica seus gastos, extrai dados de faturas e redige as respostas. '
                .'A IA nunca calcula dinheiro — os números vêm do seu banco de dados.',
        ];

        AuditLog::create([
            'user_id' => $userId,
            'entidade' => 'user',
            'entidade_id' => $userId,
            'acao' => AuditLog::ACAO_EXPORTAR,
            'antes' => null,
            'depois' => null,
            'origem' => 'web',
        ]);

        return $export;
    }

    /** @return array{vinculado: bool, telefone: string|null} */
    private function telegram(int $userId): array
    {
        $ativo = TelegramLink::where('user_id', $userId)
            ->where('status', TelegramLink::ATIVO)
            ->first();

        if ($ativo === null) {
            return ['vinculado' => false, 'telefone' => null];
        }

        // Minimização (LGPD): só os últimos 4 dígitos, nunca o telefone completo.
        $digitos = preg_replace('/\D/', '', (string) $ativo->telefone) ?? '';

        return [
            'vinculado' => true,
            'telefone' => $digitos === '' ? null : '•••• '.substr($digitos, -4),
        ];
    }
}
