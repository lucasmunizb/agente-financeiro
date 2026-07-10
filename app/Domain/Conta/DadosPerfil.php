<?php

declare(strict_types=1);

namespace App\Domain\Conta;

/**
 * Dados de perfil já validados na borda (spec FE §7.17). O fuso é preferência do usuário
 * (default America/Sao_Paulo, regra 5); o motor financeiro segue SP.
 */
final readonly class DadosPerfil
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public string $timezone,
    ) {}
}
