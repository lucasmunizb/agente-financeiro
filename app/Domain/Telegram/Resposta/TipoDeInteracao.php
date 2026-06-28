<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Resposta;

use App\Domain\Importacao\PreImportacao;

/**
 * Tipo do resultado de uma interação do bot, entregue à porta de saída
 * {@see RespostaAoUsuario}. O frontend (etapa separada, regra 3) escolhe a redação a
 * partir daqui: REGISTRO carrega a confirmação do gasto (prévia ou esclarecimentos),
 * CONSULTA a resposta do chat, e NAO_ENTENDI o fallback seguro para intenções ainda
 * não suportadas (editar/cancelar/importar/desconhecido).
 *
 * Confirmação do gasto via bot (spec 04b): GRAVADO (o "sim" persistiu o gasto),
 * CONFIRMACAO_CANCELADA (o "não" descartou o pendente), CONFIRMACAO_AMBIGUA (resposta
 * indefinida — mantém o pendente e pede de novo) e NADA_PARA_CONFIRMAR (não havia pendente
 * válido — expirou ou já fora confirmado).
 *
 * Importação de fatura (spec 07): IMPORTACAO_PRONTA (a pré-importação foi montada e está
 * pronta para revisão — carrega a {@see PreImportacao}),
 * IMPORTACAO_PROTEGIDA_POR_SENHA (PDF com senha — pedir versão sem senha, C2) e
 * IMPORTACAO_FALHOU (não foi possível ler a fatura — erro de parsing registrado).
 */
enum TipoDeInteracao
{
    case REGISTRO;
    case CONSULTA;
    case NAO_ENTENDI;
    case GRAVADO;
    case CONFIRMACAO_CANCELADA;
    case CONFIRMACAO_AMBIGUA;
    case NADA_PARA_CONFIRMAR;
    case IMPORTACAO_PRONTA;
    case IMPORTACAO_PROTEGIDA_POR_SENHA;
    case IMPORTACAO_FALHOU;
}
