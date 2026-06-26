<?php

declare(strict_types=1);

namespace App\Domain\IA;

use App\Domain\Gasto\RegistrarGastoManual;
use Carbon\CarbonImmutable;

/**
 * Orquestra a confirmação de um gasto interpretado pela IA (Bloco 4 item 3). Normaliza a
 * extração crua de forma determinística e, se tudo resolver, gera a PRÉVIA via
 * {@see RegistrarGastoManual::preview()} — sem persistir (regra inviolável 7) — carregando
 * o `DadosGastoManual` para a persistência posterior (o "sim" reusa o `confirmar()`).
 * Quando algo não resolve, devolve apenas os esclarecimentos. A IA não calcula nada aqui.
 */
final class PrepararConfirmacaoDeGasto
{
    public function __construct(
        private readonly NormalizadorDeGastoExtraido $normalizador,
        private readonly RegistrarGastoManual $registrar,
    ) {}

    public function preparar(GastoExtraido $extraido, int $userId, CarbonImmutable $agora): ConfirmacaoDeGasto
    {
        $resultado = $this->normalizador->normalizar($extraido, $userId, $agora);

        if ($resultado->precisaEsclarecer()) {
            return new ConfirmacaoDeGasto(null, null, $resultado->esclarecimentos);
        }

        $previa = $this->registrar->preview($resultado->dados, $agora);

        return new ConfirmacaoDeGasto($previa, $resultado->dados, []);
    }
}
