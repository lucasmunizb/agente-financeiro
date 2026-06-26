<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

/**
 * Classificador determinístico de comandos (doc 06 §2). Mapeia o texto da mensagem
 * para uma intenção a partir da primeira palavra: slash-command (/registrar, com ou
 * sem sufixo @bot) ou verbo imperativo (registrar, cancela, ...). Casa pela palavra
 * inteira — nunca por prefixo — e é insensível a maiúsculas. O que não casa vira
 * DESCONHECIDO, preservando o texto íntegro para o interpretador de IA (Bloco 4).
 *
 * Esta classe NÃO interpreta linguagem natural nem extrai valores/datas: só separa a
 * intenção dos argumentos brutos. Sem dependência de banco ou framework.
 */
final class ClassificadorDeComando
{
    /** Primeira palavra (slash ou verbo) → intenção. */
    private const PALAVRAS = [
        'registrar' => Comando::REGISTRAR,
        'registra' => Comando::REGISTRAR,
        'editar' => Comando::EDITAR,
        'edita' => Comando::EDITAR,
        'cancelar' => Comando::CANCELAR,
        'cancela' => Comando::CANCELAR,
        'buscar' => Comando::BUSCAR,
        'busca' => Comando::BUSCAR,
        'ajuda' => Comando::AJUDA,
        'start' => Comando::AJUDA,
        'help' => Comando::AJUDA,
    ];

    public function classificar(string $texto): ComandoRecebido
    {
        $aparado = trim($texto);

        if ($aparado === '') {
            return new ComandoRecebido(Comando::DESCONHECIDO, '', $texto);
        }

        // Separa a primeira palavra do restante (argumentos brutos).
        $partes = preg_split('/\s+/', $aparado, 2);
        $primeira = $partes[0];
        $argumentos = isset($partes[1]) ? trim($partes[1]) : '';

        $chave = $this->normalizar($primeira);
        $comando = self::PALAVRAS[$chave] ?? null;

        if ($comando === null) {
            // Texto livre: a IA recebe a mensagem íntegra (aparada) como argumento.
            return new ComandoRecebido(Comando::DESCONHECIDO, $aparado, $texto);
        }

        return new ComandoRecebido($comando, $argumentos, $texto);
    }

    /** Minúsculas + remoção do prefixo "/" e do sufixo "@bot" dos slash-commands. */
    private function normalizar(string $palavra): string
    {
        $palavra = mb_strtolower($palavra);

        if (str_starts_with($palavra, '/')) {
            $palavra = substr($palavra, 1);

            // "/cancelar@MeuFinBot" → "cancelar"
            if (($pos = strpos($palavra, '@')) !== false) {
                $palavra = substr($palavra, 0, $pos);
            }
        }

        return $palavra;
    }
}
