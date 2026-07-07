<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Domain\IA\Consulta\ResponderConsulta;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Models\ChatMessage;
use App\Models\User;

/**
 * Chat financeiro na web (spec FE §7.14). Reutiliza o MESMO motor do Telegram
 * ({@see ResponderConsulta}: agente + tools escopadas por usuário + guard barreira 4 +
 * fontes barreira 5) e persiste o histórico real ({@see ChatMessage}), sempre isolado por
 * usuário. A IA nunca calcula dinheiro (regra 4): os valores da resposta vêm do domínio, já
 * validados pelo guard antes de gravar.
 *
 * Cada pergunta é atendida de forma independente (sem memória de conversa), igual ao bot
 * hoje — dar contexto de turnos anteriores ao agente é melhoria futura.
 */
final class ResponderNoChat
{
    /**
     * Aviso honesto ao anexar um PDF: a leitura automática de faturas (spec 07) ainda não
     * existe; o arquivo é descartado na hora (regra 6). Sem nenhum número (nada foi lido).
     */
    public const RESPOSTA_ANEXO = 'Recebi sua fatura em PDF. A leitura automática de faturas ainda não está disponível — por enquanto, registre os gastos manualmente. O arquivo foi processado e descartado; nada dele ficou armazenado.';

    public function __construct(
        private readonly ResponderConsulta $responder,
    ) {}

    /**
     * Grava a pergunta do usuário, consulta o motor e grava a resposta (com fontes e o
     * veredito do guard). Devolve a mensagem do assistente.
     */
    public function perguntar(User $user, string $pergunta): ChatMessage
    {
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $pergunta,
        ]);

        $resposta = $this->responder->responder($user, $pergunta);

        return ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'body' => $resposta->texto,
            'aprovado' => $resposta->aprovado,
            'fontes' => array_map(
                static fn (TraceDaConsulta $t): array => [
                    'ferramenta' => $t->ferramenta,
                    'filtros' => $t->filtros,
                    'registros' => $t->registros,
                    'resumo' => $t->resumo(),
                ],
                $resposta->fontes,
            ),
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
