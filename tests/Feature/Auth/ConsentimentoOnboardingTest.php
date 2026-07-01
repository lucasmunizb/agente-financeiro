<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Onboarding: o consentimento LGPD (aceite_lgpd_em) é persistido no usuário
 * autenticado recém-criado. Antes o POST /onboarding era placeholder e não
 * gravava nada. Sem aceite, não avança (regra inviolável nº 6/7).
 */

uses(RefreshDatabase::class);

it('registra o aceite LGPD do usuário autenticado e segue para o vínculo do Telegram', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);

    // Após o consentimento, o fluxo encaminha para conectar o Telegram.
    $this->actingAs($user)
        ->post('/onboarding', ['consent' => '1'])
        ->assertRedirect(route('telegram'));

    expect($user->fresh()->aceite_lgpd_em)->not->toBeNull();
});

it('não avança sem consentimento e não grava aceite', function () {
    $user = User::factory()->create(['aceite_lgpd_em' => null]);

    $this->actingAs($user)
        ->post('/onboarding', [])
        ->assertSessionHasErrors('consent');

    expect($user->fresh()->aceite_lgpd_em)->toBeNull();
});
