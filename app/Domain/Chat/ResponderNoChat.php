<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Domain\Interacao\ProcessarInteracao;
use App\Domain\Telegram\Comando;
use App\Domain\Telegram\ComandoRecebido;
use App\Models\ChatMessage;
use App\Models\User;

/**
 * Chat financeiro na web (spec FE §7.14). Reutiliza o MESMO motor do Telegram: delega a
 * {@see ProcessarInteracao} (confirmação pendente → classificação → registro/consulta) e
 * redige a resposta com {@see RedatorDoChat}. Assim o chat web tem as mesmas funcionalidades
 * do bot — registrar um gasto (com confirmação "sim/não" antes de gravar, regra 7) e
 * consultar (guard barreira 4 + fontes barreira 5) — sem duplicar regra. Persiste o
 * histórico real ({@see ChatMessage}), isolado por usuário. A IA nunca calcula dinheiro
 * (regra 4).
 *
 * Cada mensagem é atendida de forma independente (sem memória de conversa livre), igual ao
 * bot; a confirmação de gasto, porém, é stateful entre mensagens (pendente em banco), então
 * "gastei 50 no mercado" → "sim" grava em duas mensagens, como no Telegram.
 */
final class ResponderNoChat
{
    /**
     * Aviso honesto ao anexar um PDF: a leitura automática de faturas (spec 07) ainda não
     * existe; o arquivo é descartado na hora (regra 6). Sem nenhum número (nada foi lido).
     */
    public const RESPOSTA_ANEXO = 'Recebi sua fatura em PDF. A leitura automática de faturas ainda não está disponível — por enquanto, registre os gastos manualmente. O arquivo foi processado e descartado; nada dele ficou armazenado.';

    public function __construct(
        private readonly ProcessarInteracao $orquestrar,
        private readonly RedatorDoChat $redator,
    ) {}

    /**
     * Grava a mensagem do usuário, roda a orquestração (mesmo motor do bot) e grava a
     * resposta redigida (com fontes/veredito quando for consulta). Devolve a mensagem do
     * assistente. Texto livre: sem slash, o roteamento é o de uma mensagem DESCONHECIDA.
     */
    public function perguntar(User $user, string $texto): ChatMessage
    {
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $texto,
        ]);

        $resultado = $this->orquestrar->processar(
            $user,
            new ComandoRecebido(Comando::DESCONHECIDO, '', $texto),
        );

        $resposta = $this->redator->redigir($resultado);

        return ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'body' => $resposta->texto,
            'aprovado' => $resposta->aprovado,
            'fontes' => $resposta->fontes,
        ]);
    }

    /**
     * Registra uma mensagem que trouxe um PDF de fatura. O arquivo NÃO chega aqui — foi
     * validado e descartado na borda (regra 6); só marcamos `tem_anexo`. A resposta é um
     * aviso fixo (sem IA, sem número), pois a extração (spec 07) ainda não existe.
     */
    public function anexarFatura(User $user, ?string $texto): ChatMessage
    {
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $texto ?? '',
            'tem_anexo' => true,
        ]);

        return ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'body' => self::RESPOSTA_ANEXO,
            'aprovado' => true,
            'fontes' => [],
        ]);
    }
}
