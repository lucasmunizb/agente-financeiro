<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\MerchantAlias;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Tela de categorias (spec FE §7.12). Borda fina: lista as categorias com a contagem de uso JÁ
 * calculada pelo domínio (a UI nunca calcula — regra 4) e grava criar/editar/arquivar após ação
 * explícita (validação server-side, regra 7 na borda). Escopo estrito por usuário; ids opacos.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

it('a tela exige login', function () {
    $this->get(route('categorias'))->assertRedirect(route('login'));
});

it('mostra o estado vazio', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('categorias'))
        ->assertOk()
        ->assertSee('Nova categoria');
});

it('lista as categorias do usuário com a contagem de uso', function () {
    $user = User::factory()->create();
    $alim = Category::factory()->for($user)->create(['nome' => 'Alimentação']);
    Transaction::factory()->count(2)->for($user)->create(['categoria_id' => $alim->id]);

    $this->actingAs($user)->get(route('categorias'))
        ->assertOk()
        ->assertSee('Alimentação')
        ->assertSee('2 usos');
});

it('não mostra categoria de outro usuário', function () {
    $user = User::factory()->create();
    Category::factory()->for(User::factory()->create())->create(['nome' => 'CategoriaAlheia']);

    $this->actingAs($user)->get(route('categorias'))
        ->assertOk()
        ->assertDontSee('CategoriaAlheia');
});

it('cria uma categoria com palavras-chave e apelidos, salva e volta com aviso', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('categorias.store'), [
            'nome' => 'Alimentação',
            'cor' => '#1F6E5A',
            'icone' => 'utensils',
            'palavras_chave' => 'mercado, restaurante, iFood',
            'apelidos' => 'Pão de Açúcar, iFood',
        ])
        ->assertRedirect(route('categorias'))
        ->assertSessionHas('sucesso');

    $categoria = Category::where('user_id', $user->id)->sole();
    expect($categoria->nome)->toBe('Alimentação')
        ->and($categoria->cor)->toBe('#1F6E5A')
        ->and($categoria->icone)->toBe('utensils');
    expect(CategoryKeyword::where('category_id', $categoria->id)->pluck('palavra_chave')->all())
        ->toEqualCanonicalizing(['mercado', 'restaurante', 'ifood']);
    expect(MerchantAlias::where('category_id', $categoria->id)->pluck('alias')->all())
        ->toEqualCanonicalizing(['pao de acucar', 'ifood']);
});

it('rejeita categoria sem nome ou com cor/ícone fora da paleta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('categorias.store'), ['nome' => '', 'cor' => '#ff00ff', 'icone' => 'coisa-inventada'])
        ->assertSessionHasErrors(['nome', 'cor', 'icone']);

    expect(Category::count())->toBe(0);
});

it('rejeita nome duplicado do mesmo usuário', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['nome' => 'Alimentação']);

    $this->actingAs($user)
        ->post(route('categorias.store'), ['nome' => 'Alimentação'])
        ->assertSessionHasErrors('nome');

    expect(Category::where('user_id', $user->id)->count())->toBe(1);
});

it('edita a categoria, salva e volta com aviso', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->for($user)->create(['nome' => 'Alimentação', 'cor' => '#1F6E5A']);

    $this->actingAs($user)
        ->put(route('categorias.update', $categoria->getRouteKey()), [
            'nome' => 'Comida', 'cor' => '#C9852A', 'icone' => 'shopping-cart',
            'palavras_chave' => 'mercado', 'apelidos' => '',
        ])
        ->assertRedirect(route('categorias'))
        ->assertSessionHas('sucesso');

    $categoria->refresh();
    expect($categoria->nome)->toBe('Comida')
        ->and($categoria->cor)->toBe('#C9852A')
        ->and($categoria->icone)->toBe('shopping-cart');
});

it('a edição inválida usa o bag editarCategoria', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('categorias.update', $categoria->getRouteKey()), ['nome' => '', 'cor' => 'xyz'])
        ->assertSessionHasErrors(['nome', 'cor'], null, 'editarCategoria');
});

it('não edita categoria de outro usuário (404)', function () {
    $user = User::factory()->create();
    $alheia = Category::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)
        ->put(route('categorias.update', $alheia->getRouteKey()), ['nome' => 'x'])
        ->assertNotFound();
});

it('arquiva a categoria e volta com aviso', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('categorias.arquivar', $categoria->getRouteKey()))
        ->assertRedirect(route('categorias'))
        ->assertSessionHas('sucesso');

    expect($categoria->fresh()->arquivada)->toBeTrue();
});

it('não arquiva categoria de outro usuário (404)', function () {
    $user = User::factory()->create();
    $alheia = Category::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)
        ->post(route('categorias.arquivar', $alheia->getRouteKey()))
        ->assertNotFound();

    expect($alheia->fresh()->arquivada)->toBeFalse();
});

it('recusa o id REAL no path ao editar (só token opaco)', function () {
    $user = User::factory()->create();
    $categoria = Category::factory()->for($user)->create();

    $this->actingAs($user)->put("/categorias/{$categoria->id}", ['nome' => 'x'])->assertNotFound();
});
