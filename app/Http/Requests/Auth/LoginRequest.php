<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validação e autenticação do login (borda web).
 *
 * Segurança (OWASP):
 * - Rate limit por (e-mail + IP) contra brute force / credential stuffing.
 * - Mensagem de falha genérica: nunca revela se o e-mail existe (evita
 *   enumeração de contas). Falha de credencial e falha de throttle usam a MESMA
 *   chave de erro ('email'), com textos que não distinguem o motivo real.
 */
class LoginRequest extends FormRequest
{
    /**
     * Máximo de tentativas antes do bloqueio temporário.
     */
    private const MAX_TENTATIVAS = 5;

    /**
     * Janela de bloqueio, em segundos.
     */
    private const BLOQUEIO_SEGUNDOS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Tenta autenticar. Falha closed: qualquer erro (throttle ou credencial)
     * interrompe com ValidationException e o usuário permanece guest.
     */
    public function authenticate(): void
    {
        $this->garantirNaoLimitado();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->chaveThrottle(), self::BLOQUEIO_SEGUNDOS);

            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha incorretos.',
            ]);
        }

        RateLimiter::clear($this->chaveThrottle());
    }

    /**
     * Barra a tentativa se o limite foi atingido. Mensagem genérica na chave
     * 'email' — não confirma existência da conta.
     */
    private function garantirNaoLimitado(): void
    {
        if (! RateLimiter::tooManyAttempts($this->chaveThrottle(), self::MAX_TENTATIVAS)) {
            return;
        }

        Event::dispatch(new Lockout($this));

        $segundos = RateLimiter::availableIn($this->chaveThrottle());

        throw ValidationException::withMessages([
            'email' => "Muitas tentativas. Tente novamente em {$segundos} segundos.",
        ]);
    }

    /**
     * Chave do rate limit: e-mail normalizado + IP.
     */
    private function chaveThrottle(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }
}
