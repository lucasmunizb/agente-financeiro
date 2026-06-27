<?php

declare(strict_types=1);

namespace App\Domain\IA\Custo;

/**
 * Natureza de uma chamada de IA registrada em ai_usage_log (doc 02 §3.6). Permite consultar
 * custo por finalidade sem reter conteúdo.
 */
enum TipoDeUsoIA: string
{
    /** Chat de consulta / interpretação de mensagem do usuário. */
    case MENSAGEM = 'mensagem';

    /** Extração a partir de fatura/PDF importado. */
    case IMPORTACAO = 'importacao';

    /** Resumos e agregações redigidas pela IA. */
    case RESUMO = 'resumo';
}
