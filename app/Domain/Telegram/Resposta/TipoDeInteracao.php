<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

/**
 * Tipo do resultado de uma interação do bot, entregue à porta de saída
 * {@see RespostaAoUsuario}. O frontend (etapa separada, regra 3) escolhe a redação a
 * partir daqui: REGISTRO carrega a confirmação do gasto (prévia ou esclarecimentos),
 * CONSULTA a resposta do chat, e NAO_ENTENDI o fallback seguro para intenções ainda
 * não suportadas (editar/cancelar/importar/desconhecido).
 */
enum TipoDeInteracao
{
    case REGISTRO;
    case CONSULTA;
    case NAO_ENTENDI;
}
