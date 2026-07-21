<?php

declare(strict_types=1);

namespace App\Domain\Gasto;

use Carbon\CarbonImmutable;

/**
 * Dados de entrada para o cadastro de um gasto manual (F2.3).
 *
 * DTO imutável validado/traduzido na borda (Form Request) antes de chegar ao
 * domínio. `cardId` presente ⇒ compra em cartão (usa o vencimento do cartão);
 * ausente ⇒ fora de cartão (vence na data da compra).
 *
 * `origem` registra de onde veio o lançamento (`manual` por padrão; `telegram`
 * via bot; `pdf` na importação de fatura). Não altera o cálculo — só a auditoria
 * e a procedência gravada na transaction.
 *
 * `categoriaSugeridaPorIa` marca que a `categoriaId` foi PRÉ-SELECIONADA pela IA (fallback
 * quando o lookup determinístico não classificou), não por uma regra aprendida. É só um
 * sinal de procedência para a apresentação exibir a dica ("sugestão da IA — confira") e para
 * medir acurácia rumo ao auto-save; não altera cálculo. A confirmação continua obrigatória.
 *
 * `dataPagamento` presente ⇒ o usuário afirmou que o gasto JÁ foi pago (decisão 2026-07-21):
 * o cadastro marca a PRIMEIRA parcela como paga nessa data (pagamento é por parcela — as
 * demais seguem abertas). Só vale FORA de cartão: em compra no cartão é ignorada, porque a
 * quitação é da fatura (§4.3).
 */
final class DadosGastoManual
{
    public function __construct(
        public readonly int $userId,
        public readonly string $descricao,
        public readonly int $valorTotalCents,
        public readonly CarbonImmutable $dataCompra,
        public readonly int $paymentMethodId,
        public readonly int $parcelas = 1,
        public readonly ?int $cardId = null,
        public readonly ?int $accountId = null,
        public readonly ?int $categoriaId = null,
        public readonly string $origem = 'manual',
        public readonly bool $categoriaSugeridaPorIa = false,
        public readonly ?CarbonImmutable $dataPagamento = null,
    ) {}
}
