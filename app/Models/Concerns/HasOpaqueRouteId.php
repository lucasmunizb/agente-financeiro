<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Shared\OpaqueId;

/**
 * Faz o id do model NUNCA sair em claro numa URL (requisito inegociável — ver README
 * §"Identificadores nas URLs"). Aplique a todo model cujo id apareceria num parâmetro:
 *
 *  - {@see getRouteKey()} garante que `route('...', $model)` gere o token CRIPTOGRAFADO
 *    em vez do id numérico (usado nos links de recurso, ex.: detalhe do lançamento);
 *  - `opaqueId()` devolve o mesmo token para usos fora de rota — ex.: o `value` de um
 *    `<option>` de filtro na query string.
 *
 * A decodificação de volta acontece na borda (Route::bind para o path; o controller para
 * os filtros), via {@see OpaqueId::decode()}.
 */
trait HasOpaqueRouteId
{
    /** URL de recurso: `route('...', $model)` passa a emitir o token opaco, não o id. */
    public function getRouteKey(): string
    {
        return OpaqueId::encode((int) $this->getKey());
    }

    /** Token opaco para usos fora de rota (ex.: value de filtro na query). */
    public function opaqueId(): string
    {
        return OpaqueId::encode((int) $this->getKey());
    }
}
