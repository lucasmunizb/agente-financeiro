<?php

declare(strict_types=1);

use App\Domain\Shared\OpaqueId;

/*
 * OpaqueId — identificador OPACO para a borda web. Todo id de recurso que sai numa URL
 * (path de rota ou valor de filtro na query) é criptografado com a APP_KEY e nunca aparece
 * em claro (requisito inegociável — ver README §"Identificadores nas URLs"). Aqui garantimos
 * o contrato do value object; a fiação nas telas é coberta nos testes web de lançamentos.
 */

it('faz round-trip: decodifica de volta o id que codificou', function () {
    expect(OpaqueId::decode(OpaqueId::encode(42)))->toBe(42)
        ->and(OpaqueId::decode(OpaqueId::encode(1)))->toBe(1)
        ->and(OpaqueId::decode(OpaqueId::encode(987654321)))->toBe(987654321);
});

it('nunca expõe o valor real do id no token', function () {
    $token = OpaqueId::encode(12345);

    expect($token)->not->toBe('12345')
        ->and($token)->not->toContain('12345');
});

it('gera um token URL-safe (só [A-Za-z0-9_-], sem +/= nem barra)', function () {
    // 100 ids diferentes: o alfabeto não pode escapar do conjunto seguro para path/query.
    foreach (range(1, 100) as $id) {
        expect(OpaqueId::encode($id))->toMatch('/^[A-Za-z0-9_-]+$/');
    }
});

it('é não-determinístico: o mesmo id gera tokens diferentes (IV aleatório) mas ambos decodificam', function () {
    $a = OpaqueId::encode(7);
    $b = OpaqueId::encode(7);

    expect($a)->not->toBe($b)
        ->and(OpaqueId::decode($a))->toBe(7)
        ->and(OpaqueId::decode($b))->toBe(7);
});

it('rejeita (null) um valor REAL/numérico passado como se fosse token', function () {
    // O ponto do requisito: id em claro na URL não é aceito.
    expect(OpaqueId::decode('123'))->toBeNull()
        ->and(OpaqueId::decode('42'))->toBeNull();
});

it('rejeita (null) tokens forjados, vazios ou ausentes', function () {
    expect(OpaqueId::decode('lixo-nao-e-token'))->toBeNull()
        ->and(OpaqueId::decode(''))->toBeNull()
        ->and(OpaqueId::decode(null))->toBeNull()
        ->and(OpaqueId::decode('!!!'))->toBeNull();
});
