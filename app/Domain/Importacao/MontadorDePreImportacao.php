<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use App\Domain\Categoria\LookupDeCategoria;

/**
 * Monta a {@see PreImportacao} inerte (spec 07 §6, C6): para cada lançamento extraído,
 * marca duplicidade (reuso do detector do Bloco 1) e sugere categoria de forma
 * determinística (lookup por alias/palavra-chave). Não persiste nada — a pré-importação
 * só vira lançamento real na confirmação (regra 7).
 */
final class MontadorDePreImportacao
{
    public function __construct(
        private readonly DetectorDeDuplicidadeNaImportacao $detector = new DetectorDeDuplicidadeNaImportacao,
        private readonly LookupDeCategoria $lookup = new LookupDeCategoria,
    ) {}

    /**
     * @param  array<int, LancamentoExtraido>  $lancamentos
     */
    public function montar(int $userId, int $importId, array $lancamentos): PreImportacao
    {
        $duplicados = $this->detector->marcar($userId, $lancamentos);

        $itens = [];
        foreach ($lancamentos as $i => $lancamento) {
            $itens[] = new ItemPreImportacao(
                lancamento: $lancamento,
                duplicado: $duplicados[$i],
                categoriaIdSugerida: $this->lookup->para($userId, $lancamento->descricao),
            );
        }

        return new PreImportacao(importId: $importId, itens: $itens);
    }
}
