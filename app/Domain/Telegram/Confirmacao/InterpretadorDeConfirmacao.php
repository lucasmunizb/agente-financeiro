<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Confirmacao;

use Illuminate\Support\Str;

/**
 * Interpreta DETERMINISTICAMENTE (não-IA, regra 4) a resposta do usuário a uma confirmação
 * pendente (spec 04b §6). Reconhece um conjunto pequeno e explícito de "sim"/"não" após
 * normalizar (trim, minúsculas, sem acento/pontuação); qualquer outra coisa é INDEFINIDO —
 * fallback seguro que jamais grava por engano (barreira 1, regra 7).
 */
final class InterpretadorDeConfirmacao
{
    /** @var list<string> */
    private const SIM = ['sim', 's', 'confirmo', 'confirma', 'confirmar', 'ok', 'isso', 'pode', 'claro', 'positivo'];

    /** @var list<string> */
    private const NAO = ['nao', 'n', 'cancela', 'cancelar', 'negativo', 'nops', 'nunca'];

    public function interpretar(string $texto): RespostaDeConfirmacao
    {
        $normalizado = $this->normalizar($texto);

        if (in_array($normalizado, self::SIM, true)) {
            return RespostaDeConfirmacao::SIM;
        }

        if (in_array($normalizado, self::NAO, true)) {
            return RespostaDeConfirmacao::NAO;
        }

        return RespostaDeConfirmacao::INDEFINIDO;
    }

    private function normalizar(string $texto): string
    {
        $semAcento = Str::ascii($texto);

        return trim(mb_strtolower(preg_replace('/[^\p{L}\s]/u', '', $semAcento) ?? ''));
    }
}
