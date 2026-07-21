<?php

use App\Ai\Agents\AssistenteDeConsulta;
use App\Ai\Agents\ClassificadorDeIntencao;
use App\Ai\Agents\ExtratorDeGasto;
use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Esclarecimento\EsclarecimentosPendentes;
use App\Domain\IA\Intencao;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;
use App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes;
use App\Domain\Telegram\Resposta\RespostaAoUsuario;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Domain\Telegram\Resposta\TipoDeInteracao;
use App\Jobs\ProcessarMensagemDoBot;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StatusPagamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

/*
 * Processamento da mensagem no worker (spec 03, §10 "Adiado": liga Blocos 4/5). O Job
 * resolve a intenção (forçada pelo slash ou classificada por IA no texto livre), executa
 * a orquestração determinística correspondente — registro (extração + confirmação SEM
 * persistir, regra 7) ou consulta (com guard pós-geração, barreira 4) — e entrega o
 * RESULTADO de domínio à porta de saída `RespostaAoUsuario`. A redação/envio da mensagem
 * ao Telegram é frontend (regra 3): aqui a saída é um espião. A IA é fakeada (regra 8).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PaymentMethodSeeder::class, StatusPagamentoSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00', 'America/Sao_Paulo'));

    $this->saida = Mockery::spy(RespostaAoUsuario::class);
    app()->instance(RespostaAoUsuario::class, $this->saida);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function processar(int $userId, Comando $comando, string $argumentos, string $original, ?Intencao $forcada): void
{
    ProcessarMensagemDoBot::dispatchSync(
        $userId,
        new ComandoRecebido($comando, $argumentos, $original),
        $forcada,
    );
}

function guardarPendente(User $user): void
{
    $dados = new DadosGastoManual(
        userId: $user->id,
        descricao: 'Mercado',
        valorTotalCents: 9000,
        dataCompra: CarbonImmutable::parse('2026-06-26', 'America/Sao_Paulo'),
        paymentMethodId: PaymentMethod::idFor(PaymentMethod::PIX),
        parcelas: 1,
    );

    $agora = CarbonImmutable::now('America/Sao_Paulo');
    $previa = (new RegistrarGastoManual)->preview($dados, $agora);

    app(ConfirmacoesPendentes::class)->guardar(
        $user->id,
        new ConfirmacaoDeGasto($previa, $dados, []),
        $agora,
    );
}

it('registro (slash forçado): extrai pelos argumentos, gera a confirmação e entrega à saída', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'mercado', 'valor' => '90', 'forma_pagamento' => 'pix', 'data' => 'hoje', 'pago' => true,
    ]]);

    processar($user->id, Comando::REGISTRAR, '90 no mercado pix', '/registrar 90 no mercado pix', Intencao::REGISTRAR);

    // Slash já fixa a intenção: o extrator recebe os ARGUMENTOS (não o texto com o /comando)
    // e o classificador NÃO é chamado (se fosse, sem fake, a SDK falharia).
    Ai::assertAgentWasPrompted(
        ExtratorDeGasto::class,
        fn ($prompt) => $prompt->prompt === '90 no mercado pix',
    );

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $u->is($user)
            && $r->tipo === TipoDeInteracao::REGISTRO
            && $r->registro->confirmavel()
            && ! $r->registro->precisaEsclarecer(),
    );
});

it('registro com extração incompleta: entrega esclarecimentos, sem prévia', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ExtratorDeGasto::class, [['descricao' => 'uber']]);

    processar($user->id, Comando::REGISTRAR, 'uber', '/registrar uber', Intencao::REGISTRAR);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::REGISTRO
            && $r->registro->precisaEsclarecer()
            && ! $r->registro->confirmavel()
            && in_array('valor', $r->registro->esclarecimentos, true),
    );
});

it('consulta (slash /buscar): responde via chat e entrega a resposta aprovada', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, ['Olá! Posso ajudar com suas finanças.']);

    processar($user->id, Comando::BUSCAR, 'oi', '/buscar oi', Intencao::CONSULTAR);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::CONSULTA
            && $r->consulta->aprovado
            && str_contains($r->consulta->texto, 'Olá'),
    );
});

it('sinaliza processamento ("digitando…") antes de entregar a resposta', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(AssistenteDeConsulta::class, ['Olá!']);

    processar($user->id, Comando::BUSCAR, 'oi', '/buscar oi', Intencao::CONSULTAR);

    $this->saida->shouldHaveReceived('sinalizarProcessando')
        ->withArgs(fn (User $u) => $u->is($user));
});

it('texto livre classificado como registrar: classifica pelo texto íntegro e segue para registro', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'registrar']]);
    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'mercado', 'valor' => '90', 'forma_pagamento' => 'pix', 'data' => 'hoje', 'pago' => true,
    ]]);

    processar($user->id, Comando::DESCONHECIDO, '', 'gastei 90 no mercado no pix hoje', null);

    Ai::assertAgentWasPrompted(
        ClassificadorDeIntencao::class,
        fn ($prompt) => $prompt->prompt === 'gastei 90 no mercado no pix hoje',
    );
    // Texto livre: o extrator recebe o texto original íntegro.
    Ai::assertAgentWasPrompted(
        ExtratorDeGasto::class,
        fn ($prompt) => $prompt->prompt === 'gastei 90 no mercado no pix hoje',
    );

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::REGISTRO
            && $r->registro->confirmavel(),
    );
});

it('texto livre classificado como consultar: segue para o chat de consulta', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'consultar']]);
    Ai::fakeAgent(AssistenteDeConsulta::class, ['Tudo certo por aqui.']);

    processar($user->id, Comando::DESCONHECIDO, '', 'quanto gastei em junho?', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::CONSULTA
            && $r->consulta->aprovado,
    );
});

it('intenção ainda não suportada (editar): entrega "não entendi", sem rodar registro nem consulta', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'editar']]);

    processar($user->id, Comando::DESCONHECIDO, '', 'muda o valor daquele gasto', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::NAO_ENTENDI
            && $r->registro === null
            && $r->consulta === null,
    );
});

/* -------- spec 04b: confirmação do gasto (sim/não), determinística -------- */

