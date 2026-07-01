<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
 * Criação de conta (backend real). Antes esta rota era um placeholder de
 * apresentação: apenas validava o formato e redirecionava sem persistir. Agora
 * cria o usuário de fato (senha com hash), autentica na sessão e envia ao
 * onboarding (onde o aceite LGPD é registrado). Regras: confirmação/consentimento
 * de termos, senha nunca em texto puro, isolamento por e-mail único.
 */

uses(RefreshDatabase::class);

it('cria o usuário, aplica hash na senha e autentica', function () {
    $resposta = $this->post('/criar-conta', [
        'name' => 'João Silva',
        'email' => 'joao@example.com',
        'password' => 'senha-super-secreta',
        'password_confirmation' => 'senha-super-secreta',
        'terms' => '1',
    ]);

    $resposta->assertRedirect(route('onboarding'));

    $user = User::where('email', 'joao@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('João Silva')
        ->and($user->password)->not->toBe('senha-super-secreta')
        ->and(Hash::check('senha-super-secreta', $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

it('não persiste a senha em texto puro em lugar nenhum', function () {
    $this->post('/criar-conta', [
        'name' => 'Maria',
        'email' => 'maria@example.com',
        'password' => 'texto-puro-proibido',
        'password_confirmation' => 'texto-puro-proibido',
        'terms' => '1',
    ]);

    $this->assertDatabaseMissing('users', ['password' => 'texto-puro-proibido']);
});

it('exige aceite dos termos para criar a conta', function () {
    $this->post('/criar-conta', [
        'name' => 'Sem Termos',
        'email' => 'sem@example.com',
        'password' => 'senha-valida-123',
        'password_confirmation' => 'senha-valida-123',
        // terms ausente
    ])->assertSessionHasErrors('terms');

    $this->assertDatabaseCount('users', 0);
    $this->assertGuest();
});

it('exige confirmação de senha e mínimo de 8 caracteres', function () {
    $this->post('/criar-conta', [
        'name' => 'Curta',
        'email' => 'curta@example.com',
        'password' => 'curta',
        'password_confirmation' => 'diferente',
        'terms' => '1',
    ])->assertSessionHasErrors('password');

    $this->assertDatabaseCount('users', 0);
});

it('recusa e-mail já cadastrado (unicidade)', function () {
    User::factory()->create(['email' => 'existe@example.com']);

    $this->post('/criar-conta', [
        'name' => 'Duplicado',
        'email' => 'existe@example.com',
        'password' => 'senha-valida-123',
        'password_confirmation' => 'senha-valida-123',
        'terms' => '1',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'existe@example.com')->count())->toBe(1);
});
