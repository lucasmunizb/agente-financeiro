<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Telegram\GerarTokenDeVinculo;
use App\Models\TelegramLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela de vínculo com o Telegram (doc 06 §1). Fia a apresentação (regra 3) ao
 * domínio já testado: gera o token de uso único (só o hash persiste), mostra o
 * código enquanto válido, permite gerar outro e desconectar. O consumo do token
 * e a ativação acontecem pelo bot (FluxoDeVinculo); aqui é só a borda web.
 */
class TelegramLinkController extends Controller
{
    public function show(Request $request, GerarTokenDeVinculo $gerar): View
    {
        $user = $request->user();

        $ativo = TelegramLink::where('user_id', $user->id)
            ->where('status', TelegramLink::ATIVO)
            ->first();

        if ($ativo !== null) {
            return view('telegram', [
                'estado' => 'vinculado',
                'handle' => $this->mascararTelefone($ativo->telefone),
            ]);
        }

        // Sem vínculo: reaproveita o token da sessão se a pendência ainda vale;
        // senão emite um novo (o token em claro só existe aqui e na sessão).
        [$token, $expiraEm] = $this->tokenVigente($user->id, $gerar);

        $botUsername = (string) config('services.telegram.bot_username', 'AgenteFinanceiroBot');
        $segundos = max(0, (int) CarbonImmutable::now(RelativeDate::TIMEZONE)->diffInSeconds($expiraEm, false));

        return view('telegram', [
            'estado' => 'pendente',
            'token' => $token,
            'botUsername' => $botUsername,
            'deepLink' => "https://t.me/{$botUsername}?start={$token}",
            'expiraEmSegundos' => $segundos,
        ]);
    }

    /**
     * Status do vínculo para o polling da tela (JSON). A tela pendente consulta
     * este endpoint enquanto aguarda o usuário confirmar o contato no bot; quando
     * vira ativo, o frontend recarrega e mostra o estado vinculado. Escopo do
     * usuário autenticado — não vaza vínculo de outra conta.
     */
    public function status(Request $request): JsonResponse
    {
        $vinculado = TelegramLink::where('user_id', $request->user()->id)
            ->where('status', TelegramLink::ATIVO)
            ->exists();

        return response()->json(['vinculado' => $vinculado]);
    }

    public function gerar(Request $request, GerarTokenDeVinculo $gerar): RedirectResponse
    {
        $novo = $gerar->para($request->user()->id);
        $this->lembrarToken($novo->token, $novo->expiraEm);

        return redirect()->route('telegram');
    }

    public function desconectar(Request $request): RedirectResponse
    {
        // Revogado não tem finalidade para reter o telefone (LGPD — minimização, P2-10).
        TelegramLink::where('user_id', $request->user()->id)
            ->where('status', TelegramLink::ATIVO)
            ->update(['status' => TelegramLink::REVOGADO, 'telefone' => null]);

        return redirect()->route('telegram');
    }

    /**
     * Token vigente para exibir: reaproveita o da sessão se ainda houver a pendência
     * correspondente (hash bate, não expirou); caso contrário, gera um novo.
     *
     * @return array{0: string, 1: CarbonImmutable}
     */
    private function tokenVigente(int $userId, GerarTokenDeVinculo $gerar): array
    {
        $token = session('telegram.token');
        $expiraEm = session('telegram.token_expira');

        if (is_string($token) && $expiraEm !== null) {
            $expiraEm = CarbonImmutable::parse($expiraEm);

            $valido = TelegramLink::where('user_id', $userId)
                ->where('status', TelegramLink::PENDENTE)
                ->where('token_hash', hash('sha256', $token))
                // timestamptz: comparar em UTC — o binding serializa a hora local sem offset.
                ->where('token_expira_em', '>', CarbonImmutable::now('UTC'))
                ->exists();

            if ($valido) {
                return [$token, $expiraEm];
            }
        }

        $novo = $gerar->para($userId);
        $this->lembrarToken($novo->token, $novo->expiraEm);

        return [$novo->token, $novo->expiraEm];
    }

    private function lembrarToken(string $token, CarbonImmutable $expiraEm): void
    {
        session([
            'telegram.token' => $token,
            'telegram.token_expira' => $expiraEm->toIso8601String(),
        ]);
    }

    /** Mostra só os últimos 4 dígitos do telefone verificado (LGPD — minimização na borda). */
    private function mascararTelefone(?string $telefone): string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone) ?? '';

        return $digitos === '' ? 'Conta conectada' : '•••• '.substr($digitos, -4);
    }
}