it('registro confirmável: guarda a confirmação como pendente para o "sim" seguinte (§C6)', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ExtratorDeGasto::class, [[
        'descricao' => 'mercado', 'valor' => '90', 'forma_pagamento' => 'pix', 'data' => 'hoje', 'pago' => true,
    ]]);

    processar($user->id, Comando::REGISTRAR, '90 no mercado pix', '/registrar 90 no mercado pix', Intencao::REGISTRAR);

    $pendente = app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo'));

    expect($pendente)->not->toBeNull()
        ->and($pendente->confirmavel())->toBeTrue();
});

it('com pendente e resposta "sim": grava o gasto e entrega GRAVADO (§C1)', function () {
    $user = User::factory()->create();
    guardarPendente($user);

    // Com pendente, a confirmação é determinística: o classificador de IA NÃO é chamado
    // (se fosse, sem fake, a SDK falharia) — prova do curto-circuito antes da intenção.
    processar($user->id, Comando::DESCONHECIDO, '', 'sim', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $u->is($user)
            && $r->tipo === TipoDeInteracao::GRAVADO
            && $r->transacao !== null,
    );

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1)
        ->and(app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))->toBeNull();
});

it('com pendente e resposta "não": cancela sem gravar e descarta o pendente (§C2)', function () {
    $user = User::factory()->create();
    guardarPendente($user);

    processar($user->id, Comando::DESCONHECIDO, '', 'não', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::CONFIRMACAO_CANCELADA,
    );

    expect(Transaction::count())->toBe(0)
        ->and(app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))->toBeNull();
});

it('com pendente e resposta ambígua: mantém o pendente e não grava (§C7)', function () {
    $user = User::factory()->create();
    guardarPendente($user);

    processar($user->id, Comando::DESCONHECIDO, '', 'talvez', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::CONFIRMACAO_AMBIGUA,
    );

    expect(Transaction::count())->toBe(0)
        ->and(app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))->not->toBeNull();
});

