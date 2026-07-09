<?php

declare(strict_types=1);

use App\Domain\Categoria\ArquivarCategoria;
use App\Domain\Categoria\CriarCategoria;
use App\Domain\Categoria\DadosCategoria;
use App\Domain\Categoria\EditarCategoria;
use App\Domain\Categoria\ListarCategorias;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\MerchantAlias;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * CRUD de categorias (spec FE §7.12). Escrita determinística: nome/cor/ícone + palavras-chave e
 * apelidos de estabelecimento (regras do lookup, doc 08 §1/§2 — armazenados NORMALIZADOS e
 * únicos). Arquivar é lógico (não apaga o histórico). A contagem de uso vem PRONTA do backend
 * (a UI nunca calcula — regra 4). Escopo estrito por usuário; auditoria em toda escrita.
 */

uses(RefreshDatabase::class);

it('lista as categorias ativas com a contagem de uso pronta, escopada por usuário', function () {
    $user = User::factory()->create();
    $alheio = User::factory()->create();

    $alim = Category::factory()->for($user)->create(['nome' => 'Alimentação']);
    $transp = Category::factory()->for($user)->create(['nome' => 'Transporte']);
    Category::factory()->for($user)->create(['nome' => 'Arquivada', 'arquivada' => true]);
    $outra = Category::factory()->for($alheio)->create(['nome' => 'Alheia']);

    Transaction::factory()->count(3)->for($user)->create(['categoria_id' => $alim->id]);
    Transaction::factory()->count(1)->for($user)->create(['categoria_id' => $transp->id]);
    // Transação de terceiro na "mesma" categoria não conta para o usuário.
    Transaction::factory()->for($alheio)->create(['categoria_id' => $outra->id]);

    $lista = (new ListarCategorias)->para($user->id);

    // Só ativas (a arquivada não aparece na grade), do próprio usuário.
    expect($lista)->toHaveCount(2);

    $porNome = $lista->keyBy(fn (array $l) => $l['categoria']->nome);
    expect($porNome['Alimentação']['usos'])->toBe(3)
        ->and($porNome['Transporte']['usos'])->toBe(1);
});

it('cria a categoria com palavras-chave e apelidos normalizados e audita', function () {
    $user = User::factory()->create();
    $agora = CarbonImmutable::parse('2026-07-09 10:00', 'America/Sao_Paulo');

    $categoria = (new CriarCategoria)->criar(new DadosCategoria(
        userId: $user->id,
        nome: 'Alimentação',
        cor: '#1F6E5A',
        icone: 'utensils',
        palavrasChave: ['Mercado', 'Restaurante', 'iFood', 'mercado'], // duplicada normaliza p/ uma
        apelidos: ['Pão de Açúcar', 'iFood'],
    ), $agora);

    expect($categoria->nome)->toBe('Alimentação')
        ->and($categoria->cor)->toBe('#1F6E5A')
        ->and($categoria->icone)->toBe('utensils')
        ->and($categoria->arquivada)->toBeFalse();

    // Palavras-chave normalizadas (caixa/acento) e sem duplicatas.
    $palavras = CategoryKeyword::where('category_id', $categoria->id)->pluck('palavra_chave')->all();
    expect($palavras)->toEqualCanonicalizing(['mercado', 'restaurante', 'ifood']);

    // Apelidos normalizados, escopados por usuário.
    $apelidos = MerchantAlias::where('category_id', $categoria->id)->pluck('alias')->all();
    expect($apelidos)->toEqualCanonicalizing(['pao de acucar', 'ifood']);

    expect(AuditLog::where('entidade', 'category')->where('entidade_id', $categoria->id)
        ->where('acao', AuditLog::ACAO_CRIAR)->exists())->toBeTrue();
});

it('edita nome/cor/ícone e re-sincroniza palavras-chave e apelidos, auditando', function () {
    $user = User::factory()->create();
    $categoria = (new CriarCategoria)->criar(new DadosCategoria(
        userId: $user->id, nome: 'Alimentação', cor: '#1F6E5A', icone: 'utensils',
        palavrasChave: ['mercado', 'restaurante'], apelidos: ['ifood'],
    ), CarbonImmutable::now('America/Sao_Paulo'));

    $editada = (new EditarCategoria)->editar($categoria->id, new DadosCategoria(
        userId: $user->id, nome: 'Comida', cor: '#C9852A', icone: 'shopping-cart',
        palavrasChave: ['mercado', 'lanche'], apelidos: ['pao de acucar'],
    ), CarbonImmutable::now('America/Sao_Paulo'));

    expect($editada->nome)->toBe('Comida')
        ->and($editada->cor)->toBe('#C9852A')
        ->and($editada->icone)->toBe('shopping-cart');

    expect(CategoryKeyword::where('category_id', $categoria->id)->pluck('palavra_chave')->all())
        ->toEqualCanonicalizing(['mercado', 'lanche']); // "restaurante" saiu, "lanche" entrou
    expect(MerchantAlias::where('category_id', $categoria->id)->pluck('alias')->all())
        ->toEqualCanonicalizing(['pao de acucar']);     // "ifood" saiu

    expect(AuditLog::where('entidade', 'category')->where('entidade_id', $categoria->id)
        ->where('acao', AuditLog::ACAO_EDITAR)->exists())->toBeTrue();
});

it('não edita categoria de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = Category::factory()->for(User::factory()->create())->create();

    (new EditarCategoria)->editar($alheia->id, new DadosCategoria(
        userId: $user->id, nome: 'x', cor: null, icone: null, palavrasChave: [], apelidos: [],
    ), CarbonImmutable::now('America/Sao_Paulo'));
})->throws(ModelNotFoundException::class);

it('arquiva a categoria sem apagar o histórico e audita', function () {
    $user = User::factory()->create();
    $categoria = (new CriarCategoria)->criar(new DadosCategoria(
        userId: $user->id, nome: 'Lazer', cor: null, icone: null,
        palavrasChave: ['cinema'], apelidos: [],
    ), CarbonImmutable::now('America/Sao_Paulo'));
    $tx = Transaction::factory()->for($user)->create(['categoria_id' => $categoria->id]);

    (new ArquivarCategoria)->arquivar($categoria->id, $user->id, CarbonImmutable::now('America/Sao_Paulo'));

    $categoria->refresh();
    expect($categoria->arquivada)->toBeTrue()
        ->and(Transaction::whereKey($tx->id)->exists())->toBeTrue()          // lançamento preservado
        ->and(CategoryKeyword::where('category_id', $categoria->id)->exists())->toBeTrue(); // regras preservadas

    // Some da grade de ativas.
    expect((new ListarCategorias)->para($user->id))->toHaveCount(0);

    expect(AuditLog::where('entidade', 'category')->where('entidade_id', $categoria->id)
        ->where('acao', AuditLog::ACAO_EDITAR)->exists())->toBeTrue();
});

it('não arquiva categoria de outro usuário', function () {
    $user = User::factory()->create();
    $alheia = Category::factory()->for(User::factory()->create())->create();

    (new ArquivarCategoria)->arquivar($alheia->id, $user->id, CarbonImmutable::now('America/Sao_Paulo'));
})->throws(ModelNotFoundException::class);
