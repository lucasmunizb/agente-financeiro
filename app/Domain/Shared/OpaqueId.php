<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Identificador OPACO para a borda web.
 *
 * REQUISITO INEGOCIÁVEL: nenhum id real de recurso sai numa URL. Todo id que apareceria
 * num parâmetro — path de rota (`/lancamentos/{id}`) ou valor de filtro na query
 * (`?categoria={id}`) — é CRIPTOGRAFADO com a APP_KEY (via {@see Crypt}) e só existe em
 * claro dentro do servidor. Ver README §"Identificadores nas URLs".
 *
 * - {@see encode()} transforma um id inteiro no token opaco (base64 URL-safe).
 * - {@see decode()} desfaz; devolve `null` para qualquer entrada inválida — inclusive um
 *   id em claro (`"123"`), garantindo que valor real na URL NÃO é aceito.
 *
 * A cifra usa IV aleatório (não-determinística): o mesmo id gera tokens diferentes a cada
 * chamada — não há como enumerar/correlacionar recursos pela URL. Não há TTL: um token
 * continua válido (o link pode ser reaberto/favoritado).
 */
final class OpaqueId
{
    /** Codifica um id inteiro num token opaco, seguro para path e query string. */
    public static function encode(int $id): string
    {
        // Crypt::encryptString devolve base64 padrão (JSON iv/value/mac). Deixamos URL-safe:
        // +/ → -_ e removemos o padding '=' (restaurado no decode).
        return rtrim(strtr(Crypt::encryptString((string) $id), '+/', '-_'), '=');
    }

    /**
     * Decodifica um token de volta no id inteiro. Devolve null quando a entrada não é um
     * token legítimo desta aplicação — token forjado, vazio/ausente ou um id em claro.
     */
    public static function decode(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        $base64 = strtr($token, '-_', '+/');
        if (($resto = strlen($base64) % 4) !== 0) {
            $base64 .= str_repeat('=', 4 - $resto);
        }

        try {
            $plano = Crypt::decryptString($base64);
        } catch (DecryptException) {
            return null;
        }

        return ctype_digit($plano) ? (int) $plano : null;
    }
}
