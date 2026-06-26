<?php

use App\Domain\Telegram\DedupeDeUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Deduplicação por update_id (doc 06 §2/§3): em reentregas por instabilidade,
 * mantém a PRIMEIRA mensagem. Idempotência garantida por unique constraint no
 * banco — sem Redis.
 */

uses(RefreshDatabase::class);

it('aceita um update_id novo', function () {
    expect((new DedupeDeUpdate)->ehNovo(1001))->toBeTrue()
        ->and(TelegramUpdate::where('update_id', 1001)->count())->toBe(1);
});

it('rejeita um update_id repetido (mantém a primeira)', function () {
    $svc = new DedupeDeUpdate;

    expect($svc->ehNovo(1001))->toBeTrue()
        ->and($svc->ehNovo(1001))->toBeFalse()
        ->and(TelegramUpdate::where('update_id', 1001)->count())->toBe(1);
});

it('aceita update_ids distintos', function () {
    $svc = new DedupeDeUpdate;

    expect($svc->ehNovo(1001))->toBeTrue()
        ->and($svc->ehNovo(1002))->toBeTrue()
        ->and(TelegramUpdate::count())->toBe(2);
});
