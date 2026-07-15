<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do perfil (spec FE §7.17). Nome/e-mail obrigatórios; o e-mail é único ignorando a
 * própria conta. O fuso é validado contra os identificadores válidos (regra `timezone`) — é
 * preferência do usuário; o motor financeiro segue America/Sao_Paulo (regra 5). Error bag próprio
 * para a tela reabrir a seção certa.
 */
class AtualizarPerfilRequest extends FormRequest
{
    protected $errorBag = 'perfil';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            // Trocar o e-mail (identificador de login) exige reautenticação: sem a senha
            // atual, uma sessão sequestrada faria account takeover (pentest 2026-07 L3).
            // Só é obrigatória quando o e-mail muda; edições de nome/fuso não pedem senha.
            'senha_atual' => ['nullable', Rule::requiredIf(fn (): bool => $this->emailMudou()), 'current_password'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    /** O e-mail enviado difere do atual do usuário (comparação normalizada). */
    private function emailMudou(): bool
    {
        $novo = mb_strtolower(trim((string) $this->input('email')));

        return $novo !== mb_strtolower((string) $this->user()->email);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Este e-mail já está em uso.',
            'senha_atual.required' => 'Confirme sua senha atual para trocar o e-mail.',
            'senha_atual.current_password' => 'A senha atual está incorreta.',
            'timezone.timezone' => 'Selecione um fuso horário válido.',
        ];
    }
}
