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
 * Recorrência via bot (spec 10c): RECORRENCIA_GRAVADA — o "sim" cadastrou o molde mensal;
 * nenhum lançamento nasceu (ele vem do materializador da spec 10, no dia).
 *
 * Importação de fatura (spec 07): IMPORTACAO_PRONTA (a pré-importação foi montada e está
 * pronta para revisão — carrega a {@see PreImportacao}),
 * IMPORTACAO_PROTEGIDA_POR_SENHA (PDF com senha — pedir versão sem senha, C2) e
 * IMPORTACAO_FALHOU (não foi possível ler a fatura — erro de parsing registrado).
 *
 * Quitar conta pelo bot (decisão do usuário 2026-07-21): PAGAMENTO_A_CONFIRMAR (uma conta foi
 * identificada e espera o "sim" — nada gravado ainda, regra 7), PAGAMENTO_AMBIGUO (mais de uma
 * conta casa com o que ele disse; quem desempata é ele, nunca o modelo),
 * CONTA_A_PAGAR_NAO_ENCONTRADA (nada casou — o bot diz isso em vez de inventar uma conta) e
 * PAGAMENTO_REGISTRADO (o "sim" quitou).
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
    case RECORRENCIA_GRAVADA;
    case IMPORTACAO_PRONTA;
    case IMPORTACAO_PROTEGIDA_POR_SENHA;
    case IMPORTACAO_FALHOU;
    case PAGAMENTO_A_CONFIRMAR;
    case PAGAMENTO_AMBIGUO;
    case PAGAMENTO_REGISTRADO;
    case CONTA_A_PAGAR_NAO_ENCONTRADA;
}
