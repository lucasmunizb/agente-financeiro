<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

/**
 * Resultado da classificação de uma mensagem (doc 06 §2). Carrega a intenção, os
 * argumentos brutos (texto após o comando, ainda não interpretados) e o texto
 * original íntegro — preservado para o interpretador de IA quando a intenção é
 * DESCONHECIDO. Nada aqui é "extraído": valores e datas são responsabilidade do
 * Bloco 4 (IA) / domínio determinístico, nunca deste roteador.
 */
final readonly class ComandoRecebido
{
    public function __construct(
        public Comando $comando,
        public string $argumentos,
        public string $textoOriginal,
    ) {}
}
