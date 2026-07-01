<?php

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

/*
 * Fail closed em cookies (OWASP). O relato de risco descreve um servidor que,
 * ao falhar a descriptografia, cai para um decoder SEM verificação de
 * integridade e aceita qualquer cookie forjado (padrão Base64+Marshal). Estes
 * testes travam o comportamento correto do nosso stack:
 *
 *  1. A descriptografia verifica integridade (MAC): payload forjado ou
 *     adulterado LANÇA exceção — não há decoder de fallback.
 *  2. Os cookies NÃO são desserializados como objetos (defesa contra object
 *     injection).
 *  3. No HTTP, um cookie de sessão forjado é descartado: o visitante continua
 *     guest e nenhum dado injetado ("admin": true) entra na sessão.
 */

uses(RefreshDatabase::class);

it('rejeita um cookie forjado Base64+JSON (sem fallback inseguro)', function () {
    // Exatamente o vetor do relato: {"session_id":"...","admin":true} apenas
    // encodado, sem criptografia/MAC válido.
    $forjado = base64_encode(json_encode(['session_id' => 'x', 'admin' => true]));

    expect(fn () => Crypt::decrypt($forjado))->toThrow(DecryptException::class);
});

it('rejeita um payload cifrado porém adulterado (verificação de MAC)', function () {
    $valido = Crypt::encrypt('conteudo-legitimo');

    // Adultera um caractere no meio do ciphertext.
    $meio = intdiv(strlen($valido), 2);
    $adulterado = substr($valido, 0, $meio).($valido[$meio] === 'A' ? 'B' : 'A').substr($valido, $meio + 1);

    expect(fn () => Crypt::decrypt($adulterado))->toThrow(DecryptException::class);
});

it('não desserializa cookies como objetos (defesa contra object injection)', function () {
    expect(EncryptCookies::serialized('qualquer-cookie'))->toBeFalse();
});

it('descarta um cookie de sessão forjado no HTTP: segue guest, sem dados injetados', function () {
    $forjado = base64_encode(json_encode(['session_id' => 'x', 'admin' => true]));

    $resposta = $this->withUnencryptedCookie(config('session.cookie'), $forjado)
        ->get('/');

    $resposta->assertRedirect(route('login'));
    $this->assertGuest();
    expect(session('admin'))->toBeNull();
});

it('um cookie de sessão forjado não assume a sessão de um usuário existente', function () {
    $user = User::factory()->create();
    $forjado = base64_encode(json_encode(['session_id' => 'x', 'user_id' => $user->id, 'admin' => true]));

    $this->withUnencryptedCookie(config('session.cookie'), $forjado)
        ->get('/')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
