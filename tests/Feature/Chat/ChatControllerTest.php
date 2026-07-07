<?php

declare(strict_types=1);

use App\Ai\Agents\AssistenteDeConsulta;
use App\Models\ChatMessage;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;

/*
 * Borda web do chat financeiro (spec FE §7.14). Camada fina: valida (Form Request), delega
 * ao serviço de domínio e devolve JSON. O anexo é validado por MIME REAL (PDF-only,
 * seguranca-ia) e é efêmero — nunca persistido (regra 6/lgpd). Histórico isolado por usuário.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
});

/** UploadedFile com CONTEÚDO real em disco (para o MIME ser inferido do conteúdo, não da extensão). */
function anexoReal(string $nome, string $conteudo, string $mimeCliente): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'chattest');
    file_put_contents($path, $conteudo);

    return new UploadedFile($path, $nome, $mimeCliente, null, true);
}

/** Bytes mínimos de um PDF válido (cabeçalho %PDF- reconhecido pelo finfo). */
function pdfReal(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

it('store: usuário autenticado envia mensagem e recebe a resposta do assistente', function () {
    $user = User::factory()->create();
    Ai::fakeAgent(AssistenteDeConsulta::class, ['Olá! Posso ajudar com suas finanças.']);

    $resp = $this->actingAs($user)->postJson(route('chat.store'), ['mensagem' => 'oi']);

    $resp->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('mensagem.role', 'assistant')
        ->assertJsonPath('mensagem.aprovado', true);

    expect($resp->json('mensagem.body'))->toContain('Olá');
    expect(ChatMessage::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('store: exige autenticação', function () {
    $this->postJson(route('chat.store'), ['mensagem' => 'oi'])->assertUnauthorized();
});

it('store: rejeita anexo que NÃO é PDF de verdade (texto renomeado .pdf) com 422', function () {
    $user = User::factory()->create();

    // Conteúdo de texto, porém com extensão .pdf e MIME de cliente forjado como PDF.
    $anexo = anexoReal('fatura.pdf', 'isto definitivamente nao e um pdf', 'application/pdf');

    $resp = $this->actingAs($user)->postJson(route('chat.store'), [
        'mensagem' => 'Segue minha fatura',
        'anexo' => $anexo,
    ]);

    $resp->assertStatus(422)->assertJsonValidationErrors('anexo');
    expect($resp->json('errors.anexo.0'))->toContain('PDF');
    expect(ChatMessage::query()->count())->toBe(0);
});

it('store: aceita PDF real, responde e NÃO persiste o arquivo (efemeridade, regra 6)', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    $anexo = anexoReal('fatura-nubank.pdf', pdfReal(), 'application/pdf');

    $resp = $this->actingAs($user)->postJson(route('chat.store'), [
        'mensagem' => 'Segue minha fatura',
        'anexo' => $anexo,
    ]);

    $resp->assertOk()->assertJsonPath('mensagem.role', 'assistant');

    // A mensagem do usuário marca o anexo, mas o arquivo não vai para lugar nenhum.
    expect(ChatMessage::query()->where('role', 'user')->where('tem_anexo', true)->exists())->toBeTrue();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('store: exige mensagem OU anexo (vazio → 422)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('chat.store'), [])
        ->assertStatus(422)->assertJsonValidationErrors('mensagem');
});

it('index: devolve apenas o histórico do próprio usuário, em ordem', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    ChatMessage::create(['user_id' => $a->id, 'role' => 'user', 'body' => 'pergunta do A']);
    ChatMessage::create(['user_id' => $a->id, 'role' => 'assistant', 'body' => 'resposta ao A', 'aprovado' => true]);
    ChatMessage::create(['user_id' => $b->id, 'role' => 'user', 'body' => 'segredo do B']);

    $resp = $this->actingAs($a)->getJson(route('chat.index'));

    $resp->assertOk();
    $corpos = collect($resp->json('mensagens'))->pluck('body');
    expect($corpos)->toContain('pergunta do A')->toContain('resposta ao A')
        ->and($corpos)->not->toContain('segredo do B');
});

it('index: exige autenticação', function () {
    $this->getJson(route('chat.index'))->assertUnauthorized();
});
