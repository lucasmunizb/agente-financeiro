<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
 * Borda web da tela Configurações & privacidade (spec FE §7.17). Fia a apresentação às ações de
 * domínio já testadas: perfil, senha, exportar (portabilidade) e excluir conta (dupla confirmação).
 * Tudo escopado no usuário autenticado; confirmar antes de agir (regra 7).
 */

uses(RefreshDatabase::class);

it('a tela exige login', function () {
    $this->get(route('configuracoes'))->assertRedirect(route('login'));
});

it('mostra o perfil do usuário autenticado', function () {
    $user = User::factory()->create(['name' => 'Lucas', 'email' => 'lucas@exemplo.com']);

    $this->actingAs($user)->get(route('configuracoes'))
        ->assertOk()
        ->assertSee('Lucas')
        ->assertSee('lucas@exemplo.com');
});

it('atualiza o perfil (nome, e-mail, fuso) com a senha atual', function () {
    $user = User::factory()->create([
        'name' => 'Lucas', 'email' => 'lucas@exemplo.com', 'password' => Hash::make('password'),
    ]);

    $this->actingAs($user)->put(route('configuracoes.perfil'), [
        'name' => 'Lucas Braga',
        'email' => 'novo@exemplo.com',
        'timezone' => 'America/Bahia',
        'senha_atual' => 'password',
    ])->assertRedirect(route('configuracoes'));

    $user->refresh();
    expect($user->name)->toBe('Lucas Braga')
        ->and($user->email)->toBe('novo@exemplo.com')
        ->and($user->timezone)->toBe('America/Bahia');
});

it('atualiza nome/fuso SEM senha quando o e-mail não muda', function () {
    $user = User::factory()->create(['name' => 'Lucas', 'email' => 'lucas@exemplo.com']);

    $this->actingAs($user)->put(route('configuracoes.perfil'), [
        'name' => 'Lucas Braga',
        'email' => 'lucas@exemplo.com', // igual — não exige senha
        'timezone' => 'America/Bahia',
    ])->assertRedirect(route('configuracoes'));

    expect($user->fresh()->name)->toBe('Lucas Braga');
});

it('recusa a troca de e-mail sem a senha atual (pentest L3)', function () {
    $user = User::factory()->create(['email' => 'lucas@exemplo.com']);

    $this->actingAs($user)->from(route('configuracoes'))
        ->put(route('configuracoes.perfil'), [
            'name' => 'Lucas',
            'email' => 'outro@exemplo.com',
            'timezone' => 'America/Sao_Paulo',
        ])->assertSessionHasErrors('senha_atual', errorBag: 'perfil');

    expect($user->fresh()->email)->toBe('lucas@exemplo.com');
});

it('rejeita fuso horário inválido', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('configuracoes'))
        ->put(route('configuracoes.perfil'), [
            'name' => 'Lucas',
            'email' => 'lucas@exemplo.com',
            'timezone' => 'Marte/Inexistente',
        ])->assertSessionHasErrors('timezone', errorBag: 'perfil');
});

it('rejeita e-mail já usado por outra conta', function () {
    User::factory()->create(['email' => 'ocupado@exemplo.com']);
    $user = User::factory()->create(['email' => 'lucas@exemplo.com']);

    $this->actingAs($user)->from(route('configuracoes'))
        ->put(route('configuracoes.perfil'), [
            'name' => 'Lucas',
            'email' => 'ocupado@exemplo.com',
            'timezone' => 'America/Sao_Paulo',
        ])->assertSessionHasErrors('email', errorBag: 'perfil');
});

it('altera a senha exigindo a senha atual correta', function () {
    $user = User::factory()->create(['password' => Hash::make('atual-123')]);

    $this->actingAs($user)->put(route('configuracoes.senha'), [
        'senha_atual' => 'atual-123',
        'senha' => 'nova-senha-456',
        'senha_confirmation' => 'nova-senha-456',
    ])->assertRedirect(route('configuracoes'));

    expect(Hash::check('nova-senha-456', $user->fresh()->password))->toBeTrue();
});

it('recusa a troca de senha com a senha atual errada', function () {
    $user = User::factory()->create(['password' => Hash::make('atual-123')]);

    $this->actingAs($user)->from(route('configuracoes'))
        ->put(route('configuracoes.senha'), [
            'senha_atual' => 'errada',
            'senha' => 'nova-senha-456',
            'senha_confirmation' => 'nova-senha-456',
        ])->assertSessionHasErrors('senha_atual', errorBag: 'senha');

    expect(Hash::check('atual-123', $user->fresh()->password))->toBeTrue();
});

it('baixa os dados do usuário como anexo estruturado', function () {
    $user = User::factory()->create(['email' => 'lucas@exemplo.com']);

    $resposta = $this->actingAs($user)->get(route('configuracoes.exportar'));

    $resposta->assertOk();
    expect($resposta->headers->get('content-disposition'))->toContain('attachment');
    expect($resposta->getContent())->toContain('lucas@exemplo.com');
});

it('exige digitar EXCLUIR para apagar a conta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('configuracoes'))
        ->delete(route('configuracoes.excluir'), ['confirmacao' => 'qualquer'])
        ->assertSessionHasErrors('confirmacao', errorBag: 'excluir');

    expect(User::find($user->id))->not->toBeNull();
    $this->assertAuthenticated();
});

it('recusa a exclusão sem a senha atual correta (pentest M1)', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->actingAs($user)->from(route('configuracoes'))
        ->delete(route('configuracoes.excluir'), [
            'confirmacao' => 'EXCLUIR',
            'senha_atual' => 'errada',
        ])->assertSessionHasErrors('senha_atual', errorBag: 'excluir');

    expect(User::find($user->id))->not->toBeNull();
    $this->assertAuthenticated();
});

it('exclui a conta com a confirmação e a senha atual, encerrando a sessão', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->actingAs($user)
        ->delete(route('configuracoes.excluir'), [
            'confirmacao' => 'EXCLUIR',
            'senha_atual' => 'password',
        ])->assertRedirect(route('login'));

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();
    $this->assertGuest();
});
