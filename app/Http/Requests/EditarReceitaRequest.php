<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validação da edição de receita (§7.10). Mesmas regras do cadastro ({@see ReceitaRequest}),
 * mas com um error bag próprio (`editarReceita`) para a tela reabrir a linha certa da lista
 * (não o formulário de "Adicionar") quando a validação falha. Edição é direta (sem o 2º passo):
 * o formulário já vem preenchido com os valores atuais — a revisão é inerente.
 */
class EditarReceitaRequest extends ReceitaRequest
{
    protected $errorBag = 'editarReceita';
}
