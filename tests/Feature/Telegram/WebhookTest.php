<?php

use App\Domain\Telegram\GerarTokenDeVinculo;
use App\Domain\Telegram\RoteadorDeMensagem;
use App\Domain\Telegram\VincularTelegram;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Contrato do webhook do Telegram (doc 06 §1/§3): toda entrada passa por
 * (1) validação do segredo no header, (2) dedupe por update_id (idempotência
 * sem Redis) e (3) autenticação (telegram_user_id → vínculo ativo). O webhook
 * sempre responde 200 ao Telegram (exceto segredo inválido → 403); a redação
 * das mensagens do bot é etapa de frontend, fora desta etapa. Aqui o roteador é
 * apenas um ponto de extensão (stub) — o roteamento de comandos é a próxima etapa.
 */

uses(RefreshDatabase::class);

const SEGREDO = 'segredo-de-teste';
const ROTA = '/telegram/webhook';

beforeEach(function () {
    config()->set('services.telegram.webhook_secret', SEGREDO);
});

/** Espião do roteador: captura o que o webhook despacharia. */
function espiaoRoteador(): object
{
    $espiao = new class implements RoteadorDeMensagem
    {
        public ?int $autenticadoComoUserId = null;

        public ?int $naoVinculadoTelegramId = null;

        public int $chamadas = 0;

        public function autenticado(User $user, array $update): void
        {
            $this->chamadas++;
            $this->autenticadoComoUserId = $user->id;
        }

        public function naoVinculado(int $telegramUserId, array $update): void
        {
            $this->chamadas++;
            $this->naoVinculadoTelegramId = $telegramUserId;
        }
    };

    app()->instance(RoteadorDeMensagem::class, $espiao);

    return $espiao;
}

function update(int $updateId, ?int $fromId = 555, string $texto = 'oi'): array
{
    $msg = ['update_id' => $updateId];

    if ($fromId !== null) {
        $msg['message'] = [
            'message_id' => 1,
            'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Lu'],
            'chat' => ['id' => $fromId, 'type' => 'private'],
            'text' => $texto,
        ];
    }

    return $msg;
}

function postWebhook(array $payload, ?string $segredo = SEGREDO)
{
    $headers = $segredo === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $segredo];

    return test()->postJson(ROTA, $payload, $headers);
}

/* -------- validação do segredo -------- */

it('recusa (403) quando o header do segredo está ausente', function () {
    postWebhook(update(1), segredo: null)->assertForbidden();

    expect(TelegramUpdate::count())->toBe(0);
});

it('recusa (403) quando o segredo está errado', function () {
    postWebhook(update(1), segredo: 'errado')->assertForbidden();

    expect(TelegramUpdate::count())->toBe(0);
});

/* -------- dedupe / idempotência -------- */

it('aceita (200) com segredo válido e registra o update_id', function () {
    espiaoRoteador();

    postWebhook(update(42))->assertOk();

    expect(TelegramUpdate::where('update_id', 42)->exists())->toBeTrue();
});

it('é idempotente: reentrega do mesmo update_id não reprocessa', function () {
    $espiao = espiaoRoteador();

    postWebhook(update(42))->assertOk();
    postWebhook(update(42))->assertOk();

    expect(TelegramUpdate::where('update_id', 42)->count())->toBe(1)
        ->and($espiao->chamadas)->toBe(1);
});

/* -------- autenticação -------- */

it('resolve o usuário vinculado e despacha como autenticado', function () {
    $user = User::factory()->create();
    $token = (new GerarTokenDeVinculo)->para($user->id);
    (new VincularTelegram)->confirmar($token->token, 999001, '+5511999998888');

    $espiao = espiaoRoteador();

    postWebhook(update(7, fromId: 999001))->assertOk();

    expect($espiao->autenticadoComoUserId)->toBe($user->id)
        ->and($espiao->naoVinculadoTelegramId)->toBeNull();
});

it('despacha como não vinculado quando o telegram_user_id não tem vínculo ativo', function () {
    $espiao = espiaoRoteador();

    postWebhook(update(7, fromId: 424242))->assertOk();

    expect($espiao->naoVinculadoTelegramId)->toBe(424242)
        ->and($espiao->autenticadoComoUserId)->toBeNull();
});

/* -------- rate limit (auditoria P1-2) -------- */

it('descarta em silêncio (200, sem rotear) updates acima do limite por remetente', function () {
    config()->set('ai.limites.telegram_por_minuto', 3);
    $espiao = espiaoRoteador();

    foreach (range(1, 3) as $i) {
        postWebhook(update($i, fromId: 777))->assertOk();
    }
    expect($espiao->chamadas)->toBe(3);

    // Sempre 200 para o Telegram (senão há reentrega em loop) — mas não roteia.
    postWebhook(update(4, fromId: 777))->assertOk();
    expect($espiao->chamadas)->toBe(3);
});

it('o rate limit é por remetente: outro usuário não é afetado', function () {
    config()->set('ai.limites.telegram_por_minuto', 2);
    $espiao = espiaoRoteador();

    foreach (range(1, 3) as $i) {
        postWebhook(update($i, fromId: 777));
    }

    postWebhook(update(100, fromId: 888))->assertOk();

    expect($espiao->naoVinculadoTelegramId)->toBe(888);
});

/* -------- robustez -------- */

it('responde 200 e não roteia quando o update não traz mensagem/remetente', function () {
    $espiao = espiaoRoteador();

    postWebhook(['update_id' => 9])->assertOk();

    expect(TelegramUpdate::where('update_id', 9)->exists())->toBeTrue()
        ->and($espiao->chamadas)->toBe(0);
});

it('responde 200 mesmo sem update_id (ignora silenciosamente)', function () {
    espiaoRoteador();

    postWebhook(['edited_message' => []])->assertOk();

    expect(TelegramUpdate::count())->toBe(0);
});
