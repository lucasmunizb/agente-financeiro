<?php

use App\Domain\Telegram\ClassificadorDeComando;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;
use App\Domain\Telegram\ManipuladorDeComando;
use App\Domain\Telegram\RoteadorDeComandos;
use App\Models\User;

/*
 * Despacho determinístico (doc 06 §2). O roteador classifica a mensagem do usuário
 * autenticado e a entrega ao ManipuladorDeComando registrado para aquela intenção;
 * sem manipulador específico, cai no padrão. A execução de cada comando (extração
 * via IA, confirmação, mensagens do bot) é etapa posterior — aqui só validamos o
 * roteamento. naoVinculado (vínculo via bot) é etapa de frontend: no-op por ora.
 */

/** Manipulador-espião: registra a última chamada recebida. */
function espiaoManipulador(): ManipuladorDeComando
{
    return new class implements ManipuladorDeComando
    {
        public int $chamadas = 0;

        public ?User $user = null;

        public ?ComandoRecebido $comando = null;

        public function manipular(User $user, ComandoRecebido $comando): void
        {
            $this->chamadas++;
            $this->user = $user;
            $this->comando = $comando;
        }
    };
}

function updateComTexto(?string $texto): array
{
    $msg = ['update_id' => 1, 'message' => ['message_id' => 1, 'from' => ['id' => 9]]];

    if ($texto !== null) {
        $msg['message']['text'] = $texto;
    }

    return $msg;
}

it('despacha cada intenção ao manipulador registrado', function (string $texto, Comando $alvo) {
    $espioes = [];
    foreach (Comando::cases() as $c) {
        $espioes[$c->value] = espiaoManipulador();
    }
    $padrao = espiaoManipulador();

    $roteador = new RoteadorDeComandos(
        new ClassificadorDeComando,
        $padrao,
        $espioes,
    );

    $user = User::factory()->make(['id' => 7]);
    $roteador->autenticado($user, updateComTexto($texto));

    expect($espioes[$alvo->value]->chamadas)->toBe(1)
        ->and($espioes[$alvo->value]->comando->comando)->toBe($alvo)
        ->and($espioes[$alvo->value]->user->id)->toBe(7);

    // Nenhum outro manipulador foi acionado.
    foreach ($espioes as $valor => $espiao) {
        if ($valor !== $alvo->value) {
            expect($espiao->chamadas)->toBe(0);
        }
    }
})->with([
    ['/registrar cafe', Comando::REGISTRAR],
    ['/editar 12', Comando::EDITAR],
    ['/cancelar 12', Comando::CANCELAR],
    ['/buscar comida', Comando::BUSCAR],
    ['/ajuda', Comando::AJUDA],
    ['gastei 50 no mercado', Comando::DESCONHECIDO],
]);

it('cai no manipulador padrão quando não há um registrado para a intenção', function () {
    $padrao = espiaoManipulador();

    $roteador = new RoteadorDeComandos(new ClassificadorDeComando, $padrao, []);

    $roteador->autenticado(User::factory()->make(['id' => 3]), updateComTexto('/buscar comida'));

    expect($padrao->chamadas)->toBe(1)
        ->and($padrao->comando->comando)->toBe(Comando::BUSCAR);
});

it('não quebra quando o update não traz texto (classifica como vazio)', function () {
    $padrao = espiaoManipulador();

    $roteador = new RoteadorDeComandos(new ClassificadorDeComando, $padrao, []);

    $roteador->autenticado(User::factory()->make(['id' => 1]), updateComTexto(null));

    expect($padrao->chamadas)->toBe(1)
        ->and($padrao->comando->comando)->toBe(Comando::DESCONHECIDO)
        ->and($padrao->comando->argumentos)->toBe('');
});

it('naoVinculado é no-op nesta etapa (vínculo via bot é frontend posterior)', function () {
    $padrao = espiaoManipulador();

    $roteador = new RoteadorDeComandos(new ClassificadorDeComando, $padrao, []);

    $roteador->naoVinculado(424242, updateComTexto('/registrar x'));

    expect($padrao->chamadas)->toBe(0);
});
