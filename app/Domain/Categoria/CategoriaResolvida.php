<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

/**
 * Resultado da resolução de categoria de um gasto ({@see ResolvedorDeCategoria}).
 *
 * `categoriaId` é a categoria escolhida (ou null se nenhuma). `sugeridaPorIa` marca a
 * PROCEDÊNCIA: true quando veio do fallback de IA (pré-seleção a confirmar), false quando veio
 * do lookup determinístico (regra aprendida) ou quando não há categoria. Só sinaliza
 * procedência para a apresentação/telemetria — nunca altera cálculo.
 */
final class CategoriaResolvida
{
    public function __construct(
        public readonly ?int $categoriaId,
        public readonly bool $sugeridaPorIa,
    ) {}
}
