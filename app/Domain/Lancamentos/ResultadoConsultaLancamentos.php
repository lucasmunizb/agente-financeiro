<?php

declare(strict_types=1);

namespace App\Domain\Lancamentos;

use Carbon\CarbonImmutable;

/**
 * Resultado da consulta de extrato `consultar_lancamentos` (FE §7.6).
 *
 * Carrega os lançamentos do período JÁ agrupados por dia de vencimento (do mais recente
 * ao mais antigo) e o "total exibido" JÁ somado pelo domínio ({@see ConsultarLancamentos}).
 * A tela apenas formata em pt-BR e exibe — nunca recalcula dinheiro (regra 4/5).
 *
 * Cada item é um array já com o essencial para a linha de extrato: descrição, valor em
 * centavos, categoria (nome + cor), forma de pagamento, cartão (quando houver), rótulo de
 * parcela ("2/3") e o status de EXIBIÇÃO derivado (pago | a_vencer | atraso | cancelado).
 */
final class ResultadoConsultaLancamentos
{
    /**
     * @param  list<array{data: CarbonImmutable, itens: list<array{transactionId: int, descricao: string, cents: int, categoria: ?array{nome: string, cor: ?string}, forma: ?string, cartaoDescricao: ?string, parcela: ?string, status: string, vencimento: CarbonImmutable}>}>  $grupos
     */
    public function __construct(
        public readonly int $totalExibidoCents,
        public readonly array $grupos,
        public readonly int $registros,
    ) {}
}
