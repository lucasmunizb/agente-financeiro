<?php

declare(strict_types=1);

use App\Domain\Dashboard\AgruparContasDeCartao;

/*
 * Agrupamento das contas do quadro do dashboard por CARTÃO (spec 06b): as compras de um
 * mesmo cartão que caem na mesma fatura viram UMA linha — somatório + dia de vencimento da
 * fatura —, enquanto o que é fora de cartão continua individual. A soma é determinística e
 * mora no domínio; a tela só exibe (regra 4). Transformação PURA: entra lista de contas em
 * centavos, sai lista de contas em centavos, sem banco e sem relógio.
 */

/** @return array<string, mixed> */
function conta(string $descricao, string $vencimento, int $cents, array $extra = []): array
{
    return [
        'descricao' => $descricao,
        'vencimento' => $vencimento,
        'cents' => $cents,
        'recorrente' => false,
        'prevista' => false,
        'cartaoId' => null,
        'cartaoDescricao' => null,
    ] + $extra;
}

/** @return array<string, mixed> */
function contaDeCartao(string $descricao, string $vencimento, int $cents, int $cartaoId = 7, string $cartao = 'Nubank', array $extra = []): array
{
    return array_merge(
        conta($descricao, $vencimento, $cents),
        ['cartaoId' => $cartaoId, 'cartaoDescricao' => $cartao],
        $extra,
    );
}

it('soma as compras do mesmo cartão numa única linha com o vencimento da fatura', function () {
    $contas = (new AgruparContasDeCartao)([
        contaDeCartao('Mercado', '2026-07-05', 12000),
        contaDeCartao('Farmácia', '2026-07-05', 3550),
        contaDeCartao('Posto', '2026-07-05', 20000),
    ]);

    expect($contas)->toHaveCount(1);
    expect($contas[0]['cents'])->toBe(35550);
    expect($contas[0]['vencimento'])->toBe('2026-07-05');
    expect($contas[0]['cartao'])->toBeTrue();
    expect($contas[0]['cartaoDescricao'])->toBe('Nubank');
    expect($contas[0]['itens'])->toBe(3);
});

it('mantém individualmente o que é fora de cartão', function () {
    $contas = (new AgruparContasDeCartao)([
        conta('Aluguel', '2026-07-10', 150000),
        contaDeCartao('Mercado', '2026-07-05', 12000),
        conta('Internet', '2026-07-12', 9990),
    ]);

    expect($contas)->toHaveCount(3);
    // A linha do cartão passa a se chamar pelo cartão — é a fatura que vence, não a compra.
    expect(array_column($contas, 'descricao'))->toBe(['Nubank', 'Aluguel', 'Internet']);
    expect($contas[1]['cartao'])->toBeFalse();
});

it('separa cartões diferentes e faturas diferentes do mesmo cartão', function () {
    $contas = (new AgruparContasDeCartao)([
        contaDeCartao('Mercado', '2026-07-05', 12000, 7, 'Nubank'),
        contaDeCartao('Livro', '2026-07-05', 8000, 9, 'Itaú'),
        contaDeCartao('Padaria', '2026-08-05', 5000, 7, 'Nubank'),
    ]);

    expect($contas)->toHaveCount(3);
    expect($contas[0]['cartaoDescricao'])->toBe('Nubank');
    expect($contas[0]['cents'])->toBe(12000);
    expect($contas[1]['cartaoDescricao'])->toBe('Itaú');
    expect($contas[2]['vencimento'])->toBe('2026-08-05');
});

it('ordena o resultado por vencimento', function () {
    $contas = (new AgruparContasDeCartao)([
        conta('Internet', '2026-07-12', 9990),
        contaDeCartao('Mercado', '2026-07-05', 12000),
        conta('Aluguel', '2026-07-01', 150000),
    ]);

    expect(array_column($contas, 'vencimento'))->toBe(['2026-07-01', '2026-07-05', '2026-07-12']);
});

it('preserva o total: agrupar não cria nem some com dinheiro', function () {
    $entrada = [
        conta('Aluguel', '2026-07-10', 150000),
        contaDeCartao('Mercado', '2026-07-05', 12000),
        contaDeCartao('Farmácia', '2026-07-05', 3550),
        contaDeCartao('Livro', '2026-07-08', 8000, 9, 'Itaú'),
    ];

    expect(array_sum(array_column((new AgruparContasDeCartao)($entrada), 'cents')))
        ->toBe(array_sum(array_column($entrada, 'cents')));
});

it('só marca a linha do cartão como prevista quando TODA a fatura é projeção', function () {
    $misto = (new AgruparContasDeCartao)([
        contaDeCartao('Assinatura', '2026-07-05', 5590, extra: ['prevista' => true, 'recorrente' => true]),
        contaDeCartao('Mercado', '2026-07-05', 12000),
    ]);
    $todaPrevista = (new AgruparContasDeCartao)([
        contaDeCartao('Assinatura', '2026-07-05', 5590, extra: ['prevista' => true, 'recorrente' => true]),
    ]);

    expect($misto[0]['prevista'])->toBeFalse();
    expect($todaPrevista[0]['prevista'])->toBeTrue();
});

it('devolve lista vazia para entrada vazia', function () {
    expect((new AgruparContasDeCartao)([]))->toBe([]);
});
