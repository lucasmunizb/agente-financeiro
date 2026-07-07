<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Models\ChatMessage;

/**
 * Texto já redigido de uma resposta do chat web, pronto para virar uma {@see ChatMessage}
 * do assistente. `aprovado` e `fontes` só existem quando a resposta veio de uma consulta
 * (barreiras 4 e 5); no registro/confirmação são nulos/vazios.
 */
final readonly class RespostaDoChat
{
    /**
     * @param  list<array<string, mixed>>  $fontes
     */
    public function __construct(
        public string $texto,
        public ?bool $aprovado,
        public array $fontes,
    ) {}
}
