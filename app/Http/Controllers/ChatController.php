<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Chat\ResponderNoChat;
use App\Http\Requests\EnviarMensagemChatRequest;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;

/**
 * Borda web do chat financeiro (spec FE §7.14). Camada fina: valida (Form Request), delega
 * ao domínio ({@see ResponderNoChat}, que reusa o motor do Telegram) e devolve JSON. Não há
 * cálculo aqui (regra 4). O escopo é sempre o usuário autenticado (nenhuma identidade vem
 * do cliente — seguranca-ia). O anexo é efêmero: validado e descartado, nunca persistido
 * (regra 6).
 */
class ChatController extends Controller
{
    /** Histórico do próprio usuário, em ordem cronológica (barreira: escopo estrito). */
    public function index(): JsonResponse
    {
        $mensagens = ChatMessage::query()
            ->where('user_id', auth()->id())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $m): array => $this->paraJson($m));

        return response()->json(['mensagens' => $mensagens]);
    }

    public function store(EnviarMensagemChatRequest $request, ResponderNoChat $chat): JsonResponse
    {
        $user = $request->user();

        // PDF válido → caminho do anexo (sem IA, sem persistir arquivo); senão, consulta o motor.
        $assistente = $request->hasFile('anexo')
            ? $chat->anexarFatura($user, $request->input('mensagem'))
            : $chat->perguntar($user, (string) $request->input('mensagem'));

        return response()->json([
            'ok' => true,
            'mensagem' => $this->paraJson($assistente),
        ]);
    }

    /**
     * Serializa uma mensagem para a tela. Fontes e veredito só existem em respostas da IA.
     *
     * @return array<string, mixed>
     */
    private function paraJson(ChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'body' => $m->body,
            'aprovado' => $m->aprovado,
            'temAnexo' => $m->tem_anexo,
            'fontes' => $m->fontes ?? [],
            'hora' => $m->created_at?->timezone('America/Sao_Paulo')->format('H:i'),
        ];
    }
}
