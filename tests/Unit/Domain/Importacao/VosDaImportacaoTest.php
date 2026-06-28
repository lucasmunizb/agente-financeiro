<?php

use App\Domain\Importacao\ItemPreImportacao;
use App\Domain\Importacao\LancamentoExtraido;
use App\Domain\Importacao\PreImportacao;
use App\Domain\Importacao\TextoExtraido;
use App\Models\InvoiceImport;
use Carbon\CarbonImmutable;

/*
 * VOs da importação (spec 07 §6). São inertes: só carregam dados já extraídos de
 * forma determinística — descrição/valor(centavos)/data/parcelas — sem nenhum campo
 * sensível e sem calcular dinheiro.
 */

it('TextoExtraido distingue conteúdo vazio e marca origem OCR', function () {
    $nativo = new TextoExtraido("LANC 10,00\nOUTRO 20,00", viaOcr: false);
    $vazio = new TextoExtraido('   ', viaOcr: false);
    $ocr = new TextoExtraido('texto', viaOcr: true);

    expect($nativo->vazio())->toBeFalse()
        ->and($vazio->vazio())->toBeTrue()
        ->and($nativo->viaOcr)->toBeFalse()
        ->and($ocr->viaOcr)->toBeTrue();
});

it('LancamentoExtraido carrega valor em centavos (int) e default de 1 parcela', function () {
    $l = new LancamentoExtraido(
        descricao: 'Padaria',
        valorCents: 123456,
        data: CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo'),
    );

    expect($l->valorCents)->toBe(123456)
        ->and($l->valorCents)->toBeInt()
        ->and($l->parcelas)->toBe(1)
        ->and($l->descricao)->toBe('Padaria');
});

it('PreImportacao fica pendente_revisao e separa novos de duplicados', function () {
    $data = CarbonImmutable::parse('2026-06-10', 'America/Sao_Paulo');
    $novo = new ItemPreImportacao(new LancamentoExtraido('Padaria', 1000, $data), duplicado: false);
    $dup = new ItemPreImportacao(new LancamentoExtraido('Mercado', 2000, $data), duplicado: true);

    $pre = new PreImportacao(importId: 1, itens: [$novo, $dup]);

    expect($pre->status)->toBe(InvoiceImport::PENDENTE_REVISAO)
        ->and($pre->itens)->toHaveCount(2)
        ->and($pre->novos())->toHaveCount(1)
        ->and($pre->duplicados())->toHaveCount(1)
        ->and($pre->novos()[0]->lancamento->descricao)->toBe('Padaria');
});
