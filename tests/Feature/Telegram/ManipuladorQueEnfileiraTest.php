<?php

use App\Domain\IA\Intencao;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;
use App\Domain\Telegram\ManipuladorQueEnfileira;
use App\Jobs\ProcessarMensagemDoBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

/*
 * Ligação dos Blocos 4/5 ao roteador (spec 03, §10 "Adiado"). O manipulador é um
 * adaptador fino: NÃO roda IA no ciclo do webhook (barreira §4 — processamento pesado
 * fica fora do request). Ele apenas ENFILEIRA o processamento no worker, carregando o
 * usuário, o comando recebido e — quando o slash já fixa a intenção — a intenção forçada.
 * Texto livre vai sem intenção: a classificação por IA acontece no worker.
 */

uses(RefreshDatabase::class);

it('enfileira o processamento com a intenção forçada (slash conhecido)', function () {
    Bus::fake();
    $user = User::factory()->create();
    $comando = new ComandoRecebido(Comando::REGISTRAR, '50 mercado', '/registrar 50 mercado');

    (new ManipuladorQueEnfileira(Intencao::REGISTRAR))->manipular($user, $comando);

    Bus::assertDispatched(
        ProcessarMensagemDoBot::class,
        fn ($job) => $job->userId === $user->id
            && $job->intencaoForcada === Intencao::REGISTRAR
            && $job->comando == $comando,
    );
});

it('enfileira a consulta com a intenção CONSULTAR (slash /buscar)', function () {
    Bus::fake();
    $user = User::factory()->create();
    $comando = new ComandoRecebido(Comando::BUSCAR, 'junho', '/buscar junho');

    (new ManipuladorQueEnfileira(Intencao::CONSULTAR))->manipular($user, $comando);

    Bus::assertDispatched(
        ProcessarMensagemDoBot::class,
        fn ($job) => $job->intencaoForcada === Intencao::CONSULTAR,
    );
});

it('enfileira o texto livre sem intenção: a classificação fica para o worker', function () {
    Bus::fake();
    $user = User::factory()->create();
    $comando = new ComandoRecebido(Comando::DESCONHECIDO, '', 'gastei 50 no mercado');

    (new ManipuladorQueEnfileira)->manipular($user, $comando);

    Bus::assertDispatched(
        ProcessarMensagemDoBot::class,
        fn ($job) => $job->intencaoForcada === null
            && $job->comando->textoOriginal === 'gastei 50 no mercado',
    );
});
