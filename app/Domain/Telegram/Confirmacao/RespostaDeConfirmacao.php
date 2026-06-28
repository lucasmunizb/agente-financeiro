<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Confirmacao;

/**
 * Resposta do usuário a uma confirmação pendente (spec 04b §6), interpretada de forma
 * DETERMINÍSTICA (não-IA, regra 4). INDEFINIDO é o fallback seguro: qualquer coisa que não
 * seja claramente sim/não nunca vira chute (barreira 1) — mantém o pendente e pede de novo.
 */
enum RespostaDeConfirmacao
{
    case SIM;
    case NAO;
    case INDEFINIDO;
}
