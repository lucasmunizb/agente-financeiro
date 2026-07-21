<?php

declare(strict_types=1);

namespace App\Domain\Pagamento;

use App\Models\TelegramPendingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Estado do "paguei a luz" entre mensagens do bot — o terceiro `tipo` da fila compartilhada
 * `telegram_pending_confirmations` (ao lado de `confirmacao` e `esclarecimento`).
 *
 * Guarda os CANDIDATOS já resolvidos pelo domínio, nunca o texto do usuário: quando ele
 * responder "sim", a conta a quitar é exatamente a que lhe foi mostrada — não uma nova busca
 * que poderia trazer outra coisa. Com mais de um candidato, o pendente sobrevive até ele
 * escolher pelo número; a partir daí resta um só e vale o sim/não (regra 7).
 *
 * TTL de 15 min e UM pendente por usuário, como as demais filas. Escopo estrito por
 * `user_id`; "agora" é injetado (determinismo).
 */
final class PagamentosPendentes
{
    public const TTL_MINUTOS = 15;

    /** Discriminador da FILA (coluna `tipo`), não do payload. */
    public const TIPO = 'pagamento';

    /**
     * @param  list<ContaPagavel>  $candidatos
     */
    public function guardar(int $userId, array $candidatos, CarbonImmutable $agora): void
    {
        TelegramPendingConfirmation::updateOrCreate(
            ['user_id' => $userId],
            [
                'tipo' => self::TIPO,
                'token' => (string) Str::uuid(),
                'payload' => [
                    'candidatos' => array_map(static fn (ContaPagavel $c): array => $c->paraArray(), $candidatos),
                ],
                'expira_em' => $agora->addMinutes(self::TTL_MINUTOS)->setTimezone('UTC'),
            ],
        );
    }

    /**
     * @return list<ContaPagavel> vazio quando não há pendente vivo
     */
    public function recuperar(int $userId, CarbonImmutable $agora): array
    {
        $pendente = TelegramPendingConfirmation::query()
            ->where('user_id', $userId)
            ->where('tipo', self::TIPO)
            ->where('expira_em', '>=', $agora->setTimezone('UTC'))
            ->first();

        if ($pendente === null) {
            return [];
        }

        return array_map(
            static fn (array $dados): ContaPagavel => ContaPagavel::deArray($dados),
            $pendente->payload['candidatos'] ?? [],
        );
    }

    public function descartar(int $userId): void
    {
        TelegramPendingConfirmation::query()
            ->where('user_id', $userId)
            ->where('tipo', self::TIPO)
            ->delete();
    }
}
