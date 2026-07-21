<?php

declare(strict_types=1);

use App\Domain\IA\Historico\ExpurgarConversas;
use App\Models\ChatMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Expurgo de histórico de conversa (doc 02 §3.6): conversas e suas mensagens com mais de
 * 60 dias são apagadas. "Agora" é INJETADO — determinismo (regra 4/5). Tabelas do
 * RemembersConversations da Laravel AI SDK (agent_conversations / agent_conversation_messages).
 */

uses(RefreshDatabase::class);

/** Instante de referência fixo (fuso SP) usado como "agora" no expurgo. */
function agora(string $data = '2026-06-26'): CarbonImmutable
{
    return CarbonImmutable::parse($data.' 09:00:00', 'America/Sao_Paulo');
}

/**
 * Cria uma conversa com `updated_at` dado e `mensagens` mensagens com o mesmo carimbo.
 * Devolve o id da conversa.
 */
function criarConversa(User $user, CarbonImmutable $updatedAt, int $mensagens = 1): string
{
    $id = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'title' => 'Conversa',
        'created_at' => $updatedAt,
        'updated_at' => $updatedAt,
    ]);

    for ($i = 0; $i < $mensagens; $i++) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $id,
            'user_id' => $user->id,
            'agent' => 'AssistenteDeConsulta',
            'role' => 'user',
            'content' => 'oi',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    return $id;
}

it('apaga conversas e suas mensagens com mais de 60 dias; mantém as recentes', function () {
    $user = User::factory()->create();

    $velha = criarConversa($user, agora()->subDays(61), mensagens: 2);
    $nova = criarConversa($user, agora()->subDays(10), mensagens: 3);

    $apagadas = app(ExpurgarConversas::class)->executar(agora());

    expect($apagadas)->toBe(1);

    expect(DB::table('agent_conversations')->where('id', $velha)->exists())->toBeFalse();
    expect(DB::table('agent_conversation_messages')->where('conversation_id', $velha)->count())->toBe(0);

    expect(DB::table('agent_conversations')->where('id', $nova)->exists())->toBeTrue();
    expect(DB::table('agent_conversation_messages')->where('conversation_id', $nova)->count())->toBe(3);
});

it('mantém a conversa de exatamente 60 dias (a borda não é "mais de 60 dias")', function () {
    $user = User::factory()->create();

    $borda = criarConversa($user, agora()->subDays(60));

    $apagadas = app(ExpurgarConversas::class)->executar(agora());

    expect($apagadas)->toBe(0);
    expect(DB::table('agent_conversations')->where('id', $borda)->exists())->toBeTrue();
});

it('não apaga mensagens de conversas que permanecem', function () {
    $user = User::factory()->create();

    criarConversa($user, agora()->subDays(61), mensagens: 4);
    $nova = criarConversa($user, agora()->subDays(1), mensagens: 2);

    app(ExpurgarConversas::class)->executar(agora());

    expect(DB::table('agent_conversation_messages')->count())->toBe(2);
    expect(DB::table('agent_conversation_messages')->where('conversation_id', $nova)->count())->toBe(2);
});

it('retorna 0 quando não há nada a expurgar', function () {
    $user = User::factory()->create();
    criarConversa($user, agora()->subDays(5));

    expect(app(ExpurgarConversas::class)->executar(agora()))->toBe(0);
});

it('o comando ai:expurgar-conversas apaga conversas antigas', function () {
    $user = User::factory()->create();
    $velha = criarConversa($user, CarbonImmutable::now('America/Sao_Paulo')->subDays(120));

    $this->artisan('ai:expurgar-conversas')->assertSuccessful();

    expect(DB::table('agent_conversations')->where('id', $velha)->exists())->toBeFalse();
});

/** Mensagem do chat web (chat_messages) com created_at fixo. */
function mensagemChat(User $user, CarbonImmutable $criadaEm): ChatMessage
{
    $m = ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'body' => 'oi']);
    $m->forceFill(['created_at' => $criadaEm, 'updated_at' => $criadaEm])->saveQuietly();

    return $m;
}

it('apaga mensagens do chat web (chat_messages) com mais de 60 dias; mantém as recentes', function () {
    $user = User::factory()->create();

    $velha = mensagemChat($user, agora()->subDays(61));
    $nova = mensagemChat($user, agora()->subDays(10));

    app(ExpurgarConversas::class)->executar(agora());

    expect(ChatMessage::query()->whereKey($velha->id)->exists())->toBeFalse()
        ->and(ChatMessage::query()->whereKey($nova->id)->exists())->toBeTrue();
});

it('mantém a mensagem do chat de exatamente 60 dias (borda não é "mais de 60 dias")', function () {
    $user = User::factory()->create();
    $borda = mensagemChat($user, agora()->subDays(60));

    app(ExpurgarConversas::class)->executar(agora());

    expect(ChatMessage::query()->whereKey($borda->id)->exists())->toBeTrue();
});

it('o expurgo está agendado diariamente às 03:30 (spec 06 / C5)', function () {
    $schedule = app(Schedule::class);

    $evento = collect($schedule->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'ai:expurgar-conversas'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('30 3 * * *'); // dailyAt('03:30')
});
