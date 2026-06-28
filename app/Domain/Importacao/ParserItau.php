<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use App\Models\Bank;

/**
 * Parser da fatura do Itaú (spec 07 §6).
 *
 * STUB DELIBERADO. A regra de identificar os lançamentos na fatura do cartão fica
 * pendente para DEPOIS do frontend — todo o resto do pipeline (validação, extração,
 * OCR, dedupe, pré-importação, efetivação, notificação e descarte) já está pronto e
 * testado com um parser fake. Quando a regra for escrita, ela entra AQUI, sobre o
 * texto de {@see TextoExtraido}, devolvendo {@see LancamentoExtraido}[] determinístico,
 * ignorando todo dado sensível (nome, endereço, CPF, nascimento) — regras 4, 5 e 6.
 */
final class ParserItau implements ParserDeFatura
{
    /**
     * @return array<int, LancamentoExtraido>
     */
    public function interpretar(TextoExtraido $texto): array
    {
        // TODO(spec-07, pós-frontend): implementar a identificação determinística dos
        // lançamentos (descrição, valor em centavos, data, parcelas) a partir do texto
        // da fatura do Itaú, ignorando todo dado sensível.
        throw ParserNaoImplementadoException::paraBanco(Bank::ITAU);
    }
}