/* -------- slot-filling multi-turno: não repetir o que o usuário já disse -------- */

it('esclarecimento multi-turno: mescla a resposta seguinte e NÃO repergunta o que já foi dito', function () {
    $user = User::factory()->create();

    // Turno 1 já trazia valor + forma + data + categoria; só faltou a descrição.
    // Turno 2 o usuário responde só a descrição — o extrator, isolado, devolve o resto nulo.
    Ai::fakeAgent(ExtratorDeGasto::class, [
        ['descricao' => null, 'valor' => '263,52', 'forma_pagamento' => 'pix', 'data' => 'amanhã', 'categoria' => 'viagem', 'pago' => false],
        ['descricao' => 'airbnb mauricio', 'valor' => null, 'forma_pagamento' => null, 'data' => null, 'categoria' => null],
    ]);

    // Turno 1: pede só a descrição (e guarda o parcial).
    processar($user->id, Comando::DESCONHECIDO, '', 'cobrança no pix para amanhã de 263,52 na categoria viagem', Intencao::REGISTRAR);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::REGISTRO
            && $r->registro->precisaEsclarecer()
            && $r->registro->esclarecimentos === ['descricao'],
    );

    // Turno 2: só a descrição. Sem pendente confirmável, o esclarecimento tem precedência —
    // o classificador NÃO roda (se rodasse, sem fake, a SDK falharia).
    processar($user->id, Comando::DESCONHECIDO, '', 'airbnb mauricio', null);

    // Agora completa: confirmável, SEM pedir valor nem forma de novo.
    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::REGISTRO
            && $r->registro->confirmavel()
            && ! $r->registro->precisaEsclarecer(),
    );

    // E vira confirmação pendente (o próximo "sim" grava); o esclarecimento sumiu.
    expect(app(ConfirmacoesPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))
        ->not->toBeNull();
});

it('aguardando esclarecimento: a próxima mensagem preenche o slot, não vira consulta', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ExtratorDeGasto::class, [
        ['descricao' => 'uber', 'valor' => null, 'forma_pagamento' => null],
        ['descricao' => null, 'valor' => '30', 'forma_pagamento' => 'pix', 'pago' => true],
    ]);

    processar($user->id, Comando::DESCONHECIDO, '', 'paguei o uber', Intencao::REGISTRAR);
    // Mensagem seguinte completa os campos; NÃO é classificada como consulta (o
    // AssistenteDeConsulta não é fakeado — se fosse chamado, a SDK falharia).
    processar($user->id, Comando::DESCONHECIDO, '', '30 no pix', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::REGISTRO
            && $r->registro->confirmavel(),
    );
});

it('aguardando esclarecimento: "cancelar" descarta o parcial sem gravar', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ExtratorDeGasto::class, [['descricao' => 'uber', 'valor' => null, 'forma_pagamento' => null]]);

    processar($user->id, Comando::DESCONHECIDO, '', 'paguei o uber', Intencao::REGISTRAR);
    // "cancelar" é a saída explícita do esclarecimento (o extrator NÃO roda de novo).
    processar($user->id, Comando::DESCONHECIDO, '', 'cancelar', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::CONFIRMACAO_CANCELADA,
    );

    expect(app(EsclarecimentosPendentes::class)->recuperar($user->id, CarbonImmutable::now('America/Sao_Paulo')))
        ->toBeNull()
        ->and(Transaction::count())->toBe(0);
});

it('sem pendente: "sim" solto nunca grava (§C6)', function () {
    $user = User::factory()->create();

    Ai::fakeAgent(ClassificadorDeIntencao::class, [['intencao' => 'desconhecido']]);

    processar($user->id, Comando::DESCONHECIDO, '', 'sim', null);

    $this->saida->shouldHaveReceived('entregar')->withArgs(
        fn (User $u, ResultadoDaInteracao $r) => $r->tipo === TipoDeInteracao::NAO_ENTENDI,
    );

    expect(Transaction::count())->toBe(0);
});
