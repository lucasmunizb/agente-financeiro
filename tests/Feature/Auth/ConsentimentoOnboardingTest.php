<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Onboarding: o consentimento LGPD (aceite_lgpd_em) é persistido no usuário
 * autenticado recém-criado. Antes o POST /onboarding era placeholder e não
 * gravava nada. Sem aceite, não avança (regra inviolável nº 6/7).
 */

uses(RefreshDatabase::class);

it('registra o aceite LGPD do usuário autenticado e entra no app', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);

    $this->actingAs($user)
        ->post('/onboarding', ['consent' => '1'])
        ->assertRedirect('/');

    expect($user->fresh()->aceite_lgpd_em)->not->toBeNull();
});

it('não avança sem consentimento e não grava aceite', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);

    $this->actingAs($user)
        ->post('/onboarding', [])
        ->assertSessionHasErrors('consent');

    expect($user->fresh()->aceite_lgpd_em)->toBeNull();
});
