<?php

use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Consentimento LGPD obrigatório (auditoria P1-7): o aceite não é decorativo — quem
 * pula o onboarding NÃO usa o app (nem a IA sobre dados financeiros) com
 * aceite_lgpd_em nulo. Só onboarding, logout e páginas públicas ficam acessíveis.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

it('sem aceite, o app redireciona para o onboarding', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);

    $this->actingAs($user)->get('/')->assertRedirect(route('onboarding'));
    $this->actingAs($user)->get('/lancamentos')->assertRedirect(route('onboarding'));
});

it('sem aceite, rota de IA em JSON responde 403 (fetch não segue redirect de HTML)', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);

    $this->actingAs($user)
        ->postJson(route('chat.store'), ['mensagem' => 'oi'])
        ->assertForbidden();
});

it('sem aceite, onboarding e logout continuam acessíveis', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);

    $this->actingAs($user)->get('/onboarding')->assertOk();
    $this->actingAs($user)->post('/logout')->assertStatus(302);
});

it('com aceite registrado, o app abre normalmente', function () {
    $user = User::factory()->create(); // a factory consente por padrão

    $this->actingAs($user)->get('/')->assertOk();
});

it('o aceite gravado preserva o instante (fuso SP → UTC em timestamptz)', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);
    $antes = now('UTC');

    $this->actingAs($user)->post('/onboarding', ['consent' => '1']);

    $aceite = $user->fresh()->aceite_lgpd_em;
    expect($aceite)->not->toBeNull()
        // Sem a conversão a UTC, o instante gravado fica ~3h no passado.
        ->and($aceite->diffInSeconds($antes, absolute: true))->toBeLessThan(60);
});
