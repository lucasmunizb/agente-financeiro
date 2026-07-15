<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Escapa os curingas de LIKE/ILIKE (auditoria P3-3): texto vindo do usuário ou da IA
 * ("%" / "_" / "\") não pode virar padrão — um cartão consultado como "%" casaria com
 * qualquer descrição e a resposta citaria a entidade errada (sem vazar terceiros, o
 * escopo por user_id permanece; ainda assim é resposta errada).
 */
final class SqlLike
{
    public static function escapar(string $texto): string
    {
        return addcslashes($texto, '\\%_');
    }
}
