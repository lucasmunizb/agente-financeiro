<?php

declare(strict_types=1);

namespace App\Domain\IA\Esclarecimento;

use App\Domain\IA\GastoParcial;
use App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes;
use App\Models\TelegramPendingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Memória do esclarecimento pendente (extração parcial) entre mensagens do bot/chat — o
 * estado que faltava para o slot-filling multi-turno (bug: o bot repetia perguntas cujas
 * respostas já estavam na 1ª mensagem). Guarda só o TEXTO cru já dito (a IA nunca calcula
 * nem normaliza, regra 4); a mescla determinística é do {@see GastoParcial}.
 *
 * Reusa a fila `telegram_pending_confirmations` (uma linha por usuário — §6.b) sob o `tipo`
 * `esclarecimento`, mutuamente exclusivo com a confirmação confirmável (04b): quando o gasto
 * enfim completa, o {@see ConfirmacoesPendentes} sobrescreve
 * a linha como `confirmacao`. TTL de 15 min (§6.a) e escopo estrito por `user_id`.
 */
final class EsclarecimentosPendentes
{
    public const TTL_MINUTOS = 15;

    public const TIPO = 'esclarecimento';

    public function guardar(int $userId, GastoParcial $parcial, CarbonImmutable $agora): void
    {
        TelegramPendingConfirmation::updateOrCreate(
            ['user_id' => $userId],
            [
                'tipo' => self::TIPO,
                'token' => (string) Str::uuid(),
                'payload' => $this->serializar($parcial),
                'expira_em' => $agora->addMinutes(self::TTL_MINUTOS)->setTimezone('UTC'),
            ],
        );
    }

    public function recuperar(int $userId, CarbonImmutable $agora): ?GastoParcial
    {
        $pendente = TelegramPendingConfirmation::query()
            ->where('user_id', $userId)
            ->where('tipo', self::TIPO)
            ->where('expira_em', '>=', $agora->setTimezone('UTC'))
            ->first();

        return $pendente === null ? null : $this->desserializar($pendente->payload);
    }

    public function descartar(int $userId): void
    {
        TelegramPendingConfirmation::query()
            ->where('user_id', $userId)
            ->where('tipo', self::TIPO)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(GastoParcial $parcial): array
    {
        return [
            'descricao' => $parcial->descricao,
            'valor' => $parcial->valorTexto,
            'forma_pagamento' => $parcial->formaPagamento,
            'cartao' => $parcial->cartao,
            'categoria' => $parcial->categoria,
            'data' => $parcial->dataTexto,
            'parcelas' => $parcial->parcelas,
            'recorrencia_dia' => $parcial->recorrenciaDiaTexto,
            'pago' => $parcial->pago,
            'data_pagamento' => $parcial->dataPagamentoTexto,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function desserializar(array $payload): GastoParcial
    {
        return new GastoParcial(
            descricao: $payload['descricao'] ?? null,
            valorTexto: $payload['valor'] ?? null,
            formaPagamento: $payload['forma_pagamento'] ?? null,
            cartao: $payload['cartao'] ?? null,
            categoria: $payload['categoria'] ?? null,
            dataTexto: $payload['data'] ?? null,
            parcelas: isset($payload['parcelas']) ? (int) $payload['parcelas'] : null,
            recorrenciaDiaTexto: $payload['recorrencia_dia'] ?? null,
            // `false` é resposta legítima ("ainda não paguei"): não pode virar "ausente" no
            // round-trip, senão o bot repergunta no turno seguinte.
            pago: isset($payload['pago']) ? (bool) $payload['pago'] : null,
            dataPagamentoTexto: $payload['data_pagamento'] ?? null,
        );
    }
}
