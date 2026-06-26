<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

/**
 * Intenções de comando do bot (doc 06 §2). REGISTRAR/EDITAR/CANCELAR/BUSCAR são os
 * comandos básicos; AJUDA cobre /start, /ajuda e /help. DESCONHECIDO é o texto livre
 * não reconhecido como comando estruturado — encaminhado ao interpretador de IA
 * (Bloco 4), que faz intenção + extração. O roteamento é determinístico; a IA nunca
 * decide aqui.
 */
enum Comando: string
{
    case REGISTRAR = 'registrar';
    case EDITAR = 'editar';
    case CANCELAR = 'cancelar';
    case BUSCAR = 'buscar';
    case AJUDA = 'ajuda';
    case DESCONHECIDO = 'desconhecido';
}
