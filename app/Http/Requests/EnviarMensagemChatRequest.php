<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da borda do chat financeiro (spec FE §7.14). Exige mensagem OU anexo.
 *
 * O anexo é validado por MIME REAL — `mimetypes:application/pdf` sniffa o conteúdo do
 * arquivo (não confia na extensão nem no MIME do cliente): um texto renomeado para `.pdf`
 * é recusado (defesa da skill seguranca-ia). O arquivo é efêmero: aqui apenas validamos;
 * nada dele é persistido (regra 6/lgpd).
 */
class EnviarMensagemChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A rota já está sob o middleware 'auth'; o escopo por usuário vem do request->user().
        return true;
    }

    public function rules(): array
    {
        return [
            'mensagem' => ['nullable', 'string', 'max:2000', 'required_without:anexo'],
            'anexo' => ['nullable', 'file', 'mimetypes:application/pdf', 'mimes:pdf', 'max:10240', 'required_without:mensagem'],
        ];
    }

    public function messages(): array
    {
        return [
            'anexo.mimetypes' => 'Aceito apenas PDF.',
            'anexo.mimes' => 'Aceito apenas PDF.',
            'anexo.file' => 'Aceito apenas PDF.',
            'mensagem.required_without' => 'Escreva uma pergunta ou anexe um PDF.',
            'anexo.required_without' => 'Escreva uma pergunta ou anexe um PDF.',
            'anexo.max' => 'O PDF é grande demais (máximo 10 MB).',
        ];
    }
}
