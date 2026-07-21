<?php

use App\Domain\IA\GastoExtraido;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    // Testes de feature renderizam Blade com @vite. Sem o manifest compilado
    // (public/build é gitignored → ausente num checkout limpo do CI) o Vite lança
    // ViteManifestNotFoundException e a view dá 500. withoutVite() stuba a diretiva:
    // a suíte valida o conteúdo renderizado, não o pipeline de assets (build fica na
    // imagem de produção, stage `assets`). Desacopla o gate de teste do npm build.
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Constrói um GastoExtraido (saída crua do ExtratorDeGasto) para os testes da camada
 * de normalização/confirmação. Por padrão: gasto simples no pix, hoje. Use `dataTexto`
 * => null para simular data ausente.
 *
 * @param  array<string, mixed>  $over
 */
function gastoExtraidoFake(array $over = []): GastoExtraido
{
    return new GastoExtraido(
        descricao: $over['descricao'] ?? 'mercado',
        valorTexto: $over['valorTexto'] ?? '35',
        formaPagamento: $over['formaPagamento'] ?? 'pix',
        cartao: $over['cartao'] ?? null,
        categoria: $over['categoria'] ?? null,
        dataTexto: array_key_exists('dataTexto', $over) ? $over['dataTexto'] : 'hoje',
        parcelas: $over['parcelas'] ?? null,
        recorrenciaDiaTexto: $over['recorrenciaDiaTexto'] ?? null,
        pago: $over['pago'] ?? null,
        dataPagamentoTexto: $over['dataPagamentoTexto'] ?? null,
    );
}
