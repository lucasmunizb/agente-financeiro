<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Telegram\Saida\ClienteTelegram;
use Illuminate\Database\QueryException;

/**
 * Fluxo de vínculo via bot (doc 06 §1, passos 2–4 · frontend regra 3). Mensagens
 * de um telegram_user_id SEM vínculo ativo caem aqui: adaptador entre o webhook e
 * o domínio {@see VincularTelegram}. Em dois passos, pois o Telegram entrega o
 * token e o telefone em mensagens distintas:
 *
 *  1. texto `/start <token>` (ou o token cru) → valida e captura o telegram_user_id,
 *     então pede o telefone pelo teclado nativo request_contact;
 *  2. `message.contact` (telefone compartilhado) → confirma o vínculo.
 *
 * Não calcula nada nem interpreta linguagem natural: só orquestra e responde curto.
 */
final class FluxoDeVinculo
{
    /** Formato do token de vínculo (Str::random(32), alfanumérico). */
    private const TOKEN = '/^[A-Za-z0-9]{32}$/';

    private const PEDIR_CONTATO = 'Tudo certo! Agora toque em "Compartilhar contato" para eu confirmar o seu número.';

    private const CONCLUIDO = 'Pronto, conta conectada. Já pode registrar e consultar seus gastos por aqui.';

    private const CODIGO_INVALIDO = 'Esse código é inválido ou expirou. Gere um novo na tela de vínculo do app e me envie de novo.';

    private const CONTATO_ALHEIO = 'Toque em "Compartilhar contato" para enviar o seu próprio número — não dá para usar o contato de outra pessoa.';

    private const JA_VINCULADO = 'Este Telegram já está vinculado a outra conta. Desconecte por lá antes de vincular aqui.';

    private const COMO_CONECTAR = 'Para conectar sua conta, gere um código de uso único na tela de vínculo do app e me envie aqui.';

    private const ROTULO_BOTAO = 'Compartilhar contato';

    public function __construct(
        private readonly VincularTelegram $vincular,
        private readonly ClienteTelegram $cliente,
    ) {}

    /** @param  array<string, mixed>  $update */
    public function tratar(int $telegramUserId, array $update): void
    {
        $mensagem = $update['message'] ?? [];
        $chatId = (int) ($mensagem['chat']['id'] ?? $telegramUserId);

        if (isset($mensagem['contact'])) {
            $this->receberContato($telegramUserId, $chatId, (array) $mensagem['contact']);

            return;
        }

        $token = $this->extrairToken((string) ($mensagem['text'] ?? ''));

        if ($token === null) {
            $this->cliente->enviarMensagem($chatId, self::COMO_CONECTAR);

            return;
        }

        try {
            $this->vincular->iniciar($token, $telegramUserId);
        } catch (TokenInvalidoException) {
            $this->cliente->enviarMensagem($chatId, self::CODIGO_INVALIDO);

            return;
        }

        $this->cliente->pedirContato($chatId, self::PEDIR_CONTATO, self::ROTULO_BOTAO);
    }

    /** @param  array<string, mixed>  $contato */
    private function receberContato(int $telegramUserId, int $chatId, array $contato): void
    {
        // Só o próprio contato vale (request_contact pode carregar contato alheio).
        if ((int) ($contato['user_id'] ?? 0) !== $telegramUserId) {
            $this->cliente->enviarMensagem($chatId, self::CONTATO_ALHEIO);

            return;
        }

        try {
            $this->vincular->finalizar($telegramUserId, (string) ($contato['phone_number'] ?? ''));
        } catch (TokenInvalidoException) {
            $this->cliente->enviarMensagem($chatId, self::CODIGO_INVALIDO, removerTeclado: true);

            return;
        } catch (QueryException) {
            // Índice parcial: um telegram_user_id ativo por vez.
            $this->cliente->enviarMensagem($chatId, self::JA_VINCULADO, removerTeclado: true);

            return;
        }

        $this->cliente->enviarMensagem($chatId, self::CONCLUIDO, removerTeclado: true);
    }

    /** Extrai o token do `/start <token>` ou de um token cru; null se não parecer um. */
    private function extrairToken(string $texto): ?string
    {
        $texto = trim($texto);

        if (str_starts_with($texto, '/start')) {
            $texto = trim(substr($texto, strlen('/start')));
        }

        return preg_match(self::TOKEN, $texto) === 1 ? $texto : null;
    }
}
